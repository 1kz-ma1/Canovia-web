<?php

/**
 * Learning V6.16 disposable Question Bank fixture and read-only assertion.
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
    || ! in_array($argv[1], ['--seed'], true)
) {
    fwrite(STDERR, "Learning V6.16 fixture denied outside disposable loopback SQLite.\n");
    exit(2);
}


if (QuestionPack::query()->where('slug', 'learning-v616-distinct-disposable')->exists()) {
    fwrite(STDERR, "V6.16 disposable pack must be seeded once.\n");
    exit(2);
}

$pack = QuestionPack::query()->create([
    'slug' => 'learning-v616-distinct-disposable',
    'title' => 'V616 合成別問題',
    'exam_code' => 'AP', 'subject' => '科目A', 'version' => '1',
    'status' => 'published',
]);
foreach ([4, 5] as $ordinal) {
    Question::query()->create([
        'question_pack_id' => $pack->id,
        'external_key' => 'learning-v616-'.$ordinal,
        'source_type' => 'canovia_original',
        'prompt' => 'V616 未回答ネットワーク問題 '.$ordinal,
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
        'explanation' => 'V616 合成別問題の解説 '.$ordinal,
        'difficulty' => 2, 'sort_order' => $ordinal, 'is_active' => true,
    ]);
}
echo json_encode(['pack_id' => (int) $pack->id], JSON_THROW_ON_ERROR).PHP_EOL;
