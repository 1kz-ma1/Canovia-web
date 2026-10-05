<?php

namespace App\Http\Controllers;

use App\Intelligence\Study\StudyLearningTypeRouter;
use App\Models\Plan;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StudyLearningTypeController extends Controller
{
    public function update(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        StudyLearningTypeRouter $router,
    ) {
        $ownership->authorizeEdit($request, $plan);
        abort_unless($profiles->forPlan($plan)->key === 'study', 404);

        $validated = $request->validate([
            'learning_type' => [
                'required',
                'string',
                Rule::in(array_keys($router->options())),
            ],
        ]);

        $plan->forceFill([
            'study_learning_type_override' =>
                $validated['learning_type'],
            'study_learning_type_confirmed_at' => now(),
        ])->save();

        return redirect()
            ->route('workspace.study.index', [
                'plan_id' => $plan->id,
            ])
            ->with(
                'success',
                '学習タイプを更新しました。',
            );
    }

    public function destroy(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
    ) {
        $ownership->authorizeEdit($request, $plan);
        abort_unless($profiles->forPlan($plan)->key === 'study', 404);

        $plan->forceFill([
            'study_learning_type_override' => null,
            'study_learning_type_confirmed_at' => null,
        ])->save();

        return redirect()
            ->route('workspace.study.index', [
                'plan_id' => $plan->id,
            ])
            ->with(
                'success',
                '学習タイプを自動判定に戻しました。',
            );
    }
}
