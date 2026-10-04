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
use Illuminate\Validation\ValidationException;

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

        $current = $actions->evaluate($plan);
        $focus = data_get(
            $current->intelligence->state->facts,
            'focus_task_state',
        );

        if (
            ! is_array($focus)
            || (int) ($focus['task_id'] ?? 0) !== (int) $task->id
        ) {
            throw ValidationException::withMessages([
                'quality_gate' => '現在のRelease candidate Taskを開き直してから確認してください。',
            ]);
        }

        $qualityGate = (string) $validated['quality_gate'];
        $targetSha = mb_strtolower(trim((string) (
            $focus['head_sha'] ?? ''
        )));
        $deploymentId = null;

        if ($qualityGate === 'verification') {
            if (
                data_get($focus, 'gates.deploy.status') !== 'passed'
                || (int) ($focus['deployment_id'] ?? 0) <= 0
                || trim((string) ($focus['deployment_sha'] ?? '')) === ''
            ) {
                throw ValidationException::withMessages([
                    'quality_gate' => 'Production Deployが確認できてから実機・本番確認を記録してください。',
                ]);
            }

            $deploymentId = (int) $focus['deployment_id'];
            $targetSha = mb_strtolower(trim((string) $focus['deployment_sha']));
        } elseif (
            data_get($focus, 'gates.implementation.status') !== 'passed'
            || $targetSha === ''
        ) {
            throw ValidationException::withMessages([
                'quality_gate' => '現在の実装SHAを確認できてから仕様同期を記録してください。',
            ]);
        }

        $evidence = $gates->confirm(
            $task,
            $qualityGate,
            (string) $validated['gate_status'],
            (string) $validated['confirmation_request_id'],
            userId: $request->user()?->id,
            actorToken: $identity->resolve($request),
            targetSha: $targetSha,
            deploymentId: $deploymentId,
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
