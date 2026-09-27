<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PlanOwnershipService
{
    /** @var array<string,string|null> */
    private array $roleCache = [];

    /**
     * Historical method name kept for compatibility. It now returns every plan
     * the current actor may view: owned plans plus logged-in collaborative plans.
     */
    public function ownedPlans(Request $request, array $with = []): Collection
    {
        $userId = $request->user()?->id;
        $guestPlanIds = $this->guestPlanIds($request);

        if (! $userId && $guestPlanIds === []) {
            return collect();
        }

        $query = Plan::with($with)->latest();

        $query->where(function ($query) use ($userId, $guestPlanIds) {
            if ($userId) {
                $query->where('user_id', $userId)
                    ->orWhereHas('memberships', fn ($memberQuery) => $memberQuery->where('user_id', $userId));
            }

            if ($guestPlanIds !== []) {
                $method = $userId ? 'orWhere' : 'where';
                $query->{$method}(function ($guestQuery) use ($guestPlanIds) {
                    $guestQuery->whereNull('user_id')->whereIn('id', $guestPlanIds);
                });
            }
        });

        return $query->get()
            ->toBase()
            ->filter(fn (Plan $plan) => $this->canView($request, $plan))
            ->values();
    }

    public function owns(Request $request, Plan $plan): bool
    {
        if ($plan->user_id !== null) {
            return $request->user() && (int) $plan->user_id === (int) $request->user()->id;
        }

        $token = $request->cookie('pace_keeper_owner_token_' . $plan->id);

        return is_string($token)
            && $token !== ''
            && is_string($plan->owner_token)
            && hash_equals($plan->owner_token, $token);
    }

    public function role(Request $request, Plan $plan): ?string
    {
        $planId = (int) $plan->id;
        $actorKey = $request->user()?->id
            ? 'user:'.$request->user()->id
            : 'guest:'.substr(hash('sha256', (string) $request->cookie('pace_keeper_owner_token_'.$planId)), 0, 16);
        $cacheKey = $actorKey.':plan:'.$planId;
        if (array_key_exists($cacheKey, $this->roleCache)) {
            return $this->roleCache[$cacheKey];
        }

        if ($this->owns($request, $plan)) {
            return $this->roleCache[$cacheKey] = 'owner';
        }

        $user = $request->user();
        if (! $user || ! $plan->is_collaborative) {
            return $this->roleCache[$cacheKey] = null;
        }

        if ($plan->relationLoaded('memberships')) {
            $membership = $plan->memberships->firstWhere('user_id', $user->id);

            return $this->roleCache[$cacheKey] = $membership?->role;
        }

        return $this->roleCache[$cacheKey] = PlanMember::query()
            ->where('plan_id', $plan->id)
            ->where('user_id', $user->id)
            ->value('role');
    }

    public function canView(Request $request, Plan $plan): bool
    {
        return in_array($this->role($request, $plan), ['owner', PlanMember::ROLE_EDITOR, PlanMember::ROLE_VIEWER], true);
    }

    public function canEdit(Request $request, Plan $plan): bool
    {
        return in_array($this->role($request, $plan), ['owner', PlanMember::ROLE_EDITOR], true);
    }

    public function authorizeView(Request $request, Plan $plan): void
    {
        if (! $this->canView($request, $plan)) {
            abort(403, 'この共同計画を閲覧する権限がありません。');
        }
    }

    /** Owner-only authorization for plan settings, sharing, and destructive actions. */
    public function authorizePlan(Request $request, Plan $plan): void
    {
        if (! $this->owns($request, $plan)) {
            abort(403, 'この計画を管理する権限がありません。');
        }
    }

    public function authorizeEdit(Request $request, Plan $plan): void
    {
        if (! $this->canEdit($request, $plan)) {
            abort(403, 'この共同計画を編集する権限がありません。');
        }
    }

    public function authorizeTaskView(Request $request, Task $task): void
    {
        $task->loadMissing('plan');
        $this->authorizeView($request, $task->plan);
    }

    /** Task-level actions are daily collaboration actions, so editors are allowed. */
    public function authorizeTask(Request $request, Task $task): void
    {
        $task->loadMissing('plan');
        $this->authorizeEdit($request, $task->plan);
    }

    /** @return array<int> */
    private function guestPlanIds(Request $request): array
    {
        $ids = [];
        foreach (array_keys($request->cookies->all()) as $name) {
            if (preg_match('/^pace_keeper_owner_token_(\d+)$/', (string) $name, $matches)) {
                $ids[] = (int) $matches[1];
            }
        }

        return array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
    }
}
