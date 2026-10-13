<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Commit-time scope fence for a Companion derivative on a shared Plan.
 *
 * Callers acquire Plan (and Task / Candidate) before this guard. Existing
 * membership rows are locked until the transaction commits. A durable
 * membership proof from context capture also prevents removal + fresh join
 * from silently re-authorizing a response or human-approval proposal made
 * under an older membership identity.
 */
final class CompanionMembershipCommitGuard
{
    /**
     * Unlocked, bounded provenance captured BEFORE requesting any AI output.
     * It is not authorization on its own. A new row has a different database
     * identity even when the same actor rejoins with the same role.
     *
     * @return array{scope:string, membership_id?:int, role?:string, owner_user_id?:int}
     */
    public function capture(User $actor, ?Plan $selectedPlan): array
    {
        if ($selectedPlan === null) {
            return ['scope' => 'global'];
        }

        $plan = Plan::query()->whereKey($selectedPlan->id)->first();
        if (! $plan) {
            return ['scope' => 'unavailable'];
        }

        if ($plan->user_id !== null && (int) $plan->user_id === (int) $actor->id) {
            return ['scope' => 'owner', 'owner_user_id' => (int) $actor->id];
        }

        if (! $plan->is_collaborative) {
            return ['scope' => 'unavailable'];
        }

        $member = PlanMember::query()
            ->where('plan_id', $plan->id)
            ->where('user_id', $actor->id)
            ->first();

        return $member && in_array($member->role, PlanMember::ROLES, true)
            ? [
                'scope' => 'member',
                'membership_id' => (int) $member->id,
                'role' => (string) $member->role,
            ]
            : ['scope' => 'unavailable'];
    }

    /**
     * @param array<string,mixed>|null $expectedIdentity
     * Legacy/unscoped calls may omit identity; a newly captured AI response
     * MUST pass its proof to reject remove/rejoin (membership ABA).
     */
    public function canAccess(
        User $actor,
        ?Plan $selectedPlan,
        bool $requiresEdit = false,
        ?array $expectedIdentity = null,
    ): bool {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Companion membership commit guard requires a database transaction.');
        }

        if ($selectedPlan === null) {
            return $expectedIdentity === null || $expectedIdentity === ['scope' => 'global'];
        }

        // Caller already holds the Plan row lock. Do not trust the route
        // model's loaded relationships or the request-local role cache.
        $plan = Plan::query()->whereKey($selectedPlan->id)->first();
        if ($plan === null) {
            return false;
        }

        $member = PlanMember::query()
            ->where('plan_id', $plan->id)
            ->where('user_id', $actor->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($plan->user_id !== null && (int) $plan->user_id === (int) $actor->id) {
            $identity = ['scope' => 'owner', 'owner_user_id' => (int) $actor->id];

            return $expectedIdentity === null || $identity === $expectedIdentity;
        }

        if (! $plan->is_collaborative || $member === null) {
            return false;
        }

        if ($requiresEdit
            ? $member->role !== PlanMember::ROLE_EDITOR
            : ! in_array($member->role, PlanMember::ROLES, true)) {
            return false;
        }

        $identity = [
            'scope' => 'member',
            'membership_id' => (int) $member->id,
            'role' => (string) $member->role,
        ];

        return $expectedIdentity === null || $identity === $expectedIdentity;
    }
}
