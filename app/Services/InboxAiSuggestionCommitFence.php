<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Enums\WorkspaceMode;
use App\Exceptions\NativeAiExecutionException;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\PlanMember;
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
    ) {}

    /**
     * Sign the exact submitted material, including private attachment bytes,
     * ownership and the pre-existing routing state. No raw content is logged.
     */
    public function signature(InboxItem $item): string
    {
        try {
            $fileHash = null;
            if (in_array($item->source_type, ['image', 'pdf'], true)) {
                if (! is_string($item->storage_path)
                    || $item->storage_path === ''
                    || ! Storage::exists($item->storage_path)) {
                    $this->deny('native_ai_source_changed', null);
                }

                $fileHash = hash('sha256', Storage::get($item->storage_path));
            }

            return hash('sha256', json_encode([
                'id' => (int) $item->id,
                'user_id' => $item->user_id,
                'actor_token' => $item->actor_token,
                'plan_id' => $item->plan_id,
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
            $live = InboxItem::query()->whereKey($item->id)->lockForUpdate()->first();
            $runId = (int) ($suggestion['run_id'] ?? 0);

            if ($live === null) {
                $this->deny('native_ai_target_changed', $runId);
            }

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

            $planId = $live->plan_id === null ? null : (int) $live->plan_id;
            if ($planId !== null) {
                $plan = Plan::query()->whereKey($planId)->lockForUpdate()->first();
                if ($plan === null) {
                    $this->deny('native_ai_target_changed', $runId);
                }

                $canEdit = (int) $plan->user_id === (int) $userId
                    || ($plan->is_collaborative && PlanMember::query()
                        ->where('plan_id', $planId)
                        ->where('user_id', $userId)
                        ->where('role', PlanMember::ROLE_EDITOR)
                        ->exists());
                if (! $canEdit) {
                    $this->deny('native_ai_target_changed', $runId);
                }
            }

            $failure = $this->purposeGuard->failure(
                $userId, $planId, null, FeatureKey::AutomaticAiExecution,
            );
            if ($failure !== null) {
                $this->deny($failure, $runId);
            }

            $currentSignature = $this->signature($live);
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
