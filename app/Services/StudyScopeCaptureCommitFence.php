<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\StudyScopeCapture;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * G3: snapshot original source and reauthorize a Study Scope draft at its
 * durable write boundary. Source files remain private Inbox material.
 */
final class StudyScopeCaptureCommitFence
{
    public function __construct(
        private readonly NativeAiPurposeAuthorizationGuard $purposeGuard,
        private readonly PlanCategoryProfileService $categories,
        private readonly CompanionMembershipCommitGuard $membershipCommit,
    ) {}

    /**
     * @return string source + Plan input signature; never stores raw bytes
     */
    public function prepare(StudyScopeCapture $capture, Plan $plan, ?int $userId): string
    {
        return DB::transaction(function () use ($capture, $plan, $userId): string {
            $livePlan = Plan::query()->whereKey($plan->id)->lockForUpdate()->first();
            $liveCapture = StudyScopeCapture::query()->whereKey($capture->id)
                ->lockForUpdate()->first();
            $source = $liveCapture === null ? null
                : InboxItem::query()->whereKey($liveCapture->inbox_item_id)->lockForUpdate()->first();

            $authorization = $this->assertCurrent($livePlan, $liveCapture, $source, $plan->id, $capture->id, $userId, null, null);

            if ($liveCapture->status === 'confirmed') {
                $this->deny('native_ai_target_changed', null);
            }

            $signature = $this->signature($livePlan, $source, $authorization);
            if ($signature === null) {
                $this->deny('native_ai_source_changed', null);
            }

            $liveCapture->update([
                'status' => 'captured',
                'failure_code' => null,
            ]);

            return $signature;
        }, 3);
    }

    public function assertCanCommit(
        StudyScopeCapture $capture,
        Plan $plan,
        ?int $userId,
        string $expectedSignature,
        int $runId,
    ): StudyScopeCapture {
        // Caller must use this inside the transaction which persists the draft.
        $livePlan = Plan::query()->whereKey($plan->id)->lockForUpdate()->first();
        $liveCapture = StudyScopeCapture::query()->whereKey($capture->id)
            ->lockForUpdate()->first();
        $source = $liveCapture === null ? null
            : InboxItem::query()->whereKey($liveCapture->inbox_item_id)->lockForUpdate()->first();

        $this->assertCurrent(
            $livePlan, $liveCapture, $source, $plan->id, $capture->id,
            $userId, $expectedSignature, $runId,
        );

        if ($liveCapture->status !== 'captured') {
            $this->deny('native_ai_target_changed', $runId);
        }

        return $liveCapture;
    }

    /**
     * Validate a human review inside the same transaction that promotes
     * StudyScopeItems. AI drafts carry their original private-source and
     * owner/member epoch fingerprint; historical unsigned AI drafts must
     * be reanalyzed. Manual capture/failed-source entry remains available.
     */
    public function assertCanConfirm(
        StudyScopeCapture $capture,
        Plan $plan,
        ?int $userId,
        bool $usesDraft,
    ): StudyScopeCapture {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Study Scope confirmation requires a transaction.');
        }

        $livePlan = Plan::query()->whereKey($plan->id)->lockForUpdate()->first();
        $liveCapture = StudyScopeCapture::query()->whereKey($capture->id)
            ->lockForUpdate()->first();
        $source = $liveCapture === null ? null
            : InboxItem::query()->whereKey($liveCapture->inbox_item_id)
                ->lockForUpdate()->first();

        $status = (string) ($liveCapture?->status ?? '');
        if (! in_array($status, ['captured', 'failed', 'review', 'confirmed'], true)
            || ($usesDraft && $status !== 'review')) {
            throw ValidationException::withMessages([
                'items' => '解析候補が更新されています。再解析して確認するか、手動で範囲を入力してください。',
            ]);
        }

        $isAiReview = $status === 'review'
            && ((int) ($liveCapture->native_ai_run_id ?? 0) > 0 || $usesDraft);
        $expected = $isAiReview
            ? data_get($liveCapture->draft_data, 'source_signature')
            : null;
        if ($isAiReview && (! is_string($expected) || strlen($expected) !== 64)) {
            throw ValidationException::withMessages([
                'items' => '以前のAI解析候補には元資料と権限の証明がありません。再解析してください。',
            ]);
        }

        try {
            $this->assertCurrent(
                $livePlan, $liveCapture, $source, $plan->id, $capture->id,
                $userId, $expected, null,
            );
        } catch (NativeAiExecutionException) {
            throw ValidationException::withMessages([
                'items' => '試験範囲の元資料または共有権限が変更されました。現在の資料から再解析するか、手動で確認してください。',
            ]);
        }

        return $liveCapture;
    }

    public function recordFailureIfCurrent(
        StudyScopeCapture $capture,
        Plan $plan,
        ?int $userId,
        string $expectedSignature,
        NativeAiExecutionException $exception,
    ): void {
        try {
            DB::transaction(function () use ($capture, $plan, $userId, $expectedSignature, $exception): void {
                $live = $this->assertCanCommit(
                    $capture, $plan, $userId, $expectedSignature, (int) ($exception->runId ?? 0),
                );
                // This is status-only recovery for the same active original source.
                // It never reverts a confirmed or retargeted Capture.
                $live->update([
                    'status' => 'failed',
                    'native_ai_run_id' => $exception->runId,
                    'failure_code' => mb_substr($exception->errorCode, 0, 80),
                    'analyzed_at' => now(),
                ]);
            }, 3);
        } catch (Throwable) {
            // The original provider error is authoritative. A concurrent
            // confirm/revoke/delete must not be overwritten by failure status.
        }
    }

    /** @return array<string,mixed> Original or current locked authorization proof. */
    private function assertCurrent(
        ?Plan $plan,
        ?StudyScopeCapture $capture,
        ?InboxItem $source,
        int $expectedPlanId,
        int $expectedCaptureId,
        ?int $userId,
        ?string $expectedSignature,
        ?int $runId,
    ): array {
        if (
            $userId === null || $userId <= 0
            || $plan === null || $capture === null || $source === null
            || (int) $plan->id !== $expectedPlanId
            || (int) $capture->id !== $expectedCaptureId
            || (int) $capture->plan_id !== $expectedPlanId
            || (int) $source->id !== (int) $capture->inbox_item_id
            || (int) $source->plan_id !== $expectedPlanId
            || $source->user_id === null
            || (int) $source->user_id !== (int) $capture->user_id
            || $this->categories->forPlan($plan)->key !== 'study'
        ) {
            $this->deny('native_ai_target_changed', $runId);
        }

        // This method runs after Plan -> Capture -> Inbox row locks.
        // Lock the membership before capturing or comparing the original
        // owner/member epoch. Current Editor access alone cannot detect ABA.
        $actor = User::query()->find($userId);
        if ($actor === null
            || ! $this->membershipCommit->canAccess($actor, $plan, true)) {
            $this->deny('native_ai_target_changed', $runId);
        }

        $authorization = $this->membershipCommit->capture($actor, $plan);
        if (! in_array($authorization['scope'] ?? null, ['owner', 'member'], true)
            || (($authorization['scope'] ?? null) === 'member'
                && ($authorization['role'] ?? null) !== PlanMember::ROLE_EDITOR)) {
            $this->deny('native_ai_target_changed', $runId);
        }

        $failure = $this->purposeGuard->failure(
            $userId, (int) $plan->id, null, FeatureKey::StudyScopeCapture,
        );
        if ($failure !== null) {
            $this->deny($failure, $runId);
        }

        if ($expectedSignature !== null) {
            $actual = $this->signature($plan, $source, $authorization);
            if ($actual === null || ! hash_equals($expectedSignature, $actual)) {
                $this->deny('native_ai_source_changed', $runId);
            }
        }

        return $authorization;
    }

    /** @param array<string,mixed> $authorization */
    private function signature(Plan $plan, InboxItem $source, array $authorization): ?string
    {
        try {
            if (! in_array($source->source_type, ['image', 'pdf'], true)
                || ! is_string($source->storage_path)
                || $source->storage_path === ''
                || ! Storage::exists($source->storage_path)
            ) {
                return null;
            }

            $bytesHash = hash('sha256', Storage::get($source->storage_path));

            return hash('sha256', json_encode([
                'plan_id' => (int) $plan->id,
                'authorization' => $authorization,
                'category' => (string) $plan->category,
                'domain' => (string) ($plan->workspace_domain_override ?? ''),
                'deadline' => $plan->deadline?->format('Y-m-d'),
                'source_id' => (int) $source->id,
                'user_id' => $source->user_id === null ? null : (int) $source->user_id,
                'plan_source_id' => $source->plan_id === null ? null : (int) $source->plan_id,
                'status' => (string) $source->status,
                'type' => (string) $source->source_type,
                'content' => (string) $source->content,
                'title' => (string) $source->title,
                'metadata' => $source->metadata ?? [],
                'name' => (string) $source->original_name,
                'mime' => (string) $source->mime_type,
                'size' => $source->byte_size,
                'path' => $source->storage_path,
                'bytes' => $bytesHash,
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return null;
        }
    }

    private function deny(string $code, ?int $runId): never
    {
        throw new NativeAiExecutionException(
            '解析中に元の試験範囲または利用権限が変更されました。内容を確認して再解析してください。',
            $code,
            $runId,
        );
    }
}
