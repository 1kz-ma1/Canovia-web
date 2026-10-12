<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\StudyScopeCapture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

            $this->assertCurrent($livePlan, $liveCapture, $source, $plan->id, $capture->id, $userId, null, null);

            if ($liveCapture->status === 'confirmed') {
                $this->deny('native_ai_target_changed', null);
            }

            $signature = $this->signature($livePlan, $source);
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

    private function assertCurrent(
        ?Plan $plan,
        ?StudyScopeCapture $capture,
        ?InboxItem $source,
        int $expectedPlanId,
        int $expectedCaptureId,
        ?int $userId,
        ?string $expectedSignature,
        ?int $runId,
    ): void {
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

        $editor = (int) $plan->user_id === $userId
            || ($plan->is_collaborative && PlanMember::query()
                ->where('plan_id', $plan->id)
                ->where('user_id', $userId)
                ->where('role', PlanMember::ROLE_EDITOR)
                ->exists());

        if (! $editor) {
            $this->deny('native_ai_target_changed', $runId);
        }

        $failure = $this->purposeGuard->failure(
            $userId, (int) $plan->id, null, FeatureKey::StudyScopeCapture,
        );
        if ($failure !== null) {
            $this->deny($failure, $runId);
        }

        if ($expectedSignature !== null) {
            $actual = $this->signature($plan, $source);
            if ($actual === null || ! hash_equals($expectedSignature, $actual)) {
                $this->deny('native_ai_source_changed', $runId);
            }
        }
    }

    private function signature(Plan $plan, InboxItem $source): ?string
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
                'category' => (string) $plan->category,
                'domain' => (string) ($plan->workspace_domain_override ?? ''),
                'deadline' => $plan->deadline?->format('Y-m-d'),
                'source_id' => (int) $source->id,
                'user_id' => $source->user_id === null ? null : (int) $source->user_id,
                'plan_source_id' => $source->plan_id === null ? null : (int) $source->plan_id,
                'status' => (string) $source->status,
                'type' => (string) $source->source_type,
                'content' => (string) $source->content,
                'name' => (string) $source->original_name,
                'mime' => (string) $source->mime_type,
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
