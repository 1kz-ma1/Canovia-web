<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\PlanActivityService;
use App\Services\PlanOwnershipService;
use App\Services\PlanSpecializationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PlanSpecializationController extends Controller
{
    public function update(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanSpecializationService $specializations,
        PlanActivityService $activity,
    ): RedirectResponse {
        $ownership->authorizePlan($request, $plan);
        $context = $specializations->forPlan($plan);
        $options = array_keys($context['allowed']);

        $validated = $request->validate([
            'workspace_specialization_override' => [
                'required',
                'string',
                Rule::in(array_merge(['auto'], $options)),
            ],
        ]);
        $chosen = $validated['workspace_specialization_override'];
        $next = $chosen === 'auto' ? null : $chosen;

        if ($plan->workspace_specialization_override !== $next) {
            $plan->update(['workspace_specialization_override' => $next]);
            $activity->record(
                $plan,
                $request->user(),
                'plan_updated',
                'plan',
                (int) $plan->id,
                ['changed_fields' => ['workspace_specialization_override']],
            );
        }

        return redirect()->route('plans.edit', $plan)
            ->with('success', $next
                ? '活動内容を確認済みとして保存しました。元のカテゴリや共同計画の設定は変更していません。'
                : '活動内容の手動設定を解除しました。Canoviaの推定があれば参考情報として表示します。');
    }
}
