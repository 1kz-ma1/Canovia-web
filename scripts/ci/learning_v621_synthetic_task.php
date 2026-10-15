<?php

/**
 * V6.21 disposable-only Task fixture to isolate the legacy draft UI.
 * The browser still creates a real guest Plan and claims it on registration.
 * This fixture is NOT acceptance evidence for first Task creation.
 */
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = (string) config('database.connections.sqlite.database');
if (
    getenv('CI') !== 'true'
    || $app->environment() !== 'testing'
    || getenv('CANOVIA_LEARNING_V621_FIXTURE') !== '1'
    || config('database.default') !== 'sqlite'
    || $db !== (string) getenv('RUNNER_TEMP').'/learning-v621-browser.sqlite'
    || (string) getenv('DB_URL') !== ''
    || config('app.url') !== 'http://127.0.0.1:8799'
    || (string) getenv('NATIVE_AI_DRIVER') !== 'disabled'
    || count($argv) !== 2 || $argv[1] !== '--seed-task'
) {
    fwrite(STDERR, "V621_SYNTHETIC_TASK_NONDISPOSABLE_DENIED\n");
    exit(2);
}

$owner = User::query()->where('email', 'v621-draft@example.invalid')->sole();
$plan = Plan::query()->where('user_id', $owner->id)
    ->where('title', 'V621 合成学習初回保存')->sole();

if ($plan->is_public || $plan->is_collaborative || Task::query()->where('plan_id', $plan->id)->exists()) {
    fwrite(STDERR, "V621_SYNTHETIC_TASK_STATE_DENIED\n");
    exit(2);
}

$task = Task::query()->create([
    'plan_id' => $plan->id,
    'title' => 'V621 合成回答保存テストTask',
    'description' => 'Only ephemeral first-draft UI validation',
    'status' => 'todo',
    'progress_percent' => 0,
    'priority' => 1,
    'sort_order' => 1,
]);
echo json_encode(['plan_id' => (int) $plan->id, 'task_id' => (int) $task->id],
    JSON_THROW_ON_ERROR).PHP_EOL;
