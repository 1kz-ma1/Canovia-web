<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Intelligence\Development\DevelopmentAdaptiveActionService;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Services\ExecutionGitHubHandoffService;
use App\Services\ExecutionOrchestrationContextService;
use App\Services\ExecutionRequestHandoffService;
use App\Services\FeatureAccessService;
use App\Services\GitHubEvidenceDecisionService;
use App\Services\GitHubIntegrationReadinessService;
use App\Services\GitHubRepositoryWriter;
use App\Services\GitHubReturnEvidenceService;
use App\Services\PlanActivityService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ExecutionGitHubHandoffController extends Controller
{
    public function __construct(
        private readonly GitHubIntegrationReadinessService $githubReadiness,
    ) {}
    public function prepare(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        FeatureAccessService $access,
        ExecutionGitHubHandoffService $handoff,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        if ($blocked = $this->capabilityRedirect(
            $request,
            $access,
            FeatureKey::DeveloperGithubWrite,
            $plan,
            $task,
            [
                'plan_id' => (int) $plan->id,
                'task_id' => (int) $task->id,
            ],
            'Developer GitHub Write',
        )) {
            return $blocked;
        }

        $validated = $request->validate([
            'repository_artifact_id' => ['required', 'integer', 'min:1'],
            'file_path' => ['required', 'string', 'max:240'],
            'file_content' => ['required', 'string', 'max:200000'],
            'commit_message' => ['required', 'string', 'max:240'],
            'pull_request_title' => ['required', 'string', 'max:240'],
            'pull_request_body' => ['nullable', 'string', 'max:20000'],
        ]);

        $repository = PlanArtifact::query()
            ->whereKey((int) $validated['repository_artifact_id'])
            ->where('plan_id', $plan->id)
            ->where('provider', 'github')
            ->where('artifact_type', 'repository')
            ->first();

        if (! $repository instanceof PlanArtifact) {
            throw ValidationException::withMessages([
                'repository_artifact_id' => 'このPlanに登録されたGitHub Repositoryを選んでください。',
            ]);
        }

        try {
            $handoff->prepare(
                request: $request,
                plan: $plan,
                task: $task,
                repository: $repository,
                filePath: (string) $validated['file_path'],
                content: (string) $validated['file_content'],
                commitMessage: (string) $validated['commit_message'],
                pullRequestTitle: (string) $validated['pull_request_title'],
                pullRequestBody: $validated['pull_request_body'] ?? null,
                user: $request->user(),
            );
        } catch (ValidationException $exception) {
            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->withInput()
                ->withErrors($exception->errors());
        } catch (\RuntimeException $exception) {
            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->withInput()
                ->with('status', $exception->getMessage());
        }

        return redirect()
            ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
            ->with('success', 'GitHubへ送る変更候補を準備しました。内容を確認してからレビューに出してください。');
    }

    public function confirm(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        FeatureAccessService $access,
        ExecutionGitHubHandoffService $handoff,
        ExecutionOrchestrationContextService $contexts,
        GitHubRepositoryWriter $writer,
        PlanActivityService $activity,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        if ($blocked = $this->capabilityRedirect(
            $request,
            $access,
            FeatureKey::DeveloperGithubWrite,
            $plan,
            $task,
            [
                'plan_id' => (int) $plan->id,
                'task_id' => (int) $task->id,
            ],
            'Developer GitHub Write',
        )) {
            return $blocked;
        }

        $candidate = $handoff->candidate($request, $plan, $task);
        if (! $candidate) {
            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with('status', '確認できるGitHub変更候補がありません。現在の成果からもう一度準備してください。');
        }

        if (
            (int) data_get($candidate, 'target_plan.id') !== (int) $plan->id
            || (int) data_get($candidate, 'target_task.id') !== (int) $task->id
        ) {
            $handoff->clear($request, $plan, $task);

            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with('status', '変更候補の対象Taskが現在のExecution Contextと一致しません。作り直してください。');
        }

        $context = $contexts->snapshot($request, $plan, $task);
        $candidateFingerprint = (string) ($candidate['context_fingerprint'] ?? '');

        if (
            $candidateFingerprint === ''
            || ! hash_equals((string) $context['context_fingerprint'], $candidateFingerprint)
        ) {
            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with('status', '変更候補を確認した後にPlan / TaskのContextが変わっています。現在Contextから候補を作り直してください。');
        }

        $orchestrationState = $request->session()->get(
            ExecutionRequestHandoffService::sessionKey($plan, $task),
            [],
        );
        $activePacket = is_array($orchestrationState['packet'] ?? null)
            ? $orchestrationState['packet']
            : null;
        $activePacketJson = $activePacket
            ? json_encode($activePacket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        $activePacketHash = is_string($activePacketJson)
            ? hash('sha256', $activePacketJson)
            : '';
        $candidatePacketHash = (string) data_get($candidate, 'source.packet_hash', '');

        if (
            $candidatePacketHash === ''
            || $activePacketHash === ''
            || ! hash_equals($candidatePacketHash, $activePacketHash)
        ) {
            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with('status', '変更候補の元になったExecution Packetが変わっています。現在のPacketから候補を作り直してください。');
        }

        $repository = PlanArtifact::query()
            ->whereKey((int) data_get($candidate, 'repository.artifact_id'))
            ->where('plan_id', $plan->id)
            ->where('provider', 'github')
            ->where('artifact_type', 'repository')
            ->first();

        if (! $repository instanceof PlanArtifact) {
            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with('status', '変更候補のGitHub Repositoryが現在のPlanに見つかりません。');
        }

        if (
            (string) $repository->url !== (string) data_get($candidate, 'repository.url', '')
        ) {
            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with('status', '確認後にRepository URLが変更されています。現在のRepositoryから候補を作り直してください。');
        }

        $repoFullName = (string) data_get($candidate, 'repository.repo_full_name', '');
        $change = (array) ($candidate['change'] ?? []);
        $preview = (array) ($candidate['preview'] ?? []);

        if (
            $repoFullName === ''
            || trim((string) ($change['file_path'] ?? '')) === ''
            || ! array_key_exists('content', $change)
        ) {
            $handoff->clear($request, $plan, $task);

            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with('status', 'GitHub変更候補の内容を確認できません。作り直してください。');
        }

        try {
            $result = $writer->proposeFileChange(
                repoFullName: $repoFullName,
                filePath: (string) $change['file_path'],
                content: (string) $change['content'],
                commitMessage: (string) ($change['commit_message'] ?? ''),
                pullRequestTitle: (string) ($change['pull_request_title'] ?? ''),
                pullRequestBody: $change['pull_request_body'] ?? null,
                expectedFileSha: isset($preview['expected_file_sha'])
                    ? (string) $preview['expected_file_sha']
                    : null,
                enforceExpectedFileState: true,
            );
        } catch (\RuntimeException $exception) {
            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with('status', $exception->getMessage());
        }

        $pullRequest = (array) ($result['pull_request'] ?? []);
        $pullRequestNumber = (int) ($pullRequest['number'] ?? 0);
        $pullRequestUrl = trim((string) ($pullRequest['url'] ?? ''));

        if ($pullRequestNumber <= 0 || $pullRequestUrl === '') {
            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with('status', 'GitHub側でPull Request結果を確認できませんでした。Repositoryを確認してください。');
        }

        $prArtifact = $plan->artifacts()->create([
            'created_by_user_id' => $request->user()?->id,
            'assigned_user_id' => null,
            'provider' => 'github',
            'artifact_type' => 'link',
            'title' => 'PR #'.$pullRequestNumber.' · '.(string) ($pullRequest['title'] ?? $change['pull_request_title']),
            'url' => $pullRequestUrl,
            'external_id' => (string) $pullRequestNumber,
            'metadata' => [
                'github_workflow_state' => 'review',
                'github_write_origin' => [
                    'repository_artifact_id' => (int) $repository->id,
                    'requested_by_user_id' => $request->user()?->id,
                    'executed_by' => 'github_app',
                    'source' => 'execution_github_handoff',
                    'target_task_id' => (int) $task->id,
                    'context_fingerprint' => $candidateFingerprint,
                    'base_branch' => (string) ($result['base_branch'] ?? ''),
                    'branch' => (string) ($result['branch'] ?? ''),
                    'file_path' => (string) ($result['file_path'] ?? ''),
                    'file_action' => (string) ($result['file_action'] ?? ''),
                    'commit_sha' => (string) ($result['commit_sha'] ?? ''),
                    'created_at' => (string) ($result['created_at'] ?? now()->toIso8601String()),
                ],
            ],
        ]);

        $prArtifact->tasks()->syncWithoutDetaching([(int) $task->id]);

        $repositoryMetadata = is_array($repository->metadata) ? $repository->metadata : [];
        $repositoryMetadata['github_last_write'] = [
            'requested_by_user_id' => $request->user()?->id,
            'executed_by' => 'github_app',
            'source' => 'execution_github_handoff',
            'target_task_id' => (int) $task->id,
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
        $repository->update(['metadata' => $repositoryMetadata]);

        $activity->record(
            $plan,
            $request->user(),
            'execution_github_change_confirmed',
            'plan_artifact',
            (int) $prArtifact->id,
            [
                'task_id' => (int) $task->id,
                'repository_artifact_id' => (int) $repository->id,
                'repo_full_name' => $repoFullName,
                'file_path' => (string) ($result['file_path'] ?? ''),
                'branch' => (string) ($result['branch'] ?? ''),
                'commit_sha' => (string) ($result['commit_sha'] ?? ''),
                'pull_request_number' => $pullRequestNumber,
            ],
        );

        $orchestrationKey = ExecutionRequestHandoffService::sessionKey($plan, $task);
        $orchestrationState = $request->session()->get($orchestrationKey, []);
        if (! is_array($orchestrationState)) {
            $orchestrationState = [];
        }

        $orchestrationState['github_handoff_result'] = [
            'repository_artifact_id' => (int) $repository->id,
            'repo_full_name' => $repoFullName,
            'file_path' => (string) ($result['file_path'] ?? ''),
            'branch' => (string) ($result['branch'] ?? ''),
            'commit_sha' => (string) ($result['commit_sha'] ?? ''),
            'pull_request_number' => $pullRequestNumber,
            'pull_request_url' => $pullRequestUrl,
            'confirmed_by_user_id' => $request->user()?->id,
            'confirmed_at' => now()->toIso8601String(),
        ];
        $request->session()->put($orchestrationKey, $orchestrationState);

        $handoff->clear($request, $plan, $task);

        return redirect()
            ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
            ->with('success', '確認した変更をGitHubのレビュー用Pull Requestへ反映しました。Task進捗はまだ変更していません。');
    }

    public function syncReturn(
        Request $request,
        Plan $plan,
        Task $task,
        PlanArtifact $artifact,
        PlanOwnershipService $ownership,
        FeatureAccessService $access,
        GitHubReturnEvidenceService $returns,
        PlanActivityService $activity,
        PlanCategoryProfileService $profiles,
        DevelopmentAdaptiveActionService $developmentActions,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        if ($blocked = $this->capabilityRedirect(
            $request,
            $access,
            FeatureKey::DeveloperGithubEvidence,
            $plan,
            $task,
            [
                'plan_id' => (int) $plan->id,
                'task_id' => (int) $task->id,
                'artifact_id' => (int) $artifact->id,
            ],
            'Developer GitHub Evidence',
        )) {
            return $blocked;
        }

        abort_unless(
            (int) $artifact->plan_id === (int) $plan->id
            && $artifact->provider === 'github',
            404,
        );

        try {
            $result = $returns->sync(
                $plan,
                $task,
                $artifact,
            );
        } catch (\RuntimeException $exception) {
            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with('status', $exception->getMessage());
        }

        $snapshot = (array) ($result['snapshot'] ?? []);
        $pull = (array) ($snapshot['pull_request'] ?? []);
        $reviewSummary = (array) ($snapshot['review_summary'] ?? []);
        $ci = (array) ($snapshot['ci'] ?? []);

        $activity->record(
            $plan,
            $request->user(),
            'github_return_synced',
            'plan_artifact',
            (int) $artifact->id,
            [
                'task_id' => (int) $task->id,
                'repo_full_name' => (string) ($snapshot['repo_full_name'] ?? ''),
                'pull_request_number' => (int) ($pull['number'] ?? 0),
                'merged' => (bool) ($pull['merged'] ?? false),
                'approved_reviewers' => (int) ($reviewSummary['approved_reviewers'] ?? 0),
                'changes_requested_reviewers' => (int) ($reviewSummary['changes_requested_reviewers'] ?? 0),
                'ci_state' => (string) ($ci['state'] ?? 'unknown'),
                'evidence_count' => (int) ($result['evidence_count'] ?? 0),
            ],
        );

        if ($profiles->forPlan($plan)->key === 'development') {
            $developmentActions->tryRefresh($plan, now());
        }

        return redirect()
            ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
            ->with(
                'success',
                'GitHubのReview / Merge / CI結果を確認し、Task Evidenceへ反映しました。Task進捗・完了状態は自動変更していません。',
            );
    }

    public function applyEvidenceDecision(
        Request $request,
        Plan $plan,
        Task $task,
        PlanArtifact $artifact,
        PlanOwnershipService $ownership,
        FeatureAccessService $access,
        GitHubReturnEvidenceService $returns,
        GitHubEvidenceDecisionService $decisions,
        PlanActivityService $activity,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        if ($blocked = $this->capabilityRedirect(
            $request,
            $access,
            FeatureKey::DeveloperGithubEvidence,
            $plan,
            $task,
            [
                'plan_id' => (int) $plan->id,
                'task_id' => (int) $task->id,
                'artifact_id' => (int) $artifact->id,
            ],
            'Developer GitHub Evidence',
        )) {
            return $blocked;
        }

        abort_unless(
            (int) $artifact->plan_id === (int) $plan->id
            && $artifact->provider === 'github'
            && data_get($artifact->metadata, 'github_write_origin.source') === 'execution_github_handoff',
            404,
        );

        $validated = $request->validate([
            'action' => ['required', Rule::in(['complete', 'continue', 'wait'])],
            'expected_snapshot_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'expected_task_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ]);

        try {
            $returnResult = $returns->sync(
                $plan,
                $task,
                $artifact,
            );
        } catch (\RuntimeException $exception) {
            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with('status', $exception->getMessage());
        }

        $snapshot = (array) ($returnResult['snapshot'] ?? []);
        $freshTask = Task::query()->findOrFail($task->id);
        $freshCandidate = $decisions->candidate($freshTask, $artifact->fresh(), $snapshot);

        $snapshotMatches = hash_equals(
            (string) $validated['expected_snapshot_fingerprint'],
            (string) ($freshCandidate['snapshot_fingerprint'] ?? ''),
        );
        $taskMatches = hash_equals(
            (string) $validated['expected_task_fingerprint'],
            (string) ($freshCandidate['task_fingerprint'] ?? ''),
        );

        if (! $snapshotMatches || ! $taskMatches) {
            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with(
                    'status',
                    '確認中にGitHubまたはTaskの状態が変わりました。最新結果を表示したので、内容を確認してからもう一度反映してください。',
                );
        }

        $applied = DB::transaction(function () use (
            $task,
            $artifact,
            $snapshot,
            $decisions,
            $validated
        ) {
            $lockedTask = Task::query()
                ->whereKey($task->id)
                ->lockForUpdate()
                ->firstOrFail();

            $candidate = $decisions->candidate(
                $lockedTask,
                $artifact->fresh(),
                $snapshot,
            );

            if (! hash_equals(
                (string) $validated['expected_task_fingerprint'],
                (string) ($candidate['task_fingerprint'] ?? ''),
            )) {
                throw ValidationException::withMessages([
                    'github_decision' => 'Task状態が確認後に変わっています。最新状態から判断し直してください。',
                ]);
            }

            return [
                'candidate' => $candidate,
                'mutation' => $decisions->apply(
                    $lockedTask,
                    $candidate,
                    (string) $validated['action'],
                ),
            ];
        });

        $candidate = (array) ($applied['candidate'] ?? []);
        $mutation = (array) ($applied['mutation'] ?? []);

        $activity->record(
            $plan,
            $request->user(),
            'github_evidence_decision_applied',
            'task',
            (int) $task->id,
            [
                'action' => (string) $validated['action'],
                'pull_request_artifact_id' => (int) $artifact->id,
                'pull_request_number' => (int) ($candidate['pull_request_number'] ?? 0),
                'snapshot_fingerprint' => (string) ($candidate['snapshot_fingerprint'] ?? ''),
                'evidence_ids' => array_values((array) ($returnResult['evidence_ids'] ?? [])),
                'before' => (array) ($mutation['before'] ?? []),
                'after' => (array) ($mutation['after'] ?? []),
            ],
        );

        $message = match ((string) $validated['action']) {
            'complete' => 'GitHub Evidenceを確認し、このTaskを完了として反映しました。',
            'continue' => 'GitHub Evidenceを確認し、修正対応を続ける状態へ反映しました。進捗率は変更していません。',
            default => 'GitHub Evidenceを確認し、次の確認Actionだけを更新しました。進捗率は変更していません。',
        };

        return redirect()
            ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
            ->with('success', $message);
    }

    public function discard(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        ExecutionGitHubHandoffService $handoff,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        $handoff->clear($request, $plan, $task);

        return redirect()
            ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
            ->with('status', 'GitHub変更候補を破棄しました。GitHub側には何も変更していません。');
    }

    /**
     * Preserve capability enforcement while keeping web-form failures
     * actionable for the current Development context.
     *
     * @param array<string,mixed> $context
     */
    private function capabilityRedirect(
        Request $request,
        FeatureAccessService $access,
        FeatureKey $feature,
        Plan $plan,
        Task $task,
        array $context,
        string $label,
    ): ?RedirectResponse {
        $decision = $access->resolveAccess(
            $request->user(),
            $feature,
            $context,
        );

        if ($decision->allowed) {
            return null;
        }

        return redirect()
            ->route(
                'plans.tasks.execution_orchestration.show',
                [$plan, $task],
            )
            ->with(
                'status',
                $this->githubReadiness->deniedMessage(
                    $decision,
                    $label,
                ),
            );
    }

    private function authorizeTask(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
    ): void {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeTask($request, $task);
    }
}
