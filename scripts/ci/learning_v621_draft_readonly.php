<?php

/**
 * Read-only, synthetic-account metadata assertion for Learning V6.21.
 * This script has no seed/create/mutation command. Never targets a real DB.
 */
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
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
    || count($argv) !== 1
) {
    fwrite(STDERR, "V621_DISPOSABLE_METADATA_ONLY_DENIED\n");
    exit(2);
}

$owner = User::query()->where('email', 'v621-draft@example.invalid')->sole();
$plan = Plan::query()->where('user_id', $owner->id)
    ->where('title', 'V621 合成学習初回保存')->sole();
$task = Task::query()->where('plan_id', $plan->id)->sole();
$sessions = StudyPracticeSession::query()
    ->where('user_id', $owner->id)->where('plan_id', $plan->id)
    ->where('task_id', $task->id)->get();
$session = $sessions->first();
$draft = (array) ($session?->draft_answers ?? []);

echo json_encode([
    'session_count' => $sessions->count(),
    'attempt_count' => StudyPracticeAttempt::query()
        ->where('user_id', $owner->id)->where('plan_id', $plan->id)
        ->where('task_id', $task->id)->count(),
    'stage' => $session?->status,
    'has_q1_saved' => ($draft['q1']['answer'] ?? null) === 'A',
    'has_q2_saved' => ($draft['q2']['answer'] ?? null) === '合成の短い回答',
    'retry_result_count' => $sessions->where('question_provider', 'review_retry')->count(),
    'learning_run_count' => \App\Models\LearningRun::query()->where('user_id', $owner->id)->where('task_id', $task->id)->count(),
    'attempt_record_hash' => hash('sha256', json_encode(StudyPracticeAttempt::query()->where('user_id', $owner->id)->where('task_id', $task->id)->orderBy('id')->get()->map->getAttributes()->all(), JSON_THROW_ON_ERROR)),
    'task_progress' => (int) $task->progress_percent,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
