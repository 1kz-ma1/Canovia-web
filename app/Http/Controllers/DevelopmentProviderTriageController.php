<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Intelligence\Development\DevelopmentAdaptiveActionService;
use App\Models\Plan;
use App\Models\Task;
use App\Services\DevelopmentExecutionContextService;
use App\Services\DevelopmentProviderTriageService;
use App\Services\FeatureAccessService;
use App\Services\GitHubRepositoryWriter;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

final class DevelopmentProviderTriageController extends Controller
{
    public function __invoke(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        FeatureAccessService $featureAccess,
        DevelopmentAdaptiveActionService $developmentActions,
        DevelopmentExecutionContextService $executionContext,
        GitHubRepositoryWriter $github,
        DevelopmentProviderTriageService $triage,
    ) {
        $ownership->authorizeEdit($request, $plan);

        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        abort_unless($profiles->forPlan($plan)->key === 'development', 404);

        $featureAccess->authorizeUse(
            $request->user(),
            FeatureKey::DeveloperGithubEvidence,
            ['plan_id' => (int) $plan->id],
        );

        $validated = $request->validate([
            'mode' => [
                'nullable',
                'string',
                Rule::in(['auto', 'ci', 'review']),
            ],
        ]);

        $adaptive = $developmentActions->evaluate($plan);
        $action = $adaptive->primaryAction();
        $context = $executionContext->build(
            $plan,
            (int) $task->id,
            $action,
        );

        if (
            ! is_array($context)
            || (int) data_get($context, 'task.id', 0) !== (int) $task->id
        ) {
            return redirect()
                ->route('workspace.development.index', [
                    'plan_id' => $plan->id,
                ])
                ->withErrors([
                    'provider_triage' =>
                        '現在TaskのDevelopment Contextを確認できませんでした。',
                ]);
        }

        $repo = trim((string) data_get($context, 'repository', ''));
        $pullRequestNumber = (int) data_get(
            $context,
            'pull_request.number',
            0,
        );

        if ($repo === '' || $pullRequestNumber <= 0) {
            return redirect()
                ->route('workspace.development.index', [
                    'plan_id' => $plan->id,
                ])
                ->withErrors([
                    'provider_triage' =>
                        'Provider triageにはTaskへ紐づくPull Request Evidenceが必要です。',
                ]);
        }

        $mode = (string) ($validated['mode'] ?? 'auto');
        if ($mode === 'auto') {
            $mode = match ($action?->kind) {
                'development_fix_ci',
                'development_run_ci' => 'ci',
                'development_review_fix',
                'development_obtain_review' => 'review',
                default => 'auto',
            };
        }

        try {
            $providerSnapshot = $github->inspectPullRequestTriage(
                $repo,
                $pullRequestNumber,
                $mode,
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('workspace.development.index', [
                    'plan_id' => $plan->id,
                ])
                ->withErrors([
                    'provider_triage' => $exception->getMessage(),
                ]);
        }

        $projection = $triage->build(
            $task,
            $context,
            $providerSnapshot,
            $action,
        );

        return response()
            ->view('workspace.development.provider-triage', [
                'plan' => $plan,
                'task' => $task,
                'triage' => $projection,
                'executionContext' => $context,
                'backUrl' => route(
                    'workspace.development.index',
                    ['plan_id' => $plan->id],
                ).'#development-current-action',
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
