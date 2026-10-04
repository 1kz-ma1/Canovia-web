<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceMode;
use App\Models\Plan;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\PlanPriorityService;
use App\Services\WorkspaceModePreference;
use App\Services\WorkspaceModeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WorkspaceModeController extends Controller
{
    public function enter(
        Request $request,
        string $workspaceMode,
        WorkspaceModeRegistry $registry,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        PlanPriorityService $priorities,
    ): RedirectResponse {
        $mode = $this->publicMode($workspaceMode, $registry);

        return $this->redirectForMode(
            $request,
            $mode,
            $ownership,
            $profiles,
            $priorities,
        );
    }

    public function select(
        Request $request,
        string $workspaceMode,
        WorkspaceModeRegistry $registry,
        WorkspaceModePreference $preference,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        PlanPriorityService $priorities,
    ): RedirectResponse {
        $mode = $this->publicMode($workspaceMode, $registry);

        $preference->remember($request, $mode);

        return $this->redirectForMode(
            $request,
            $mode,
            $ownership,
            $profiles,
            $priorities,
        );
    }

    public function reset(
        Request $request,
        WorkspaceModePreference $preference,
    ): RedirectResponse {
        $preference->clear($request);

        return redirect()
            ->route('home')
            ->with('status', 'Workspaceを自動判定に戻しました。');
    }

    private function publicMode(
        string $workspaceMode,
        WorkspaceModeRegistry $registry,
    ): WorkspaceMode {
        $mode = WorkspaceMode::tryFrom(
            mb_strtolower(trim($workspaceMode)),
        );

        abort_unless(
            $mode instanceof WorkspaceMode
                && in_array(
                    $mode->value,
                    $registry->publicKeys(),
                    true,
                ),
            404,
        );

        return $mode;
    }

    private function redirectForMode(
        Request $request,
        WorkspaceMode $mode,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        PlanPriorityService $priorities,
    ): RedirectResponse {
        if ($mode === WorkspaceMode::Overview) {
            return redirect()->route('workspace.overview.index');
        }

        if ($mode === WorkspaceMode::Study) {
            return redirect()->route('workspace.study.index');
        }

        if ($mode === WorkspaceMode::Development) {
            return redirect()->route('workspace.development.index');
        }

        if ($mode === WorkspaceMode::Career) {
            return redirect()->route('workspace.career.index');
        }

        $profileKey = $mode->value;
        $plans = $ownership->ownedPlans($request, [
            'tasks',
            'workLogs',
            'availabilityRules',
            'availabilityOverrides',
        ])->filter(
            fn (Plan $plan) =>
                $profiles->forPlan($plan)->key === $profileKey,
        );

        $plan = $plans
            ->sort(function (Plan $left, Plan $right) use ($priorities) {
                $priority = (int) data_get(
                    $priorities->evaluate($left),
                    'priority',
                    5,
                ) <=> (int) data_get(
                    $priorities->evaluate($right),
                    'priority',
                    5,
                );

                if ($priority !== 0) {
                    return $priority;
                }

                $deadline = ($left->deadline?->timestamp ?? PHP_INT_MAX)
                    <=> ($right->deadline?->timestamp ?? PHP_INT_MAX);

                if ($deadline !== 0) {
                    return $deadline;
                }

                return ((int) $left->id) <=> ((int) $right->id);
            })
            ->first();

        if ($plan instanceof Plan) {
            return match ($mode) {
                WorkspaceMode::Study => redirect()->route(
                    'workspace.study.index',
                    ['plan_id' => $plan->id],
                ),
                WorkspaceMode::Development => redirect()->route(
                    'workspace.development.index',
                    ['plan_id' => $plan->id],
                ),
                WorkspaceMode::Career => redirect()->route(
                    'workspace.career.index',
                    ['plan_id' => $plan->id],
                ),
                WorkspaceMode::Overview => redirect()->route('workspace.overview.index'),
            };
        }

        return redirect()
            ->route('home', ['workspace_mode' => $mode->value])
            ->with(
                'status',
                match ($mode) {
                    WorkspaceMode::Study => '学習Workspaceを始めるには、学習Planを作成してください。',
                    WorkspaceMode::Development => '開発Workspaceを始めるには、開発Planを作成してください。',
                    WorkspaceMode::Career => 'Career Workspaceを始めるには、Career Planを作成してください。',
                    WorkspaceMode::Overview => 'Overviewを開きました。',
                },
            );
    }
}
