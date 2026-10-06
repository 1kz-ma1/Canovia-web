<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Jobs\BootstrapGitHubDevelopmentActivity;
use App\Intelligence\Development\DevelopmentAdaptiveActionService;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Presentation\DevelopmentIntelligencePresentationAdapter;
use App\Intelligence\Presentation\IntelligencePresentationHistoryService;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Services\BehaviorIdentityService;
use App\Services\FeatureAccessService;
use App\Services\GitHubIntegrationReadinessService;
use App\Services\GitHubRepositoryInspector;
use App\Services\GitHubRepositoryWriter;
use App\Services\GitHubWorkflowService;
use App\Services\PlanActivityService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\TaskEvidenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class GitHubWorkflowController extends Controller
{
    public function __construct(
        private readonly GitHubIntegrationReadinessService $githubReadiness,
    ) {}
    public function index(
        Request $request,
        GitHubWorkflowService $workflow,
        FeatureAccessService $featureAccess,
        GitHubRepositoryWriter $repositoryWriter,
        PlanCategoryProfileService $profiles,
        DevelopmentAdaptiveActionService $developmentActions,
        DevelopmentIntelligencePresentationAdapter $presentationAdapter,
        IntelligencePresentationHistoryService $history,
    ) {
        $dashboard = $workflow->dashboard($request);
        $integrationStatus = $this->githubReadiness->forActor(
            $request->user(),
        );
        $dashboard['repository_overviews'] = collect(
            $dashboard['repository_overviews'] ?? [],
        )
            ->map(function (array $overview) use ($integrationStatus) {
                $overview['integration_readiness'] =
                    $this->githubReadiness->connectionStatus(
                        $integrationStatus,
                        is_array($overview['app_connection'] ?? null)
                            ? $overview['app_connection']
                            : [],
                    );

                return $overview;
            });
        $selectedPlan = $dashboard['selected_plan'] ?? null;
        $developmentAction = null;
        $developmentFocusTask = null;
        $intelligencePresentation = null;
        $intelligenceHistory = [];
        $canEditDevelopment = false;

        if (
            $selectedPlan
            && $profiles->forPlan($selectedPlan)->key === 'development'
        ) {
            $developmentAction = $developmentActions->evaluate($selectedPlan);
            $focusTaskId = (int) data_get(
                $developmentAction->intelligence->state->facts,
                'focus_task_id',
                0,
            );

            if ($focusTaskId > 0) {
                $developmentFocusTask = $selectedPlan->tasks
                    ->firstWhere('id', $focusTaskId);
            }

            $intelligencePresentation = $presentationAdapter->adapt(
                $selectedPlan,
                $developmentAction,
            );
            $intelligenceHistory = $history->forPlan(
                $selectedPlan,
                IntelligenceDomain::Development,
            );

            $canEditDevelopment = collect($dashboard['editable_plans'] ?? [])
                ->contains(fn ($plan) =>
                    (int) $plan->id === (int) $selectedPlan->id
                );
        }

        return view('github_workflow.index', [
            ...$dashboard,
            'can_repository_inspect' => (bool) data_get(
                $integrationStatus,
                'evidence.allowed',
                false,
            ),
            'can_repository_write' => (bool) data_get(
                $integrationStatus,
                'write.allowed',
                false,
            ),
            'github_write_configured' => (bool) data_get(
                $integrationStatus,
                'runtime.app_configured',
                false,
            ),
            'github_app_connect_available' => (bool) data_get(
                $integrationStatus,
                'runtime.interactive_connect_configured',
                false,
            ),
            'github_integration_status' => $integrationStatus,
            'development_action' => $developmentAction,
            'development_focus_task' => $developmentFocusTask,
            'intelligencePresentation' => $intelligencePresentation,
            'intelligenceHistory' => $intelligenceHistory,
            'can_edit_development_readiness' => $canEditDevelopment,
        ]);
    }

    public function store(
        Request $request,
        GitHubWorkflowService $workflow,
        PlanOwnershipService $ownership,
        PlanActivityService $activity,
        BehaviorIdentityService $identity,
        TaskEvidenceService $evidence,
        FeatureAccessService $featureAccess,
        GitHubRepositoryInspector $repositoryInspector,
    ) {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'min:1'],
            'url' => ['required', 'url', 'max:2048'],
            'title' => ['nullable', 'string', 'max:255'],
            'workflow_state' => [
                'nullable',
                Rule::in(array_keys(PlanArtifact::GITHUB_WORKFLOW_STATES)),
            ],
            'task_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $planId = (int) $validated['plan_id'];
        $plan = $ownership->ownedPlans($request, [
            'tasks',
            'user:id,name,email',
            'memberships.user:id,name,email',
        ])->firstWhere('id', $planId);

        if (! $plan || ! $ownership->canEdit($request, $plan)) {
            throw ValidationException::withMessages([
                'plan_id' => 'このPlanへGitHub項目を追加する権限がありません。',
            ]);
        }

        $parsed = $workflow->parseUrl((string) $validated['url']);
        if (! ($parsed['valid'] ?? false)) {
            throw ValidationException::withMessages([
                'url' => 'github.com のRepository / PR / Issue等のURLを貼ってください。',
            ]);
        }

        $task = null;
        if (! empty($validated['task_id'])) {
            $task = $plan->tasks->firstWhere('id', (int) $validated['task_id']);
            if (! $task instanceof Task) {
                throw ValidationException::withMessages([
                    'task_id' => '選択したPlanのTaskを選んでください。',
                ]);
            }
        }

        $title = trim((string) ($validated['title'] ?? ''));
        if ($title === '') {
            $title = $workflow->suggestedTitle((string) $validated['url']);
        }

        $isRepository = ($parsed['kind'] ?? null) === 'repository';
        $workflowState = $isRepository
            ? null
            : trim((string) ($validated['workflow_state'] ?? 'now'));

        $artifact = $plan->artifacts()->create([
            'created_by_user_id' => $request->user()?->id,
            'assigned_user_id' => null,
            'provider' => 'github',
            'artifact_type' => $isRepository ? 'repository' : 'link',
            'title' => $title,
            'url' => (string) $validated['url'],
            'metadata' => $workflowState !== ''
                && $workflowState !== null
                    ? ['github_workflow_state' => $workflowState]
                    : null,
        ]);

        if ($task) {
            $artifact->tasks()->sync([(int) $task->id]);
        }

        $artifact->load('tasks');

        $activity->record(
            $plan,
            $request->user(),
            'artifact_created',
            'plan_artifact',
            (int) $artifact->id,
            ['artifact_title' => $artifact->title],
        );

        $evidence->recordArtifactState(
            $artifact,
            'created',
            userId: $request->user()?->id,
            actorToken: $identity->resolve($request),
        );

        $inspectionMessage = null;
        if (
            $isRepository
            && $featureAccess->canUse($request->user(), FeatureKey::DeveloperGithubEvidence)
            && filled($parsed['repo_full_name'] ?? null)
        ) {
            try {
                $snapshot = $repositoryInspector->inspect((string) $parsed['repo_full_name']);
                $metadata = is_array($artifact->metadata) ? $artifact->metadata : [];
                $metadata['github_repository_snapshot'] = $snapshot;
                $artifact->update(['metadata' => $metadata]);
                $inspectionMessage = 'Public Repository Previewを読み込みました。GitHub App接続後は同じ画面でauthoritative同期へ切り替わります。';
            } catch (\RuntimeException $exception) {
                // Repository capture must remain fail-open. The URL is still a
                // valid Canovia root even when remote inspection is unavailable.
                $inspectionMessage = $exception->getMessage();
            }
        }

        $redirect = redirect()
            ->route('github_workflow.index', ['plan_id' => $plan->id])
            ->with(
                'success',
                $isRepository
                    ? 'Repositoryを全体像としてCanoviaへ追加しました。'
                    : 'GitHub項目をCanoviaへ追加しました。',
            );

        return $inspectionMessage
            ? $redirect->with('status', $inspectionMessage)
            : $redirect;
    }

    public function refreshRepository(
        Request $request,
        PlanArtifact $artifact,
        PlanOwnershipService $ownership,
        FeatureAccessService $featureAccess,
        GitHubWorkflowService $workflow,
        GitHubRepositoryInspector $repositoryInspector,
        GitHubRepositoryWriter $repositoryWriter,
        PlanActivityService $activity,
    ) {
        $artifact->loadMissing('plan');
        $plan = $artifact->plan;

        abort_unless($plan && $artifact->provider === 'github', 404);
        $ownership->authorizeEdit($request, $plan);
        if ($blocked = $this->capabilityRedirect(
            $request,
            $featureAccess,
            FeatureKey::DeveloperGithubEvidence,
            (int) $plan->id,
            [
                'plan_id' => (int) $plan->id,
                'artifact_id' => (int) $artifact->id,
            ],
            'Developer GitHub Evidence',
        )) {
            return $blocked;
        }

        $parsed = $workflow->parseUrl((string) $artifact->url);
        abort_unless(($parsed['kind'] ?? null) === 'repository', 404);

        try {
            $connected = (string) data_get(
                $artifact->metadata,
                'github_app_connection.status',
                '',
            ) === 'connected';

            $snapshot = $connected
                ? $repositoryWriter->inspectRepositorySnapshot(
                    (string) $parsed['repo_full_name'],
                )
                : $repositoryInspector->inspect(
                    (string) $parsed['repo_full_name'],
                );
        } catch (\RuntimeException $exception) {
            return redirect()
                ->route('github_workflow.index', ['plan_id' => $plan->id])
                ->with('status', $exception->getMessage());
        }

        $metadata = is_array($artifact->metadata) ? $artifact->metadata : [];
        $metadata['github_repository_snapshot'] = $snapshot;

        // A repository is a navigation root, not a workflow item.
        unset($metadata['github_workflow_state']);

        $artifact->update([
            'metadata' => $metadata,
        ]);

        $activity->record(
            $plan,
            $request->user(),
            'github_repository_snapshot_refreshed',
            'plan_artifact',
            (int) $artifact->id,
            [
                'repo_full_name' => (string) $parsed['repo_full_name'],
                'fetched_at' => data_get($snapshot, 'fetched_at'),
            ],
        );

        return redirect()
            ->route('github_workflow.index', ['plan_id' => $plan->id])
            ->with('success', 'GitHubからRepositoryの現在構造を更新しました。');
    }

    public function beginRepositoryConnection(
        Request $request,
        PlanArtifact $artifact,
        PlanOwnershipService $ownership,
        FeatureAccessService $featureAccess,
        GitHubWorkflowService $workflow,
        GitHubRepositoryWriter $repositoryWriter,
        PlanActivityService $activity,
    ) {
        $artifact->loadMissing('plan');
        $plan = $artifact->plan;

        abort_unless($plan && $artifact->provider === 'github', 404);
        $ownership->authorizeEdit($request, $plan);
        if ($blocked = $this->capabilityRedirect(
            $request,
            $featureAccess,
            FeatureKey::DeveloperGithubEvidence,
            (int) $plan->id,
            [
                'plan_id' => (int) $plan->id,
                'artifact_id' => (int) $artifact->id,
            ],
            'Developer GitHub Evidence',
        )) {
            return $blocked;
        }

        $parsed = $workflow->parseUrl((string) $artifact->url);
        abort_unless(($parsed['kind'] ?? null) === 'repository', 404);

        if (! $repositoryWriter->configured()) {
            return redirect()
                ->route('github_workflow.index', ['plan_id' => $plan->id])
                ->with('status', 'Canovia運営側のGitHub App設定がまだ完了していません。');
        }

        $state = bin2hex(random_bytes(32));
        $installUrl = $repositoryWriter->installUrlForState($state);

        if ($installUrl === null) {
            return redirect()
                ->route('github_workflow.index', ['plan_id' => $plan->id])
                ->with('status', 'GitHub Appの接続URLがCanoviaに設定されていません。');
        }

        $request->session()->put($this->githubInstallStateKey($state), [
            'artifact_id' => (int) $artifact->id,
            'plan_id' => (int) $plan->id,
            'user_id' => $request->user()?->id,
            'repo_full_name' => (string) $parsed['repo_full_name'],
            'expires_at' => now()->addMinutes(15)->timestamp,
        ]);

        $metadata = is_array($artifact->metadata) ? $artifact->metadata : [];
        $metadata['github_app_connection'] = [
            'status' => 'connecting',
            'requested_by_user_id' => $request->user()?->id,
            'requested_at' => now()->toIso8601String(),
            'last_checked_at' => null,
        ];
        unset($metadata['github_workflow_state']);

        $artifact->update(['metadata' => $metadata]);

        $activity->record(
            $plan,
            $request->user(),
            'github_app_connection_started',
            'plan_artifact',
            (int) $artifact->id,
            [
                'repo_full_name' => (string) $parsed['repo_full_name'],
            ],
        );

        return redirect()->away($installUrl);
    }

    public function completeRepositoryConnection(
        Request $request,
        PlanOwnershipService $ownership,
        FeatureAccessService $featureAccess,
        GitHubWorkflowService $workflow,
        GitHubRepositoryWriter $repositoryWriter,
        PlanActivityService $activity,
    ) {
        $state = trim((string) $request->query('state', ''));
        if (! preg_match('/^[a-f0-9]{64}$/', $state)) {
            return redirect()
                ->route('github_workflow.index')
                ->with('status', 'GitHub接続の確認情報がありません。Canoviaから「GitHubを接続」をやり直してください。');
        }

        $pending = $request->session()->pull($this->githubInstallStateKey($state));
        if (! is_array($pending)) {
            return redirect()
                ->route('github_workflow.index')
                ->with('status', 'GitHub接続の確認期限が切れているか、すでに確認済みです。もう一度接続を開始してください。');
        }

        if (
            (int) ($pending['expires_at'] ?? 0) < now()->timestamp
            || (int) ($pending['user_id'] ?? 0) !== (int) ($request->user()?->id ?? 0)
        ) {
            return redirect()
                ->route('github_workflow.index')
                ->with('status', 'GitHub接続の確認情報が一致しません。もう一度接続を開始してください。');
        }

        $artifact = PlanArtifact::query()->find((int) ($pending['artifact_id'] ?? 0));
        if (! $artifact instanceof PlanArtifact) {
            return redirect()
                ->route('github_workflow.index')
                ->with('status', '接続対象のRepositoryがCanoviaに見つかりませんでした。');
        }

        $artifact->loadMissing('plan');
        $plan = $artifact->plan;
        abort_unless($plan && $artifact->provider === 'github', 404);

        $ownership->authorizeEdit($request, $plan);
        if ($blocked = $this->capabilityRedirect(
            $request,
            $featureAccess,
            FeatureKey::DeveloperGithubEvidence,
            (int) $plan->id,
            [
                'plan_id' => (int) $plan->id,
                'artifact_id' => (int) $artifact->id,
            ],
            'Developer GitHub Evidence',
        )) {
            return $blocked;
        }

        $parsed = $workflow->parseUrl((string) $artifact->url);
        abort_unless(($parsed['kind'] ?? null) === 'repository', 404);

        if ((string) ($pending['repo_full_name'] ?? '') !== (string) ($parsed['repo_full_name'] ?? '')) {
            return redirect()
                ->route('github_workflow.index', ['plan_id' => $plan->id])
                ->with('status', '接続対象Repositoryの確認に失敗しました。もう一度接続を開始してください。');
        }

        $installationId = filter_var(
            $request->query('installation_id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $setupAction = preg_match('/^[a-z_]{1,40}$/', (string) $request->query('setup_action', ''))
            ? (string) $request->query('setup_action')
            : null;

        if (! is_int($installationId)) {
            $this->storeRepositoryConnection($artifact, [
                'status' => 'pending',
                'requested_by_user_id' => $request->user()?->id,
                'requested_at' => data_get($artifact->metadata, 'github_app_connection.requested_at'),
                'last_checked_at' => now()->toIso8601String(),
                'setup_action' => $setupAction,
            ]);

            return redirect()
                ->route('github_workflow.index', ['plan_id' => $plan->id])
                ->with('status', 'GitHub側でまだInstallationを確認できません。OrganizationではOwner承認待ちの可能性があります。');
        }

        try {
            $installation = $repositoryWriter->verifyRepositoryInstallation(
                (string) $parsed['repo_full_name'],
                $installationId,
            );
        } catch (\RuntimeException $exception) {
            $this->storeRepositoryConnection($artifact, [
                'status' => 'verification_failed',
                'requested_by_user_id' => $request->user()?->id,
                'requested_at' => data_get($artifact->metadata, 'github_app_connection.requested_at'),
                'last_checked_at' => now()->toIso8601String(),
                'setup_action' => $setupAction,
            ]);

            return redirect()
                ->route('github_workflow.index', ['plan_id' => $plan->id])
                ->with('status', $exception->getMessage());
        }

        $connection = $this->connectionFromInstallation(
            $installation,
            $request->user()?->id,
            $setupAction,
        );
        $this->storeRepositoryConnection($artifact, $connection);

        $activity->record(
            $plan,
            $request->user(),
            'github_app_connection_verified',
            'plan_artifact',
            (int) $artifact->id,
            [
                'repo_full_name' => (string) $parsed['repo_full_name'],
                'status' => (string) $connection['status'],
                'installation_id' => (int) ($connection['installation_id'] ?? 0),
                'target_type' => (string) ($connection['target_type'] ?? ''),
                'account_login' => (string) ($connection['account_login'] ?? ''),
            ],
        );

        if (
            $connection['status'] === 'connected'
            && (string) config('queue.default') !== 'sync'
        ) {
            BootstrapGitHubDevelopmentActivity::dispatch((int) $artifact->id);
        }

        return redirect()
            ->route('github_workflow.index', ['plan_id' => $plan->id])
            ->with(
                $connection['status'] === 'connected' ? 'success' : 'status',
                $connection['status'] === 'connected'
                    ? 'GitHub Repositoryとの接続を確認しました。Public / Privateを問わずGitHub App経由で同期できます。'
                    : 'GitHub Appは確認できましたが、Contents / Pull Requestsのread権限が必要です。',
            );
    }

    public function checkRepositoryConnection(
        Request $request,
        PlanArtifact $artifact,
        PlanOwnershipService $ownership,
        FeatureAccessService $featureAccess,
        GitHubWorkflowService $workflow,
        GitHubRepositoryWriter $repositoryWriter,
    ) {
        $artifact->loadMissing('plan');
        $plan = $artifact->plan;

        abort_unless($plan && $artifact->provider === 'github', 404);
        $ownership->authorizeEdit($request, $plan);
        if ($blocked = $this->capabilityRedirect(
            $request,
            $featureAccess,
            FeatureKey::DeveloperGithubEvidence,
            (int) $plan->id,
            [
                'plan_id' => (int) $plan->id,
                'artifact_id' => (int) $artifact->id,
            ],
            'Developer GitHub Evidence',
        )) {
            return $blocked;
        }

        $parsed = $workflow->parseUrl((string) $artifact->url);
        abort_unless(($parsed['kind'] ?? null) === 'repository', 404);

        try {
            $installation = $repositoryWriter->repositoryInstallation(
                (string) $parsed['repo_full_name'],
            );
        } catch (\RuntimeException $exception) {
            return redirect()
                ->route('github_workflow.index', ['plan_id' => $plan->id])
                ->with('status', $exception->getMessage());
        }

        if ($installation === null) {
            $previousStatus = (string) data_get($artifact->metadata, 'github_app_connection.status', '');
            $this->storeRepositoryConnection($artifact, [
                'status' => $previousStatus === 'connected' ? 'revoked' : 'pending',
                'requested_by_user_id' => data_get($artifact->metadata, 'github_app_connection.requested_by_user_id'),
                'requested_at' => data_get($artifact->metadata, 'github_app_connection.requested_at'),
                'last_checked_at' => now()->toIso8601String(),
            ]);

            return redirect()
                ->route('github_workflow.index', ['plan_id' => $plan->id])
                ->with(
                    'status',
                    $previousStatus === 'connected'
                        ? 'GitHub App接続を現在確認できません。Repository側でAppが削除された可能性があります。'
                        : 'GitHub AppはまだこのRepositoryへ接続されていません。OrganizationではOwner承認待ちの可能性があります。',
                );
        }

        $connection = $this->connectionFromInstallation(
            $installation,
            $request->user()?->id,
            null,
        );
        $this->storeRepositoryConnection($artifact, $connection);

        if (
            $connection['status'] === 'connected'
            && (string) config('queue.default') !== 'sync'
        ) {
            BootstrapGitHubDevelopmentActivity::dispatch((int) $artifact->id);
        }

        return redirect()
            ->route('github_workflow.index', ['plan_id' => $plan->id])
            ->with(
                $connection['status'] === 'connected' ? 'success' : 'status',
                $connection['status'] === 'connected'
                    ? 'GitHub Appの接続状態を確認しました。'
                    : 'GitHub Appは存在しますが、必要なread権限を確認できません。',
            );
    }

    public function proposeRepositoryChange(
        Request $request,
        PlanArtifact $artifact,
        PlanOwnershipService $ownership,
        FeatureAccessService $featureAccess,
        GitHubWorkflowService $workflow,
        GitHubRepositoryWriter $repositoryWriter,
        PlanActivityService $activity,
    ) {
        $artifact->loadMissing('plan');
        $plan = $artifact->plan;

        abort_unless($plan && $artifact->provider === 'github', 404);
        $ownership->authorizeEdit($request, $plan);
        if ($blocked = $this->capabilityRedirect(
            $request,
            $featureAccess,
            FeatureKey::DeveloperGithubWrite,
            (int) $plan->id,
            [
                'plan_id' => (int) $plan->id,
                'artifact_id' => (int) $artifact->id,
            ],
            'Developer GitHub Write',
        )) {
            return $blocked;
        }

        $parsed = $workflow->parseUrl((string) $artifact->url);
        abort_unless(($parsed['kind'] ?? null) === 'repository', 404);

        $validated = $request->validate([
            'file_path' => ['required', 'string', 'max:240'],
            'file_content' => ['required', 'string', 'max:200000'],
            'commit_message' => ['required', 'string', 'max:240'],
            'pull_request_title' => ['required', 'string', 'max:240'],
            'pull_request_body' => ['nullable', 'string', 'max:20000'],
        ]);

        try {
            $result = $repositoryWriter->proposeFileChange(
                (string) $parsed['repo_full_name'],
                (string) $validated['file_path'],
                (string) $validated['file_content'],
                (string) $validated['commit_message'],
                (string) $validated['pull_request_title'],
                $validated['pull_request_body'] ?? null,
            );
        } catch (\RuntimeException $exception) {
            return redirect()
                ->route('github_workflow.index', ['plan_id' => $plan->id])
                ->withInput()
                ->with('status', $exception->getMessage());
        }

        $pullRequest = (array) ($result['pull_request'] ?? []);
        $pullRequestNumber = (int) ($pullRequest['number'] ?? 0);
        $pullRequestUrl = (string) ($pullRequest['url'] ?? '');

        $prArtifact = $plan->artifacts()->create([
            'created_by_user_id' => $request->user()?->id,
            'assigned_user_id' => null,
            'provider' => 'github',
            'artifact_type' => 'link',
            'title' => $pullRequestNumber > 0
                ? 'PR #'.$pullRequestNumber.' · '.(string) ($pullRequest['title'] ?? $validated['pull_request_title'])
                : (string) $validated['pull_request_title'],
            'url' => $pullRequestUrl,
            'external_id' => $pullRequestNumber > 0 ? (string) $pullRequestNumber : null,
            'metadata' => [
                'github_workflow_state' => 'review',
                'github_write_origin' => [
                    'repository_artifact_id' => (int) $artifact->id,
                    'requested_by_user_id' => $request->user()?->id,
                    'executed_by' => 'github_app',
                    'base_branch' => (string) ($result['base_branch'] ?? ''),
                    'branch' => (string) ($result['branch'] ?? ''),
                    'file_path' => (string) ($result['file_path'] ?? ''),
                    'file_action' => (string) ($result['file_action'] ?? ''),
                    'commit_sha' => (string) ($result['commit_sha'] ?? ''),
                    'created_at' => (string) ($result['created_at'] ?? now()->toIso8601String()),
                ],
            ],
        ]);

        $repositoryMetadata = is_array($artifact->metadata) ? $artifact->metadata : [];
        $repositoryMetadata['github_last_write'] = [
            'requested_by_user_id' => $request->user()?->id,
            'executed_by' => 'github_app',
            'branch' => (string) ($result['branch'] ?? ''),
            'base_branch' => (string) ($result['base_branch'] ?? ''),
            'file_path' => (string) ($result['file_path'] ?? ''),
            'file_action' => (string) ($result['file_action'] ?? ''),
            'commit_sha' => (string) ($result['commit_sha'] ?? ''),
            'pull_request_number' => $pullRequestNumber,
            'pull_request_url' => $pullRequestUrl,
            'created_at' => (string) ($result['created_at'] ?? now()->toIso8601String()),
        ];
        unset($repositoryMetadata['github_workflow_state']);

        $artifact->update([
            'metadata' => $repositoryMetadata,
        ]);

        $activity->record(
            $plan,
            $request->user(),
            'github_repository_change_proposed',
            'plan_artifact',
            (int) $prArtifact->id,
            [
                'repository_artifact_id' => (int) $artifact->id,
                'repo_full_name' => (string) $parsed['repo_full_name'],
                'branch' => (string) ($result['branch'] ?? ''),
                'base_branch' => (string) ($result['base_branch'] ?? ''),
                'file_path' => (string) ($result['file_path'] ?? ''),
                'commit_sha' => (string) ($result['commit_sha'] ?? ''),
                'pull_request_number' => $pullRequestNumber,
            ],
        );

        return redirect()
            ->to(route('github_workflow.index', ['plan_id' => $plan->id]).'#github-item-'.$prArtifact->id)
            ->with('success', '変更をレビュー用Pull RequestとしてGitHubへ反映しました。mainにはまだ反映されていません。');
    }

    public function updateState(
        Request $request,
        PlanArtifact $artifact,
        PlanOwnershipService $ownership,
        PlanActivityService $activity,
    ) {
        $artifact->loadMissing('plan');
        $plan = $artifact->plan;

        abort_unless($plan && $artifact->provider === 'github', 404);
        $ownership->authorizeEdit($request, $plan);

        $validated = $request->validate([
            'workflow_state' => [
                'nullable',
                Rule::in(array_keys(PlanArtifact::GITHUB_WORKFLOW_STATES)),
            ],
        ]);

        $oldState = $artifact->githubWorkflowState();
        $newState = isset($validated['workflow_state'])
            ? trim((string) $validated['workflow_state'])
            : '';

        $metadata = is_array($artifact->metadata) ? $artifact->metadata : [];

        if ($newState !== '') {
            $metadata['github_workflow_state'] = $newState;
        } else {
            unset($metadata['github_workflow_state']);
        }

        $artifact->update([
            'metadata' => $metadata === [] ? null : $metadata,
        ]);

        $activity->record(
            $plan,
            $request->user(),
            'github_workflow_state_updated',
            'plan_artifact',
            (int) $artifact->id,
            [
                'from' => $oldState,
                'to' => $newState !== '' ? $newState : null,
            ],
        );

        return redirect()
            ->to($this->safeReturnUrl($request, $plan->id))
            ->with('success', 'GitHub項目のCanovia状態を更新しました。');
    }

    /**
     * Keep web-form capability denial fail-closed without dropping users onto
     * an opaque 403 page.
     *
     * @param array<string,mixed> $context
     */
    private function capabilityRedirect(
        Request $request,
        FeatureAccessService $featureAccess,
        FeatureKey $feature,
        int $planId,
        array $context,
        string $label,
    ): ?RedirectResponse {
        $decision = $featureAccess->resolveAccess(
            $request->user(),
            $feature,
            $context,
        );

        if ($decision->allowed) {
            return null;
        }

        return redirect()
            ->route(
                'github_workflow.index',
                ['plan_id' => $planId],
            )
            ->with(
                'status',
                $this->githubReadiness->deniedMessage(
                    $decision,
                    $label,
                ),
            );
    }

    private function githubInstallStateKey(string $state): string
    {
        return 'github_app_install_state.'.hash('sha256', $state);
    }

    /**
     * @param array<string,mixed> $installation
     * @return array<string,mixed>
     */
    private function connectionFromInstallation(
        array $installation,
        ?int $requestedByUserId,
        ?string $setupAction,
    ): array {
        $permissions = is_array($installation['permissions'] ?? null)
            ? $installation['permissions']
            : [];
        $readReady = in_array(
            $permissions['contents'] ?? null,
            ['read', 'write'],
            true,
        ) && in_array(
            $permissions['pull_requests'] ?? null,
            ['read', 'write'],
            true,
        );
        $writeReady = ($permissions['contents'] ?? null) === 'write'
            && ($permissions['pull_requests'] ?? null) === 'write';

        return [
            'status' => $readReady ? 'connected' : 'permission_update_required',
            'installation_id' => (int) ($installation['installation_id'] ?? 0),
            'account_login' => (string) ($installation['account_login'] ?? ''),
            'account_type' => (string) ($installation['account_type'] ?? ''),
            'target_type' => (string) ($installation['target_type'] ?? ''),
            'repository_selection' => (string) ($installation['repository_selection'] ?? ''),
            'permissions' => $permissions,
            'read_ready' => $readReady,
            'write_ready' => $writeReady,
            'management_url' => $installation['management_url'] ?? null,
            'requested_by_user_id' => $requestedByUserId,
            'requested_at' => now()->toIso8601String(),
            'connected_at' => $readReady ? now()->toIso8601String() : null,
            'last_checked_at' => now()->toIso8601String(),
            'setup_action' => $setupAction,
        ];
    }

    /**
     * @param array<string,mixed> $connection
     */
    private function storeRepositoryConnection(
        PlanArtifact $artifact,
        array $connection,
    ): void {
        $metadata = is_array($artifact->metadata) ? $artifact->metadata : [];
        $metadata['github_app_connection'] = $connection;
        unset($metadata['github_workflow_state']);

        $artifact->update(['metadata' => $metadata]);
    }

    private function safeReturnUrl(Request $request, int $planId): string
    {
        $selectedPlanId = max(0, (int) $request->input('return_plan_id', 0));

        return route(
            'github_workflow.index',
            $selectedPlanId === $planId ? ['plan_id' => $planId] : [],
        );
    }
}
