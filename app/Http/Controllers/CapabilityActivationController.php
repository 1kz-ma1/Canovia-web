<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\CapabilityActivationService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CapabilityActivationController extends Controller
{
    public function start(
        Request $request,
        string $capability,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        CapabilityActivationService $activation,
    ): RedirectResponse {
        abort_unless(
            $capability === CapabilityActivationService::GITHUB,
            404,
        );
        $ownership->authorizeEdit($request, $plan);
        abort_unless(
            $profiles->forPlan($plan)->key === 'development',
            404,
        );

        $state = $activation->startGithub($request, $plan);

        if (! (bool) ($state['can_start'] ?? false)) {
            return redirect()
                ->route('workspace.development.index', [
                    'plan_id' => $plan->id,
                ])
                ->with(
                    'status',
                    (string) (
                        $state['detail']
                        ?? '現在はGitHub設定を開始できません。'
                    ),
                );
        }

        return redirect()
            ->route('github_workflow.index', [
                'plan_id' => $plan->id,
            ])
            ->with(
                'status',
                data_get($state, 'repository.auto_candidate')
                    ? '対象Repository候補を1件に絞りました。内容を確認してGitHub接続を進めてください。'
                    : 'GitHub連携の準備を開始しました。対象Repositoryを確認してください。',
            );
    }

    public function abandon(
        Request $request,
        string $capability,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        CapabilityActivationService $activation,
    ): RedirectResponse {
        abort_unless(
            $capability === CapabilityActivationService::GITHUB,
            404,
        );
        $ownership->authorizeEdit($request, $plan);
        abort_unless(
            $profiles->forPlan($plan)->key === 'development',
            404,
        );

        $activation->abandonGithub($request, $plan);

        return redirect()
            ->route('workspace.development.index', [
                'plan_id' => $plan->id,
            ])
            ->with(
                'status',
                'GitHub連携の準備をいったん停止しました。Developmentはそのまま利用できます。',
            );
    }
}
