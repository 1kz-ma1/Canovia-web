<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\Task;
use App\Models\User;
use Throwable;

/**
 * G3 bounded Native AI execution fence. The FeatureKey is the declared
 * purpose/entitlement for this run, NOT an MCP delegated ChatGPT grant.
 *
 * Use before sending private input and after receiving provider output.
 * No network call or raw contextual content is stored here.
 */
final class NativeAiPurposeAuthorizationGuard
{
    public function __construct(
        private readonly FeatureAccessService $access,
    ) {}

    /**
     * @return string|null Stable internal deny reason, or null if authorized.
     */
    public function failure(
        ?int $userId,
        ?int $planId,
        ?int $taskId,
        FeatureKey $feature,
    ): ?string {
        try {
            if ($userId === null || $userId <= 0) {
                // There is no cookie/session ownership proof at this service
                // layer. Anonymous callers must use a separate guest policy.
                return 'native_ai_actor_unverified';
            }

            $user = User::query()->find($userId);
            if ($user === null) {
                return 'native_ai_actor_unverified';
            }

            if ($taskId !== null && $planId === null) {
                return 'native_ai_target_changed';
            }

            if ($planId !== null) {
                $plan = Plan::query()->find($planId);
                if ($plan === null) {
                    return 'native_ai_target_changed';
                }

                $owned = $plan->user_id !== null
                    && (int) $plan->user_id === (int) $user->id;
                $member = $plan->is_collaborative
                    && PlanMember::query()
                        ->where('plan_id', $plan->id)
                        ->where('user_id', $user->id)
                        ->whereIn('role', [PlanMember::ROLE_EDITOR, PlanMember::ROLE_VIEWER])
                        ->exists();

                if (! $owned && ! $member) {
                    return 'native_ai_target_changed';
                }

                if ($taskId !== null) {
                    $task = Task::query()->find($taskId);
                    if ($task === null || (int) $task->plan_id !== (int) $plan->id) {
                        return 'native_ai_target_changed';
                    }
                }
            }

            return $this->access->canUse($user, $feature)
                ? null
                : 'native_ai_purpose_not_entitled';
        } catch (Throwable) {
            return 'native_ai_authorization_unavailable';
        }
    }
}
