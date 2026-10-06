<?php

namespace App\Http\Controllers;

use App\Models\DevelopmentActivityObservation;
use App\Models\Plan;
use App\Models\Task;
use App\Services\DevelopmentTaskAssociationService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DevelopmentActivityObservationController extends Controller
{
    public function link(
        Request $request,
        Plan $plan,
        DevelopmentActivityObservation $observation,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        DevelopmentTaskAssociationService $associations,
    ) {
        $ownership->authorizeEdit($request, $plan);
        abort_unless($profiles->forPlan($plan)->key === 'development', 404);
        abort_unless((int) $observation->plan_id === (int) $plan->id, 404);

        $validated = $request->validate([
            'task_id' => ['required', 'integer', 'min:1'],
        ]);

        $task = $plan->tasks()
            ->whereKey((int) $validated['task_id'])
            ->first();

        if (! $task instanceof Task) {
            throw ValidationException::withMessages([
                'task_id' => '同じPlanのTaskを選んでください。',
            ]);
        }

        $result = $associations->link($plan, $task, $observation);

        $redirect = redirect()
            ->route('workspace.development.index', [
                'plan_id' => (int) $plan->id,
            ])
            ->with(
                'success',
                'GitHub活動を「'.$task->title.'」へ関連付けました。Task進捗は自動変更していません。',
            );

        return filled($result['warning'] ?? null)
            ? $redirect->with('status', (string) $result['warning'])
            : $redirect;
    }

    public function ignore(
        Request $request,
        Plan $plan,
        DevelopmentActivityObservation $observation,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        DevelopmentTaskAssociationService $associations,
    ) {
        $ownership->authorizeEdit($request, $plan);
        abort_unless($profiles->forPlan($plan)->key === 'development', 404);
        abort_unless((int) $observation->plan_id === (int) $plan->id, 404);

        $associations->ignore($plan, $observation);

        return redirect()
            ->route('workspace.development.index', [
                'plan_id' => (int) $plan->id,
            ])
            ->with('success', 'このGitHub活動はTask関連付け候補から外しました。');
    }
}
