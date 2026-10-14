<?php

/**
 * Disposable V6.9 browser acceptance fixture.
 *
 * Strictly local CI, after a browser creates a Guest Plan and registers.
 * Never create test-only web routes, reuse real Aiven/Render credentials or
 * allow this script to run against a non-SQLite database.
 */
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (
    $app->environment() !== 'testing'
    || getenv('CANOVIA_V69_BROWSER_FIXTURE') !== '1'
    || config('database.default') !== 'sqlite'
    || ! str_starts_with((string) config('database.connections.sqlite.database'), '/')
    || ! str_starts_with((string) config('app.url'), 'http://127.0.0.1:')
    || ! in_array(count($argv), [2, 3], true)
) {
    fwrite(STDERR, "V6.9 test fixture blocked: isolated local CI SQLite required.\n");
    exit(2);
}

$verify = count($argv) === 2 && $argv[1] === '--verify';
$email = $verify ? 'v69-browser@example.invalid' : (string) $argv[1];
$title = $verify ? 'V69 ブラウザ開発Plan' : (string) ($argv[2] ?? '');
if ($email !== 'v69-browser@example.invalid' || $title !== 'V69 ブラウザ開発Plan') {
    fwrite(STDERR, "V6.9 fixture must target its single synthetic actor and Plan.\n");
    exit(2);
}

$user = User::query()->where('email', $email)->sole();
$plan = Plan::query()->where('user_id', $user->id)->where('title', $title)->sole();

if ($verify) {
    $task = Task::query()->where('plan_id', $plan->id)->sole();
    $evidenceCount = \App\Models\TaskEvidence::query()->where('task_id', $task->id)->count();
    $executionCount = \App\Models\GuidedExecution::query()->where('task_id', $task->id)->count();
    echo json_encode([
        'guided_execution_count' => $executionCount,
        'task_evidence_count' => $evidenceCount,
        'task_progress_percent' => (int) $task->progress_percent,
        'task_next_action' => $task->next_action_note,
    ], JSON_THROW_ON_ERROR)."\\n";
    exit(0);
}

if (Task::query()->where('plan_id', $plan->id)->exists()) {
    fwrite(STDERR, "V6.9 fixture must only seed a zero-Task Plan.\n");
    exit(2);
}

$task = Task::query()->create([
    'plan_id' => $plan->id,
    'title' => 'ブラウザでログイン復帰を調べる',
    'description' => 'Synthetic browser fixture to exercise real Guided Execution UI',
    'next_action_note' => '最初にログイン状態を確認する',
    'estimated_minutes' => 45,
    'remaining_minutes' => 45,
    'progress_percent' => 20,
    'status' => 'doing',
    'priority' => 1,
    'activation_cost' => 2,
    'sort_order' => 1,
]);

echo json_encode(
    ['plan_id' => $plan->id, 'task_id' => $task->id],
    JSON_THROW_ON_ERROR
)."\n";
