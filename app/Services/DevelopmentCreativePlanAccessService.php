<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Reversible, explicit bridge for legacy Creative Plans whose activity is
 * actually software development. Never infer "software" from collaboration
 * or silently rewrite a Plan's category.
 */
final class DevelopmentCreativePlanAccessService
{
    private const SESSION_PREFIX = 'canovia.development.legacy_creative_plans.v1.';

    /**
     * The caller must first authorize the Plan for this actor and ensure its
     * current profile is "creative". Selection changes this actor's session,
     * not the shared Plan or another collaborator's Workspace.
     */
    public function optIn(Request $request, Plan $plan): void
    {
        $ids = $this->selectedIds($request);
        $ids[] = (int) $plan->id;

        $request->session()->put(
            $this->sessionKey($request),
            array_slice(array_values(array_unique($ids)), -40),
        );
    }

    public function optOut(Request $request, Plan $plan): void
    {
        $ids = array_values(array_filter(
            $this->selectedIds($request),
            fn (int $id): bool => $id !== (int) $plan->id,
        ));

        if ($ids === []) {
            $request->session()->forget($this->sessionKey($request));

            return;
        }

        $request->session()->put($this->sessionKey($request), $ids);
    }

    /**
     * Only Plans accessible to the current actor may be projected.
     * All Creative Plans stay Creative by default until explicitly selected.
     *
     * @param Collection<int,Plan> $accessiblePlans
     * @return array{plans:Collection<int,Plan>,candidates:Collection<int,Plan>}
     */
    public function partition(
        Request $request,
        Collection $accessiblePlans,
        PlanCategoryProfileService $profiles,
    ): array {
        $selectedIds = $this->selectedIds($request);
        $development = collect();
        $candidates = collect();

        foreach ($accessiblePlans as $plan) {
            $profile = $profiles->forPlan($plan)->key;
            if ($profile === 'development') {
                $development->push($plan);

                continue;
            }

            if ($profile !== 'creative') {
                continue;
            }

            if (in_array((int) $plan->id, $selectedIds, true)) {
                $development->push($plan);
            } else {
                $candidates->push($plan);
            }
        }

        return [
            'plans' => $development->values(),
            'candidates' => $candidates->values(),
        ];
    }

    private function sessionKey(Request $request): string
    {
        $userId = (int) ($request->user()?->id ?? 0);

        return self::SESSION_PREFIX.($userId > 0 ? 'user_'.$userId : 'guest');
    }

    /** @return array<int,int> */
    private function selectedIds(Request $request): array
    {
        $raw = $request->session()->get($this->sessionKey($request), []);

        return collect(is_array($raw) ? $raw : [])
            ->filter(fn (mixed $id): bool =>
                (is_int($id) && $id > 0)
                || (is_string($id) && ctype_digit($id) && (int) $id > 0),
            )
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->take(40)
            ->values()
            ->all();
    }
}
