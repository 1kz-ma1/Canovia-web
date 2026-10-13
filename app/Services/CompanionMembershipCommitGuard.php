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
 * Caller locks Plan (and Task / Candidate where applicable) first. This
 * method locks the actor's existing membership row before re-reading the
 * canonical Plan and role. All locks survive until the outer transaction
 * commits or rolls back. Never consult the request-local role cache here.
 */
final class CompanionMembershipCommitGuard
{
    public function canAccess(User $actor, ?Plan $selectedPlan, bool $requiresEdit = false): bool
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Companion membership commit guard requires a database transaction.');
        }

        if ($selectedPlan === null) {
            return true; // Global Companion conversations have no Plan membership.
        }

        $plan = Plan::query()->whereKey($selectedPlan->id)->first();
        if ($plan === null) {
            return false;
        }

        // Even a role changed by another transaction after the AI call
        // cannot slip between this check and the derivative commit.
        $member = PlanMember::query()
            ->where('plan_id', $plan->id)
            ->where('user_id', $actor->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ((int) ($plan->user_id ?? 0) === (int) $actor->id) {
            return true;
        }

        if (! $plan->is_collaborative || $member === null) {
            return false;
        }

        return $requiresEdit
            ? $member->role === PlanMember::ROLE_EDITOR
            : in_array($member->role, [PlanMember::ROLE_EDITOR, PlanMember::ROLE_VIEWER], true);
    }
}
