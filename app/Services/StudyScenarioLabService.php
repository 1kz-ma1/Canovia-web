<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyRecallItem;
use App\Models\StudyScenarioFixture;
use App\Models\StudyScoreObservation;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StudyScenarioLabService
{
    public const VERSION = 1;

    /**
     * @return array<string,array<string,mixed>>
     */
    public function catalog(): array
    {
        return [
            'ap-current' => [
                'key' => 'ap-current',
                'label' => 'AP · 現在地あり',
                'learning_type' => '資格試験',
                'state_summary' => 'Practice 3回 / 最新90% / DB・NWの履歴 / Scopeなし',
                'expected' => 'State First + Question Practice。Scopeを必須入口にしない。',
                'title' => '🧪 応用情報技術者試験（AP）に合格する',
                'category' => '資格学習',
                'task_title' => '科目Aの分野横断問題を解く',
                'task_description' => '科目Aを分野横断で確認し、未探索と弱点をバランス良く洗い出す。',
                'deadline_days' => 36,
            ],
            'ap-knowledge-gap' => [
                'key' => 'ap-knowledge-gap',
                'label' => 'AP · Knowledge Gap連続',
                'learning_type' => '資格試験',
                'state_summary' => 'DNSでknowledge/concept gapが直近2回',
                'expected' => 'V56.15が問題追加よりResource Studyを優先する。',
                'title' => '🧪 AP DNSの理解不足を立て直す',
                'category' => '資格学習',
                'task_title' => 'DNSの弱点補強',
                'task_description' => 'DNSの名前解決・レコード種別を理解し直してから問題へ戻る。',
                'deadline_days' => 36,
            ],
            'toeic-known' => [
                'key' => 'toeic-known',
                'label' => 'TOEIC 600 · Baselineあり',
                'learning_type' => 'スコア型試験',
                'state_summary' => '現在480 / L250 R230 / Practice 76%',
                'expected' => 'Current 480 / Target 600 / Gap 120 / Practice Accuracyを別尺度表示。',
                'title' => '🧪 TOEIC 600点を取る',
                'category' => '英語学習',
                'task_title' => 'TOEIC診断問題を解く',
                'task_description' => '現在の得意・不得意をPracticeで確認する。',
                'deadline_days' => 70,
            ],
            'toeic-missing' => [
                'key' => 'toeic-missing',
                'label' => 'TOEIC 600 · Baselineなし',
                'learning_type' => 'スコア型試験',
                'state_summary' => 'Practice 84%あり / 外部スコア未登録',
                'expected' => 'Practice 84%をTOEICスコア扱いせず、現在スコアEvidenceを要求。',
                'title' => '🧪 TOEIC 600点を取る',
                'category' => '英語学習',
                'task_title' => 'TOEIC診断問題を解く',
                'task_description' => 'Canovia内Practiceを進めながら外部スコアBaselineを確認する。',
                'deadline_days' => 70,
            ],
            'school-scope-missing' => [
                'key' => 'school-scope-missing',
                'label' => '学校テスト · 範囲未登録',
                'learning_type' => '学校テスト',
                'state_summary' => '前回62点 / 目標80点 / Study Scopeなし',
                'expected' => 'Score履歴があってもScope OrganizationをPrimaryにする。',
                'title' => '🧪 数学II 中間テストで80点',
                'category' => '定期テスト学習',
                'task_title' => '数学IIのテスト対策を進める',
                'task_description' => '今回のテスト範囲を確認して優先順を決める。',
                'deadline_days' => 18,
            ],
            'memorization-due' => [
                'key' => 'memorization-due',
                'label' => '暗記 · Recall期限あり',
                'learning_type' => '暗記・定着',
                'state_summary' => '英単語Recall 6件 / 4件due / 2件future',
                'expected' => 'Question PracticeよりRecallをPrimary Methodにする。',
                'title' => '🧪 英単語100語を暗記する',
                'category' => '英語学習',
                'task_title' => '英単語100語を定着させる',
                'task_description' => '見ずに思い出せるかをRecallで確認する。',
                'deadline_days' => 30,
            ],
            'skill-practical' => [
                'key' => 'skill-practical',
                'label' => 'スキル · Practical Evidence',
                'learning_type' => 'スキル学習',
                'state_summary' => 'Python実践Taskあり / Evidenceなし',
                'expected' => '問題生成ではなくGuided Execution / Practical EvidenceをPrimaryにする。',
                'title' => '🧪 PythonでCLIツールを作れるようになる',
                'category' => 'プログラミング学習',
                'task_title' => 'CSV集計CLIを作る',
                'task_description' => 'CSVを読み込み、列ごとの合計と平均を出すCLIを実装する。',
                'deadline_days' => 45,
            ],
            'ambiguous-type' => [
                'key' => 'ambiguous-type',
                'label' => '曖昧Plan · Learning Type確認',
                'learning_type' => '低confidence',
                'state_summary' => '「英語を学ぶ」だけ / overrideなし',
                'expected' => 'V56.17のLearning Type確認Surfaceを表示する。',
                'title' => '🧪 英語を学ぶ',
                'category' => '英語学習',
                'task_title' => '英語学習を進める',
                'task_description' => '今の目標に合う学習方法を決めて進める。',
                'deadline_days' => 60,
            ],
        ];
    }

    public function has(string $scenarioKey): bool
    {
        return array_key_exists($scenarioKey, $this->catalog());
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function catalogFor(User $user): array
    {
        $fixtures = StudyScenarioFixture::query()
            ->with('plan')
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('scenario_key');

        return collect($this->catalog())
            ->map(function (array $scenario, string $key) use ($fixtures) {
                $fixture = $fixtures->get($key);

                return [
                    ...$scenario,
                    'fixture' => $fixture,
                    'plan' => $fixture?->plan,
                ];
            })
            ->all();
    }

    public function createOrReplace(
        User $user,
        string $scenarioKey,
    ): StudyScenarioFixture {
        $definition = $this->catalog()[$scenarioKey] ?? null;

        if (! is_array($definition)) {
            throw new \InvalidArgumentException(
                "Unknown Study Scenario [{$scenarioKey}].",
            );
        }

        return DB::transaction(function () use (
            $user,
            $scenarioKey,
            $definition,
        ) {
            $existing = StudyScenarioFixture::query()
                ->with('plan')
                ->where('user_id', $user->id)
                ->where('scenario_key', $scenarioKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $oldPlan = $existing->plan;
                $existing->delete();
                $oldPlan?->delete();
            }

            $plan = $this->createPlan(
                $user,
                $definition,
            );
            $task = $this->createTask(
                $plan,
                $definition,
            );

            $this->seedScenario(
                $scenarioKey,
                $user,
                $plan,
                $task,
            );

            return StudyScenarioFixture::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'scenario_key' => $scenarioKey,
                'scenario_version' => self::VERSION,
            ]);
        });
    }

    public function delete(
        User $user,
        StudyScenarioFixture $fixture,
    ): void {
        abort_unless(
            (int) $fixture->user_id === (int) $user->id,
            404,
        );

        DB::transaction(function () use ($fixture) {
            $fixture->loadMissing('plan');
            $plan = $fixture->plan;

            $fixture->delete();
            $plan?->delete();
        });
    }

    public function deleteAll(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $fixtures = StudyScenarioFixture::query()
                ->with('plan')
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->get();

            foreach ($fixtures as $fixture) {
                $plan = $fixture->plan;

                $fixture->delete();
                $plan?->delete();
            }

            return $fixtures->count();
        });
    }

    /**
     * @param array<string,mixed> $definition
     */
    private function createPlan(
        User $user,
        array $definition,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'creation_request_id' => (string) Str::uuid(),
            'public_slug' => (string) Str::uuid(),
            'title' => $definition['title'],
            'description' =>
                '[Study Scenario Lab] QA fixture. '.
                $definition['expected'],
            'category' => $definition['category'],
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today()->subDays(14),
            'deadline' => today()->addDays(
                (int) $definition['deadline_days'],
            ),
            'is_public' => false,
            'is_collaborative' => false,
            'visual_icon' => '🧪',
            'accent_key' => 'cyan',
            'roadmap_world' => 'study',
        ]);
    }

    /**
     * @param array<string,mixed> $definition
     */
    private function createTask(
        Plan $plan,
        array $definition,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $definition['task_title'],
            'description' => $definition['task_description'],
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }

    private function seedScenario(
        string $scenarioKey,
        User $user,
        Plan $plan,
        Task $task,
    ): void {
        switch ($scenarioKey) {
            case 'ap-current':
                $this->seedApCurrent($user, $plan, $task);
                break;

            case 'ap-knowledge-gap':
                $this->seedApKnowledgeGap($user, $plan, $task);
                break;

            case 'toeic-known':
                $this->seedToeicKnown($user, $plan, $task);
                break;

            case 'toeic-missing':
                $this->seedToeicMissing($user, $plan, $task);
                break;

            case 'school-scope-missing':
                $this->seedSchoolTest($user, $plan);
                break;

            case 'memorization-due':
                $this->seedMemorization($plan, $task);
                break;
        }
    }

    private function seedApCurrent(
        User $user,
        Plan $plan,
        Task $task,
    ): void {
        $this->attempt(
            $user,
            $plan,
            $task,
            70,
            'incorrect',
            'knowledge_gap',
            ['データベース'],
            now()->subDays(3),
        );
        $this->attempt(
            $user,
            $plan,
            $task,
            84,
            'partial',
            'calculation_slip',
            ['ネットワーク'],
            now()->subDays(2),
        );
        $this->attempt(
            $user,
            $plan,
            $task,
            90,
            'correct',
            'none',
            [],
            now()->subDay(),
        );
    }

    private function seedApKnowledgeGap(
        User $user,
        Plan $plan,
        Task $task,
    ): void {
        $this->attempt(
            $user,
            $plan,
            $task,
            55,
            'incorrect',
            'knowledge_gap',
            ['DNS'],
            now()->subDays(2),
        );
        $this->attempt(
            $user,
            $plan,
            $task,
            60,
            'partial',
            'concept_gap',
            ['DNS'],
            now()->subDay(),
        );
    }

    private function seedToeicKnown(
        User $user,
        Plan $plan,
        Task $task,
    ): void {
        $this->score(
            $user,
            $plan,
            480,
            'toeic_total',
            'TOEIC',
            0,
            990,
            'official_result',
            [
                [
                    'key' => 'listening',
                    'label' => 'Listening',
                    'value' => 250,
                    'min' => 0,
                    'max' => 495,
                    'unit' => 'score',
                ],
                [
                    'key' => 'reading',
                    'label' => 'Reading',
                    'value' => 230,
                    'min' => 0,
                    'max' => 495,
                    'unit' => 'score',
                ],
            ],
        );

        $this->attempt(
            $user,
            $plan,
            $task,
            76,
            'partial',
            'knowledge_gap',
            ['語彙'],
            now()->subDay(),
        );
    }

    private function seedToeicMissing(
        User $user,
        Plan $plan,
        Task $task,
    ): void {
        $this->attempt(
            $user,
            $plan,
            $task,
            84,
            'correct',
            'none',
            [],
            now()->subDay(),
        );
    }

    private function seedSchoolTest(
        User $user,
        Plan $plan,
    ): void {
        $this->score(
            $user,
            $plan,
            62,
            'school_test_score',
            '学校テスト',
            0,
            100,
            'school_result',
            [],
        );
    }

    private function seedMemorization(
        Plan $plan,
        Task $task,
    ): void {
        $cards = [
            ['abandon', '捨てる', -2, 1, 1],
            ['maintain', '維持する', -1, 2, 3],
            ['obtain', '得る', 0, 1, 1],
            ['occur', '起こる', 0, 0, 0],
            ['require', '必要とする', 2, 2, 4],
            ['significant', '重要な', 4, 3, 8],
        ];

        foreach ($cards as $index => $card) {
            [$prompt, $answer, $dueOffset, $repetitions, $interval] = $card;

            StudyRecallItem::query()->create([
                'plan_id' => $plan->id,
                'task_id' => $task->id,
                'prompt' => $prompt,
                'answer' => $answer,
                'note' => 'Study Scenario Lab fixture',
                'tags' => ['scenario', 'english'],
                'fingerprint' => hash(
                    'sha256',
                    $plan->id.'|'.$task->id.'|'.$prompt,
                ),
                'repetitions' => $repetitions,
                'lapse_count' => $index === 1 ? 1 : 0,
                'interval_days' => $interval,
                'ease_factor' => 2.50,
                'due_at' => now()->addDays($dueOffset),
                'last_reviewed_at' => $repetitions > 0
                    ? now()->subDays(max(1, $interval))
                    : null,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @param array<int,string> $topics
     */
    private function attempt(
        User $user,
        Plan $plan,
        Task $task,
        int $score,
        string $correctness,
        string $errorType,
        array $topics,
        \DateTimeInterface $createdAt,
    ): StudyPracticeAttempt {
        $attempt = StudyPracticeAttempt::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'actor_token' => null,
            'request_hash' => hash(
                'sha256',
                (string) Str::uuid(),
            ),
            'exercise_title' => 'Study Scenario Lab Practice',
            'questions' => [],
            'answers' => [],
            'assessment' => [
                'score_percent' => $score,
                'question_feedback' => [[
                    'question_id' => 'scenario-q1',
                    'correctness' => $correctness,
                    'error_type' => $errorType,
                    'weakness_topics' => $topics,
                ]],
            ],
            'score_percent' => $score,
            'strengths' => $correctness === 'correct'
                ? ['Scenario確認']
                : [],
            'weaknesses' => $topics,
            'recommended_task_progress_percent' => 50,
            'progress_before_percent' => 0,
            'progress_after_percent' => 0,
            'evidence_summary' => 'Study Scenario Lab fixture',
            'next_action' => 'Scenario stateを確認',
        ]);

        $attempt->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $attempt;
    }

    /**
     * @param array<int,array<string,mixed>> $components
     */
    private function score(
        User $user,
        Plan $plan,
        float $value,
        string $metricKey,
        string $metricLabel,
        float $min,
        float $max,
        string $sourceKind,
        array $components,
    ): StudyScoreObservation {
        return StudyScoreObservation::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'actor_token' => null,
            'request_id' => (string) Str::uuid(),
            'metric_key' => $metricKey,
            'metric_label' => $metricLabel,
            'score_value' => $value,
            'scale_min' => $min,
            'scale_max' => $max,
            'unit' => 'score',
            'source_kind' => $sourceKind,
            'source_label' => 'Study Scenario Lab',
            'components' => $components,
            'observed_at' => now()->subDay(),
        ]);
    }
}
