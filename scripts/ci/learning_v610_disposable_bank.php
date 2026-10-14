<?php

/**
 * Learning V6.10 disposable Question Bank fixture and read-only assertion.
 * The only synthetic writes are the Question Pack and its questions, before
 * browser use. NEVER seed a Plan, user, Task, learning run or answer event.
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
    || ! in_array($argv[1], ['--seed', '--lookup', '--verify'], true)
) {
    fwrite(STDERR, "Learning V6.10 fixture denied outside disposable loopback SQLite.\n");
    exit(2);
}

if ($argv[1] === '--seed') {
    if (QuestionPack::query()->where('slug', 'learning-v610-disposable')->exists()) {
        fwrite(STDERR, "Learning V6.10 synthetic pack must be created exactly once.\n");
        exit(2);
    }

    $pack = QuestionPack::query()->create([
        'slug' => 'learning-v610-disposable',
        'title' => 'V610 合成ネットワーク演習',
        'exam_code' => 'AP', 'subject' => '科目A', 'version' => '1',
        'status' => 'published', 'downloadable' => true,
        'metadata' => ['match_terms' => ['AP']],
    ]);
    foreach ([1, 2, 3] as $ordinal) {
        Question::query()->create([
            'question_pack_id' => $pack->id,
            'external_key' => 'learning-v610-'.$ordinal,
            'source_type' => 'canovia_original',
            'prompt' => 'V610 検証用ネットワーク問題 '.$ordinal,
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
            'explanation' => 'V610 合成問題の採点解説 '.$ordinal,
            'difficulty' => 2, 'sort_order' => $ordinal, 'is_active' => true,
        ]);
    }
    echo json_encode(['pack_id' => (int) $pack->id], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

$user = User::query()->where('email', 'v610-learning@example.invalid')->sole();
$plan = Plan::query()->where('user_id', $user->id)
    ->where('title', '応用情報技術者試験 合格 V610')->sole();
$task = Task::query()->where('plan_id', $plan->id)->sole();
$pack = QuestionPack::query()->where('slug', 'learning-v610-disposable')->sole();
$data = [
    'plan_id' => (int) $plan->id,
    'task_id' => (int) $task->id,
    'task_count' => Task::query()->where('plan_id', $plan->id)->count(),
    'progress_percent' => (int) $task->progress_percent,
    'task_next_action' => $task->next_action_note,
    'pack_id' => (int) $pack->id,
];

if ($argv[1] === '--verify') {
    $run = LearningRun::query()->where('plan_id', $plan->id)->where('task_id', $task->id)->sole();
    $data += [
        'run_id' => (int) $run->id,
        'run_status' => $run->status,
        'run_current_ordinal' => (int) $run->current_ordinal,
        'learning_answer_count' => $run->items()->whereHas('answer')->count(),
        'correct_count' => $run->items()->whereHas('answer', fn ($q) => $q->where('was_correct', true))->count(),
        'task_evidence_count' => TaskEvidence::query()->where('task_id', $task->id)->count(),
    ];
}

echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
