<?php

/**
 * IA-2e first-value browser read-only verification.
 * This script MUST NOT create or modify a Task. The only Task comes from
 * the user's real browser form submission to the existing AI Plan import.
 */
use App\Models\GuidedExecution;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (
    $app->environment() !== 'testing'
    || getenv('CANOVIA_IA2E_BROWSER_FIXTURE') !== '1'
    || config('database.default') !== 'sqlite'
    || ! str_starts_with((string) config('database.connections.sqlite.database'), '/')
    || ! str_starts_with((string) config('app.url'), 'http://127.0.0.1:')
    || count($argv) !== 2
    || ! in_array($argv[1], ['--lookup', '--verify'], true)
) {
    fwrite(STDERR, "IA-2e read-only verifier requires isolated localhost testing SQLite.\n");
    exit(2);
}

$user = User::query()->where('email', 'ia2e-browser@example.invalid')->sole();
$plan = Plan::query()
    ->where('user_id', $user->id)
    ->where('title', 'IA2e 初回体験開発Plan')
    ->sole();
$task = Task::query()->where('plan_id', $plan->id)->sole();
if ($task->title !== '最初の一歩として画面を確認する') {
    fwrite(STDERR, "IA-2e Task must originate from the expected browser-entered import.\n");
    exit(2);
}

$data = [
    'plan_id' => (int) $plan->id,
    'task_id' => (int) $task->id,
    'task_count' => Task::query()->where('plan_id', $plan->id)->count(),
    'task_title' => $task->title,
    'task_progress_percent' => (int) $task->progress_percent,
    'task_next_action' => $task->next_action_note,
    'guided_execution_count' => GuidedExecution::query()->where('task_id', $task->id)->count(),
    'task_evidence_count' => TaskEvidence::query()->where('task_id', $task->id)->count(),
];

echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
