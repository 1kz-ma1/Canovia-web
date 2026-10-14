<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Enums\WorkspaceMode;
use App\Exceptions\NativeAiExecutionException;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * G3-R1: an Inbox suggestion is a private AI derivative, not a Plan/Task
 * mutation. Revalidate source, actor and purpose at its durable write.
 */
final class InboxAiSuggestionCommitFence
{
    public function __construct(
        private readonly NativeAiPurposeAuthorizationGuard $purposeGuard,
        private readonly BehaviorIdentityService $identity,
        private readonly CompanionMembershipCommitGuard $membershipCommit,
    ) {}

    /**
     * Sign the exact submitted material, including private attachment bytes,
     * ownership and the pre-existing routing state. No raw content is logged.
     */
    public function signature(
        InboxItem $item,
        ?int $userId = null,
        ?string $providerInputHash = null,
    ): string
    {
        try {
            // Bind a linked Plan to ORIGINAL editor admission, not just the
            // role that happens to be current after the provider call.
            // Planless Inbox items retain their existing actor/source fence.
            $authorization = null;
            if ($item->plan_id !== null) {
                $actor = $userId !== null && $userId > 0
                    ? User::query()->find($userId)
                    : null;
                $plan = Plan::query()->find($item->plan_id);
                if ($actor === null || $plan === null) {
                    $this->deny('native_ai_target_changed', null);
                }

                $authorization = $this->membershipCommit->capture($actor, $plan);
                if (
                    ! in_array($authorization['scope'] ?? null, ['owner', 'member'], true)
                    || (($authorization['scope'] ?? null) === 'member'
                        && ($authorization['role'] ?? null) !== PlanMember::ROLE_EDITOR)
                ) {
                    $this->deny('native_ai_target_changed', null);
                }
            }

            $fileHash = null;
            if (in_array($item->source_type, ['image', 'pdf'], true)) {
                if (! is_string($item->storage_path)
                    || $item->storage_path === ''
                    || ! Storage::exists($item->storage_path)) {
                    $this->deny('native_ai_source_changed', null);
                }

                $fileHash = $providerInputHash
                    ?? hash('sha256', Storage::get($item->storage_path));
            }

            return hash('sha256', json_encode([
                'id' => (int) $item->id,
                'user_id' => $item->user_id,
                'actor_token' => $item->actor_token,
                'plan_id' => $item->plan_id,
                'authorization' => $authorization,
                'source_type' => $item->source_type,
                'status' => $item->status,
                'title' => $item->title,
                'content' => $item->content,
                'source_url' => $item->source_url,
                'storage_path' => $item->storage_path,
                'mime_type' => $item->mime_type,
                'original_name' => $item->original_name,
                'byte_size' => $item->byte_size,
                'metadata' => $item->metadata,
                'processed_at' => $item->processed_at?->toIso8601String(),
                'file_sha256' => $fileHash,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (NativeAiExecutionException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->deny('native_ai_source_changed', null);
        }
    }

    /**
     * R41 canonical source lock order for a Plan-linked Inbox item:
     * Plan -> Inbox, followed by membership. Recall transfer already
     * locks Plan -> Task -> Inbox; taking Inbox -> Plan in R39 inverted
     * that order and could deadlock with a concurrent transfer.
     *
     * Use the original route-loaded Plan ID ONLY to choose which parent
     * row to lock first. The live Inbox row is then locked and its current
     * plan_id MUST still match; never silently lock a newly retargeted Plan
     * out of order. Unlinked Inbox items lock only their own row.
     *
     * @return array{0: ?Plan, 1: InboxItem, 2: ?int}
     */
    private function lockSourceGraph(InboxItem $item): array
    {
        $originalPlanId = $item->plan_id === null ? null : (int) $item->plan_id;
        $plan = $originalPlanId === null ? null
            : Plan::query()->whereKey($originalPlanId)->lockForUpdate()->first();

        $live = InboxItem::query()->whereKey($item->id)->lockForUpdate()->first();
        $livePlanId = $live?->plan_id === null ? null : (int) $live->plan_id;

        if ($live === null || $livePlanId !== $originalPlanId
            || ($originalPlanId !== null && $plan === null)) {
            $this->deny('native_ai_target_changed', null);
        }

        return [$plan, $live, $originalPlanId];
    }

    /**
     * R38-R39 pre-provider seal: compare the hash of bytes actually encoded into
     * the AI request with the ORIGINAL source/authorization signature.
     * A->B input mismatches fail before submission, even if another writer
     * later restores A. This is a content integrity check, NOT immutable
     * provider-generation attestation or protection against A->B->A entirely
     * between the first and final reads. R39 locks live owner/Plan rows and
     * reauthorizes original source state before any remote provider call.
     */
    public function assertInputMatches(
        InboxItem $item,
        Request $request,
        string $expectedSignature,
        ?string $providerInputHash,
    ): void {
        // The byte seal must be checked against CURRENT database ownership,
        // not a stale route-loaded model. This transaction closes before
        // calling the remote AI: never hold SQL locks over provider latency.
        $userId = $request->user()?->id;
        DB::transaction(function () use (
            $item, $request, $userId, $expectedSignature, $providerInputHash,
        ): void {
            [$plan, $live, $planId] = $this->lockSourceGraph($item);
            if (! in_array($live->status, ['new', 'review'], true)) {
                $this->deny('native_ai_target_changed', null);
            }

            if ($userId === null || $userId <= 0) {
                $this->deny('native_ai_actor_unverified', null);
            }
            if ($live->user_id !== null) {
                if ((int) $live->user_id !== (int) $userId) {
                    $this->deny('native_ai_target_changed', null);
                }
            } else {
                $actorToken = $this->identity->resolve($request);
                if (! is_string($live->actor_token)
                    || ! hash_equals($live->actor_token, $actorToken)) {
                    $this->deny('native_ai_target_changed', null);
                }
            }

            if ($planId !== null) {
                $actor = User::query()->find($userId);
                if ($plan === null || $actor === null
                    || ! $this->membershipCommit->canAccess($actor, $plan, true)) {
                    $this->deny('native_ai_target_changed', null);
                }
            }

            $failure = $this->purposeGuard->failure(
                $userId, $planId, null, FeatureKey::AutomaticAiExecution,
            );
            if ($failure !== null) {
                $this->deny($failure, null);
            }

            $isFile = in_array($live->source_type, ['image', 'pdf'], true);
            if (($isFile && (! is_string($providerInputHash)
                    || ! preg_match('/^[a-f0-9]{64}$/', $providerInputHash)))
                || (! $isFile && $providerInputHash !== null)
                || ! hash_equals(
                    $expectedSignature,
                    $this->signature($live, $userId, $providerInputHash),
                )) {
                $this->deny('native_ai_source_changed', null);
            }
        }, 3);
    }

    /**
     * @param array<string,mixed> $suggestion
     * @return array<string,mixed> the suggestion actually persisted
     */
    public function commit(
        InboxItem $item,
        string $expectedSignature,
        array $suggestion,
        Request $request,
        ReleaseLevelService $releaseLevels,
    ): array {
        return DB::transaction(function () use (
            $item, $expectedSignature, $suggestion, $request, $releaseLevels,
        ): array {
            [$plan, $live, $planId] = $this->lockSourceGraph($item);
            $runId = (int) ($suggestion['run_id'] ?? 0);

            $userId = $request->user()?->id;
            if ($userId === null || $userId <= 0) {
                $this->deny('native_ai_actor_unverified', $runId);
            }

            if ($live->user_id !== null) {
                if ((int) $live->user_id !== (int) $userId) {
                    $this->deny('native_ai_target_changed', $runId);
                }
            } else {
                $actorToken = $this->identity->resolve($request);
                if (! is_string($live->actor_token)
                    || ! hash_equals($live->actor_token, $actorToken)) {
                    $this->deny('native_ai_target_changed', $runId);
                }
            }

            if (! in_array($live->status, ['new', 'review'], true)) {
                $this->deny('native_ai_target_changed', $runId);
            }

            if ($planId !== null) {
                // The Plan is locked; lock the actor's membership before
                // publishing. The signature below compares its generation
                // epoch and row identity to the pre-provider snapshot.
                $actor = User::query()->find((int) $userId);
                if ($actor === null
                    || ! $this->membershipCommit->canAccess($actor, $plan, true)) {
                    $this->deny('native_ai_target_changed', $runId);
                }
            }

            $failure = $this->purposeGuard->failure(
                $userId, $planId, null, FeatureKey::AutomaticAiExecution,
            );
            if ($failure !== null) {
                $this->deny($failure, $runId);
            }

            $currentSignature = $this->signature($live, (int) $userId);
            if (! hash_equals($expectedSignature, $currentSignature)) {
                $this->deny('native_ai_source_changed', $runId);
            }

            if (($suggestion['destination'] ?? null) === 'career_capture'
                && ! $releaseLevels->allowsWorkspace(
                    WorkspaceMode::Career, $request->user(), $request,
                )) {
                $suggestion['destination'] = 'keep_inbox';
                $suggestion['reason'] = 'Careerは現在Beta準備中のため、Inboxに保持します。';
            }

            $metadata = is_array($live->metadata) ? $live->metadata : [];
            $metadata['routing_suggestion'] = $suggestion;

            $live->update([
                'status' => 'review',
                'metadata' => $metadata,
            ]);

            return $suggestion;
        }, 3);
    }

    private function deny(string $code, ?int $runId): never
    {
        throw new NativeAiExecutionException(
            'Inboxの内容または権限がAI解析中に変わりました。内容を確認して再試行してください。',
            $code,
            $runId !== null && $runId > 0 ? $runId : null,
        );
    }
}
