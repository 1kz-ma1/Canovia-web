<?php

namespace App\Http\Controllers;

use App\Intelligence\Development\DevelopmentAdaptiveActionService;
use App\Models\Plan;
use App\Models\Task;
use App\Services\BehaviorIdentityService;
use App\Services\DevelopmentQualityGateService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DevelopmentReadinessController extends Controller
{
    public function confirmQualityGate(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity,
        DevelopmentQualityGateService $gates,
        DevelopmentAdaptiveActionService $actions,
    ) {
        $ownership->authorizeEdit($request, $plan);

        abort_unless(
            (int) $task->plan_id === (int) $plan->id
            && $profiles->forPlan($plan)->key === 'development',
            404,
        );

        $validated = $request->validate([
            'quality_gate' => [
                'required',
                Rule::in(DevelopmentQualityGateService::GATES),
            ],
            'gate_status' => ['required', 'string', 'max:32'],
            'confirmation_request_id' => ['required', 'uuid'],
        ]);

        $evidence = $gates->confirm(
            $task,
            (string) $validated['quality_gate'],
            (string) $validated['gate_status'],
            (string) $validated['confirmation_request_id'],
            userId: $request->user()?->id,
            actorToken: $identity->resolve($request),
        );

        $actions->tryRefresh($plan, now());

        return redirect()
            ->route('github_workflow.index', ['plan_id' => $plan->id])
            ->with(
                'success',
                match ((string) data_get($evidence->metadata, 'quality_gate')) {
                    'verification' => '実機・本番確認をDevelopment Evidenceへ反映しました。',
                    'spec_sync' => '仕様同期の確認をDevelopment Evidenceへ反映しました。',
                    default => 'Development Quality Gateを更新しました。',
                },
            );
    }
}
