<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Task;
use App\Services\ExecutionSetupService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ExecutionSetupController extends Controller
{
    public function store(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        ExecutionSetupService $setup,
    ) {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeEdit($request, $plan);
        abort_unless($profiles->forPlan($plan)->key === 'study', 404);

        $validated = $request->validate([
            'provider_key' => ['required', 'string', 'max:191'],
        ]);

        try {
            $preference = $setup->choose(
                $plan,
                $task,
                (string) $validated['provider_key'],
            );
        } catch (InvalidArgumentException) {
            abort(422, '選択した実行方法は現在利用できません。');
        }

        return redirect()
            ->route('workspace.study.index', ['plan_id' => $plan->id])
            ->with(
                'status',
                '実行方法を更新しました: '.$preference->provider_key,
            );
    }
}
