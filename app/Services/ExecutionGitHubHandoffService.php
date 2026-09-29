<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

final class ExecutionGitHubHandoffService
{
    public const SCHEMA_VERSION = '1.0';
    public const FLOW = 'github_change_candidate';

    public function __construct(
        private readonly ExecutionOrchestrationContextService $contexts,
        private readonly GitHubRepositoryWriter $writer,
        private readonly GitHubWorkflowService $githubWorkflow,
    ) {}

    /**
     * Prepare one human-reviewable GitHub change candidate.
     *
     * This only reads GitHub. It does not create a branch, commit or PR.
     *
     * @return array<string,mixed>
     */
    public function prepare(
        Request $request,
        Plan $plan,
        Task $task,
        PlanArtifact $repository,
        string $filePath,
        string $content,
        string $commitMessage,
        string $pullRequestTitle,
        ?string $pullRequestBody,
        ?User $user,
    ): array {
        if ((int) $task->plan_id !== (int) $plan->id) {
            throw ValidationException::withMessages([
                'task_id' => '選択したPlanのTaskを選んでください。',
            ]);
        }

        if (
            (int) $repository->plan_id !== (int) $plan->id
            || $repository->provider !== 'github'
            || $repository->artifact_type !== 'repository'
        ) {
            throw ValidationException::withMessages([
                'repository_artifact_id' => 'このPlanに登録されたGitHub Repositoryを選んでください。',
            ]);
        }

        if ((string) data_get($repository->metadata, 'github_app_connection.status', '') !== 'connected') {
            throw ValidationException::withMessages([
                'repository_artifact_id' => 'このRepositoryはまだGitHub App接続済みではありません。',
            ]);
        }

        $parsed = $this->githubWorkflow->parseUrl((string) $repository->url);
        if (($parsed['kind'] ?? null) !== 'repository' || blank($parsed['repo_full_name'] ?? null)) {
            throw ValidationException::withMessages([
                'repository_artifact_id' => 'GitHub Repository URLを確認できませんでした。',
            ]);
        }

        $filePath = trim($filePath);
        $commitMessage = trim($commitMessage);
        $pullRequestTitle = trim($pullRequestTitle);
        $pullRequestBody = trim((string) $pullRequestBody);

        if ($filePath === '' || $content === '' || $commitMessage === '' || $pullRequestTitle === '') {
            throw ValidationException::withMessages([
                'github_change' => '変更するファイル・内容・変更メモ・レビュー用タイトルを確認してください。',
            ]);
        }

        $context = $this->contexts->snapshot($request, $plan, $task);
        $orchestrationState = $request->session()->get(
            ExecutionRequestHandoffService::sessionKey($plan, $task),
            [],
        );
        $packet = is_array($orchestrationState['packet'] ?? null)
            ? $orchestrationState['packet']
            : null;
        $packetContextFingerprint = (string) ($orchestrationState['context_fingerprint'] ?? '');

        if (! $packet) {
            throw ValidationException::withMessages([
                'github_change' => '先に現在ContextからExecution Packetを生成または読み込んでください。',
            ]);
        }

        if (
            $packetContextFingerprint === ''
            || ! hash_equals((string) $context['context_fingerprint'], $packetContextFingerprint)
        ) {
            throw ValidationException::withMessages([
                'github_change' => 'Execution Packet生成後にPlan / TaskのContextが変わっています。Packetを再生成してください。',
            ]);
        }

        $packetJson = json_encode(
            $packet,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $packetHash = hash('sha256', is_string($packetJson) ? $packetJson : '');

        $preview = $this->writer->previewFileChange(
            (string) $parsed['repo_full_name'],
            $filePath,
            $content,
        );

        $candidate = [
            'schema_version' => self::SCHEMA_VERSION,
            'flow' => self::FLOW,
            'target_plan' => [
                'id' => (int) $plan->id,
                'title' => (string) $plan->title,
            ],
            'target_task' => [
                'id' => (int) $task->id,
                'title' => (string) $task->title,
            ],
            'repository' => [
                'artifact_id' => (int) $repository->id,
                'repo_full_name' => (string) $parsed['repo_full_name'],
                'url' => (string) $repository->url,
            ],
            'context_fingerprint' => (string) $context['context_fingerprint'],
            'source' => [
                'type' => 'execution_packet',
                'packet_hash' => $packetHash,
                'summary' => mb_substr((string) ($packet['summary'] ?? $task->title), 0, 500),
            ],
            'change' => [
                'file_path' => (string) $preview['file_path'],
                'content_encrypted' => Crypt::encryptString($content),
                'commit_message' => mb_substr($commitMessage, 0, 240),
                'pull_request_title' => mb_substr($pullRequestTitle, 0, 240),
                'pull_request_body' => $pullRequestBody !== ''
                    ? mb_substr($pullRequestBody, 0, 20_000)
                    : null,
            ],
            'preview' => [
                'base_branch' => (string) $preview['base_branch'],
                'file_action' => (string) $preview['file_action'],
                'expected_file_sha' => $preview['expected_file_sha'] ?? null,
                'current_bytes' => (int) ($preview['current_bytes'] ?? 0),
                'proposed_bytes' => (int) ($preview['proposed_bytes'] ?? strlen($content)),
                'current_content_available' => (bool) ($preview['current_content_available'] ?? false),
                'previewed_at' => (string) $preview['previewed_at'],
            ],
            'confirmation' => [
                'state' => 'preview',
                'prepared_by_user_id' => $user?->id,
                'prepared_at' => now()->toIso8601String(),
            ],
        ];

        $request->session()->put(self::sessionKey($plan, $task), $candidate);

        return $this->hydrateCandidate($candidate);
    }

    /** @return array<string,mixed>|null */
    public function candidate(Request $request, Plan $plan, Task $task): ?array
    {
        $candidate = $request->session()->get(self::sessionKey($plan, $task));
        if (! is_array($candidate)) {
            return null;
        }

        try {
            return $this->hydrateCandidate($candidate);
        } catch (DecryptException) {
            $this->clear($request, $plan, $task);

            return null;
        }
    }

    /**
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    private function hydrateCandidate(array $candidate): array
    {
        $encrypted = data_get($candidate, 'change.content_encrypted');
        if (! is_string($encrypted) || $encrypted === '') {
            throw new DecryptException('GitHub change candidate content is missing.');
        }

        $content = Crypt::decryptString($encrypted);
        data_set($candidate, 'change.content', $content);
        data_forget($candidate, 'change.content_encrypted');

        return $candidate;
    }

    public function clear(Request $request, Plan $plan, Task $task): void
    {
        $request->session()->forget(self::sessionKey($plan, $task));
    }

    public static function sessionKey(Plan $plan, Task $task): string
    {
        return 'execution_github_handoff.'.$plan->id.'.'.$task->id;
    }
}
