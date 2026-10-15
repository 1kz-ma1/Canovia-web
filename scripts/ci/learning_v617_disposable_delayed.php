<?php

/**
 * Learning V6.17 disposable Question Bank fixture and read-only assertion.
 * Only seed synthetic Question Bank before browser use; --age-answers changes
 * timestamps on two truly browser-submitted answers solely in disposable
 * SQLite to simulate an elapsed interval; NEVER seed Plans/Users/Runs/Answers.
 */
use App\Models\LearningRun;
use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (
    $app->environment() !== 'testing'
    || getenv('CANOVIA_LEARNING_V610_FIXTURE') !== '1'
    || config('database.default') !== 'sqlite'
    || ! str_starts_with((string) config('database.connections.sqlite.database'), '/')
    || (string) config('app.url') !== 'http://127.0.0.1:8792'
    || count($argv) !== 2
    || ! in_array($argv[1], ['--seed', '--verify', '--age-answers'], true)
) {
    fwrite(STDERR, "Learning V6.17 fixture denied outside disposable loopback SQLite.\n");
    exit(2);
}


if ($argv[1] === '--age-answers') {
    // Synthetic clock-gap fixture ONLY. Never create or modify a user, Task,
    // Run, correctness or evidence. Only time-shift two actual browser grades
    // on disposable loopback SQLite after they have been submitted by UI.
    $owner = User::query()->where('email', 'v610-learning@example.invalid')->sole();
    $plan = Plan::query()->where('user_id', $owner->id)
        ->where('title', '応用情報技術者試験 合格 V610')->sole();
    $task = Task::query()->where('plan_id', $plan->id)->sole();
    $prior = LearningRun::query()->where('plan_id', $plan->id)
        ->where('task_id', $task->id)->where('user_id', $owner->id)
        ->where('queue_policy_version', 'bank_topic_distinct_initial_v616')->sole();
    $two = $prior->items()->whereIn('ordinal', [1, 2])
        ->orderBy('ordinal')->with('answer')->get();
    if ($two->count() !== 2 || $two->contains(fn ($item) =>
        ! $item->answer || ! $item->answer->was_correct)) {
        fwrite(STDERR, "Expected exactly two correctly graded V6.16 browser answers.\n");
        exit(2);
    }
    foreach ($two as $item) {
        $item->answer->update(['answered_at' => now()->subHours(26)
            ->addSeconds((int) $item->ordinal)]);
    }
    echo json_encode(['adjusted_existing_answer_ids' =>
        $two->map(fn ($item) => (int) $item->answer->id)->all(),
        'synthetic_gap_hours' => 26], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($argv[1] === '--verify') {
    $owner = User::query()->where('email', 'v610-learning@example.invalid')->sole();
    $plan = Plan::query()->where('user_id', $owner->id)
        ->where('title', '応用情報技術者試験 合格 V610')->sole();
    $task = Task::query()->where('plan_id', $plan->id)->sole();
    $pack = QuestionPack::query()->where('slug', 'learning-v617-later-disposable')->sole();
    $run = LearningRun::query()->where('plan_id', $plan->id)
        ->where('task_id', $task->id)->where('user_id', $owner->id)
        ->where('queue_policy_version', 'bank_topic_delayed_distinct_initial_v617')->sole();
    $items = $run->items()->orderBy('ordinal')->limit(2)->with('answer')->get();
    echo json_encode([
        'plan_id' => (int) $plan->id,
        'task_id' => (int) $task->id,
        'run_id' => (int) $run->id,
        'policy' => $run->queue_policy_version,
        'source_question_ids' => $items->pluck('question_id')->map(fn ($id) => (int) $id)->all(),
        'expected_source_ids' => $pack->questions()->orderBy('sort_order')
            ->pluck('id')->map(fn ($id) => (int) $id)->all(),
        'answer_count' => $items->filter(fn ($i) => $i->answer !== null)->count(),
        'correct_count' => $items->filter(fn ($i) => (bool) $i->answer?->was_correct)->count(),
        'task_progress' => (int) $task->progress_percent,
        'task_evidence_count' => TaskEvidence::query()->where('task_id', $task->id)->count(),
        'task_next_action' => $task->next_action_note,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(0);
}

if (QuestionPack::query()->where('slug', 'learning-v617-later-disposable')->exists()) {
    fwrite(STDERR, "V6.17 disposable pack must be seeded once.\n");
    exit(2);
}

$pack = QuestionPack::query()->create([
    'slug' => 'learning-v617-later-disposable',
    'title' => 'V617 合成別問題',
    'exam_code' => 'AP', 'subject' => '科目A', 'version' => '1',
    'status' => 'published',
]);
foreach ([6, 7] as $ordinal) {
    Question::query()->create([
        'question_pack_id' => $pack->id,
        'external_key' => 'learning-v617-'.$ordinal,
        'source_type' => 'canovia_original',
        'prompt' => 'V617 未回答ネットワーク問題 '.$ordinal,
        'response_schema' => [[
            'id' => 'answer', 'type' => 'single_choice',
            'label' => '回答', 'required' => true,
            'choices' => [
                ['id' => 'A', 'label' => '正しい選択肢'],
                ['id' => 'B', 'label' => '誤った選択肢'],
            ],
        ]],
        'grading_rule' => [
            'type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A',
        ],
        'learning_metadata' => ['concepts' => ['ネットワーク']],
        'explanation' => 'V617 合成別問題の解説 '.$ordinal,
        'difficulty' => 2, 'sort_order' => $ordinal, 'is_active' => true,
    ]);
}
echo json_encode(['pack_id' => (int) $pack->id], JSON_THROW_ON_ERROR).PHP_EOL;
