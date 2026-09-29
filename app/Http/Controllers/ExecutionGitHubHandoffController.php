<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Services\ExecutionGitHubHandoffService;
use App\Services\ExecutionOrchestrationContextService;
use App\Services\ExecutionRequestHandoffService;
use App\Services\FeatureAccessService;
use App\Services\GitHubRepositoryWriter;
use App\Services\PlanActivityService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ExecutionGitHubHandoffController extends Controller
{
    public function prepare(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        FeatureAccessService $access,
        ExecutionGitHubHandoffService $handoff,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        $access->authorizeUse(
            $request->user(),
            FeatureKey::DeveloperGithubWrite,
            ['plan_id' => (int) $plan->id, 'task_id' => (int) $task->id],
        );

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
        $access->authorizeUse(
            $request->user(),
            FeatureKey::DeveloperGithubWrite,
            ['plan_id' => (int) $plan->id, 'task_id' => (int) $task->id],
        );

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
