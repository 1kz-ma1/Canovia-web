<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\GoalContext;
use App\Models\Plan;
use Illuminate\Http\Request;

/**
 * Bounded G3 fence: recheck actor, Plan and the exact Goal Context inputs
 * after the provider returns, inside the transaction that writes the reply
 * and quoted memories. Does not authorize guest Native AI provider calls.
 */
final class GoalDiscoveryAiCommitFence
{
    public function __construct(
        private readonly NativeAiPurposeAuthorizationGuard $purposeGuard,
        private readonly FeatureFlagService $flags,
        private readonly BehaviorIdentityService $identity,
    ) {}

    public function fingerprint(GoalContext $context): string
    {
        $facts = $context->facts()
            ->orderBy('id')
            ->get(['id', 'type', 'key', 'label', 'value_json', 'source', 'state', 'confidence', 'importance', 'metadata', 'updated_at'])
            ->map(fn ($fact) => $fact->getAttributes())
            ->all();

        $history = $context->discoveryMessages()
            ->latest('id')
            ->limit(8)
            ->get(['id', 'user_id', 'role', 'content', 'metadata', 'updated_at'])
            ->reverse()
            ->values()
            ->map(fn ($message) => $message->getAttributes())
            ->all();

        return hash('sha256', json_encode([
            'id' => (int) $context->id,
            'user_id' => $context->user_id,
            'actor_token' => $context->actor_token,
            'plan_id' => $context->plan_id,
            'desired_state' => $context->desired_state,
            'current_state_summary' => $context->current_state_summary,
            'readiness_score' => $context->readiness_score,
            'readiness_state' => $context->readiness_state,
            'interaction_profile' => $context->interaction_profile,
            'status' => $context->status,
            'facts' => $facts,
            'recent_messages' => $history,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Must run inside the caller's database transaction.
     */
    public function assertCanCommit(
        Request $request,
        GoalContext $context,
        string $expectedFingerprint,
        bool $nativeAi,
        ?int $runId = null,
    ): GoalContext {
        $live = GoalContext::query()->whereKey($context->id)->lockForUpdate()->first();

        if ($live === null) {
            $this->deny('native_ai_target_changed', $runId);
        }

        $userId = $request->user()?->id;
        if ($userId !== null) {
            if ($live->user_id === null || (int) $live->user_id !== (int) $userId) {
                $this->deny('native_ai_target_changed', $runId);
            }
        } else {
            $token = $this->identity->resolve($request);
            if (
                $live->user_id !== null
                || ! is_string($live->actor_token)
                || ! hash_equals($live->actor_token, $token)
            ) {
                $this->deny('native_ai_target_changed', $runId);
            }
        }

        if ($live->plan_id !== null) {
            $plan = Plan::query()->whereKey($live->plan_id)->lockForUpdate()->first();
            if (
                $plan === null
                || $userId === null
                || (int) $plan->user_id !== (int) $userId
            ) {
                // Goal Discovery is actor-owned, not a collaborator assistant.
                $this->deny('native_ai_target_changed', $runId);
            }
        }

        if (! hash_equals($expectedFingerprint, $this->fingerprint($live))) {
            $this->deny('native_ai_source_changed', $runId);
        }

        if ($nativeAi) {
            if (! $this->flags->isEnabled(FeatureKey::ConversationalOnboarding)) {
                $this->deny('native_ai_purpose_not_entitled', $runId);
            }

            $failure = $this->purposeGuard->failure(
                $userId,
                $live->plan_id === null ? null : (int) $live->plan_id,
                null,
                FeatureKey::ConversationalOnboarding,
            );
            if ($failure !== null) {
                $this->deny($failure, $runId);
            }
        }

        return $live;
    }

    private function deny(string $code, ?int $runId): never
    {
        throw new NativeAiExecutionException(
            'Goal Discoveryの回答中に権限または元の会話が更新されました。',
            $code,
            $runId,
        );
    }
}
