<?php

/**
 * V6.13 read-only verifier for a browser-created focused review Run.
 * NO fixture creation, migrations, client credentials, or external I/O.
 */
use App\Models\LearningRun;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment() !== 'testing'
    || getenv('CANOVIA_LEARNING_V610_FIXTURE') !== '1'
    || config('database.default') !== 'sqlite'
    || ! str_starts_with((string) config('database.connections.sqlite.database'), '/')
    || (string) config('app.url') !== 'http://127.0.0.1:8792'
    || $argc !== 2 || $argv[1] !== '--verify') {
    fwrite(STDERR, "V6.13 verifier refused non-disposable local SQLite.\n");
    exit(2);
}

$owner = User::query()->where('email', 'v610-learning@example.invalid')->sole();
$plan = Plan::query()->where('user_id', $owner->id)
    ->where('title', '応用情報技術者試験 合格 V610')->sole();
$task = Task::query()->where('plan_id', $plan->id)->sole();
$runs = LearningRun::query()->where('plan_id', $plan->id)
    ->where('task_id', $task->id)->where('user_id', $owner->id)
    ->orderBy('id')->get();

if ($runs->count() !== 2) {
    fwrite(STDERR, "Expected two actor-scoped browser-created learning runs.\n");
    exit(1);
}
$review = $runs->last();
$answered = $review->items()->whereHas('answer')->with(['answer'])->get();
$firstTwo = $review->items()->orderBy('ordinal')->limit(2)->get();
$topics = $firstTwo->map(fn ($item) =>
    data_get($item->question_snapshot, 'learning_metadata.concepts.0'))->all();

$data = [
    'run_count' => $runs->count(),
    'review_queue_policy' => $review->queue_policy_version,
    'review_question_ids' => $firstTwo->pluck('question_id')->all(),
    'review_question_topics' => $topics,
    'review_answer_count' => $answered->count(),
    'review_correct_count' => $answered->filter(fn ($item) => $item->answer->was_correct)->count(),
    'total_recorded_answer_count' => $runs->sum(fn ($run) => $run->items()->whereHas('answer')->count()),
    'task_progress_percent' => (int) $task->progress_percent,
    'task_remaining_minutes' => (int) $task->remaining_minutes,
    'task_evidence_count' => TaskEvidence::where('task_id', $task->id)->count(),
    'task_next_action_note' => (string) $task->next_action_note,
];
echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
