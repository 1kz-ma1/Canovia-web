<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Task;
use App\Services\ExecutionLaunchResolver;
use App\Services\ExecutionResolver;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;

final class ExecutionValidationProviderController extends Controller
{
    public function show(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        ExecutionResolver $resolver,
    ) {
        abort_unless(
            (bool) config(
                'canovia.execution_setup_validation_enabled',
                false,
            ),
            404,
        );
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeTask($request, $task);

        $resolution = $resolver->resolve($plan, $task);

        abort_unless(
            $resolution->provider?->key
                === ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
            404,
        );

        return view('execution.validation-study-practice', [
            'plan' => $plan,
            'task' => $task,
            'provider' => $resolution->provider,
        ]);
    }
}
