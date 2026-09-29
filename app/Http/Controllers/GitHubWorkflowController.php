<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Services\BehaviorIdentityService;
use App\Services\FeatureAccessService;
use App\Services\GitHubRepositoryInspector;
use App\Services\GitHubRepositoryWriter;
use App\Services\GitHubWorkflowService;
use App\Services\PlanActivityService;
use App\Services\PlanOwnershipService;
use App\Services\TaskEvidenceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class GitHubWorkflowController extends Controller
{
    public function index(
        Request $request,
        GitHubWorkflowService $workflow,
        FeatureAccessService $featureAccess,
        GitHubRepositoryWriter $repositoryWriter,
    ) {
        return view('github_workflow.index', [
            ...$workflow->dashboard($request),
            'can_repository_inspect' => $featureAccess->canUse(
                $request->user(),
                FeatureKey::DeveloperGithubEvidence,
            ),
            'can_repository_write' => $featureAccess->canUse(
                $request->user(),
                FeatureKey::DeveloperGithubWrite,
            ),
            'github_write_configured' => $repositoryWriter->configured(),
            'github_app_install_url' => $repositoryWriter->installUrl(),
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
                $inspectionMessage = 'GitHubから現在のRepository構造も読み込みました。';
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
        PlanActivityService $activity,
    ) {
        $artifact->loadMissing('plan');
        $plan = $artifact->plan;

        abort_unless($plan && $artifact->provider === 'github', 404);
        $ownership->authorizeEdit($request, $plan);
        $featureAccess->authorizeUse(
            $request->user(),
            FeatureKey::DeveloperGithubEvidence,
            ['plan_id' => (int) $plan->id, 'artifact_id' => (int) $artifact->id],
        );

        $parsed = $workflow->parseUrl((string) $artifact->url);
        abort_unless(($parsed['kind'] ?? null) === 'repository', 404);

        try {
            $snapshot = $repositoryInspector->inspect((string) $parsed['repo_full_name']);
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
        $featureAccess->authorizeUse(
            $request->user(),
            FeatureKey::DeveloperGithubWrite,
            ['plan_id' => (int) $plan->id, 'artifact_id' => (int) $artifact->id],
        );

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

    private function safeReturnUrl(Request $request, int $planId): string
    {
        $selectedPlanId = max(0, (int) $request->input('return_plan_id', 0));

        return route(
            'github_workflow.index',
            $selectedPlanId === $planId ? ['plan_id' => $planId] : [],
        );
    }
}
