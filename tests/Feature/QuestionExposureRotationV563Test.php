<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Models\User;
use App\Services\QuestionBankStudyPracticeQuestionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuestionExposureRotationV563Test extends TestCase
{
    use RefreshDatabase;

    public function test_plan_wide_history_avoids_questions_seen_on_another_task(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $otherTask = $this->task($plan, '科目A 別タスク');
        $pack = $this->pack(20);
        $questions = $pack->questions()->orderBy('sort_order')->get();

        $seen = $questions->take(10)->pluck('id');
        $this->recordSession($user, $plan, $otherTask, $seen);

        $prepared = $this->prepareGeneral($plan, $task);
        $selected = collect($prepared['selected_questions']);

        $this->assertSame('bank-v4-routing', $prepared['selector_version']);
        $this->assertSame(1, data_get($prepared, 'payload.selection_rotation.history_sessions_considered'));
        $this->assertSame(24, data_get($prepared, 'payload.selection_rotation.history_session_limit'));
        $this->assertSame(3, data_get($prepared, 'payload.selection_rotation.recent_session_window'));
        $this->assertCount(10, $selected);
        $this->assertEmpty($selected->pluck('question_id')->intersect($seen)->all());
        $this->assertSame(
            $questions->skip(10)->pluck('id')->sort()->values()->all(),
            $selected->pluck('question_id')->sort()->values()->all(),
        );
        $this->assertTrue($selected->every(
            fn (array $item) => $item['selection_exposure_count'] === 0
                && $item['selection_last_seen_session_offset'] === null
                && $item['selection_recent'] === false
        ));
    }

    public function test_lower_exposure_count_wins_when_all_candidates_are_recent(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $pack = $this->pack(20);
        $questions = $pack->questions()->orderBy('sort_order')->get();

        $lowSortMoreExposed = $questions->take(10)->pluck('id');
        $highSortLessExposed = $questions->skip(10)->pluck('id');

        $this->recordSession($user, $plan, $task, $lowSortMoreExposed);
        $this->recordSession($user, $plan, $task, $lowSortMoreExposed);
        $this->recordSession($user, $plan, $task, $highSortLessExposed);

        $selected = collect($this->prepareGeneral($plan, $task)['selected_questions']);

        $this->assertSame(
            $highSortLessExposed->sort()->values()->all(),
            $selected->pluck('question_id')->sort()->values()->all(),
        );
        $this->assertTrue($selected->every(
            fn (array $item) => $item['selection_exposure_count'] === 1
                && $item['selection_recent'] === true
        ));
    }

    public function test_longer_unseen_question_wins_when_exposure_counts_tie(): void
    {
        [$user, $plan, $task] = $this->studyPlan();
        $pack = $this->pack(20);
        $questions = $pack->questions()->orderBy('sort_order')->get();

        $olderHighSort = $questions->skip(10)->pluck('id');
        $latestLowSort = $questions->take(10)->pluck('id');

        $this->recordSession($user, $plan, $task, $olderHighSort);
        $this->recordSession($user, $plan, $task, $latestLowSort);

        $selected = collect($this->prepareGeneral($plan, $task)['selected_questions']);

        $this->assertSame(
            $olderHighSort->sort()->values()->all(),
            $selected->pluck('question_id')->sort()->values()->all(),
        );
        $this->assertTrue($selected->every(
            fn (array $item) => $item['selection_exposure_count'] === 1
                && $item['selection_last_seen_session_offset'] === 1
                && $item['selection_recent'] === true
        ));
    }

    private function prepareGeneral(Plan $plan, Task $task): array
    {
        return app(QuestionBankStudyPracticeQuestionProvider::class)->prepare(
            $plan,
            $task,
            collect(),
            [
                'key' => 'general_practice',
                'label' => '総合演習',
                'target_question_count' => 10,
                'focus_topics' => [],
                'weakness_priority' => [
                    'primary_topics' => [],
                    'secondary_topics' => [],
                ],
                'question_mix' => [
                    'primary' => 0,
                    'secondary' => 0,
                    'diagnostic' => 10,
                ],
            ],
        );
    }

    private function recordSession(
        User $user,
        Plan $plan,
        Task $task,
        Collection $questionIds,
    ): StudyPracticeSession {
        return StudyPracticeSession::create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'session_token' => (string) Str::uuid(),
            'prepare_request_id' => (string) Str::uuid(),
            'status' => StudyPracticeSession::STATUS_READY,
            'strategy' => 'general_practice',
            'strategy_version' => 'v3',
            'selector_type' => 'question_bank',
            'selector_version' => 'bank-v3-exposure',
            'question_provider' => 'question_bank',
            'question_provider_mode' => 'direct',
            'selected_questions' => $questionIds
                ->map(fn ($id) => [
                    'question_ref' => 'bank_'.$id,
                    'question_id' => (int) $id,
                    'source_type' => 'canovia_original',
                ])
                ->values()
                ->all(),
        ]);
    }

    private function pack(int $count): QuestionPack
    {
        $pack = QuestionPack::create([
            'slug' => 'ap-a-v563-'.Str::lower(Str::random(6)),
            'title' => '応用情報技術者試験 科目A V56.3',
            'exam_code' => 'AP',
            'subject' => '科目A',
            'version' => '1',
            'status' => 'published',
            'downloadable' => true,
            'metadata' => [
                'match_terms' => ['AP', '応用情報', '応用情報技術者試験'],
            ],
        ]);

        foreach (range(1, $count) as $index) {
            Question::create([
                'question_pack_id' => $pack->id,
                'external_key' => 'v563-'.$index,
                'source_type' => 'canovia_original',
                'prompt' => "APローテーション確認 {$index}",
                'response_schema' => [[
                    'id' => 'answer',
                    'type' => 'single_choice',
                    'label' => '回答',
                    'required' => true,
                    'choices' => [
                        ['id' => 'A', 'label' => 'A'],
                        ['id' => 'B', 'label' => 'B'],
                        ['id' => 'C', 'label' => 'C'],
                        ['id' => 'D', 'label' => 'D'],
                    ],
                ]],
                'grading_rule' => [
                    'type' => 'exact_choice',
                    'field_id' => 'answer',
                    'answer' => 'A',
                ],
                'learning_metadata' => [
                    'concepts' => ['ネットワーク'],
                    'weakness_targets' => [],
                    'tags' => ['科目A'],
                    'keywords' => [],
                ],
                'difficulty' => 3,
                'sort_order' => $index,
                'is_active' => true,
            ]);
        }

        return $pack->fresh();
    }

    private function studyPlan(): array
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'AP 応用情報技術者試験',
            'description' => '科目Aの本番演習',
            'category' => '資格学習',
            'priority_mode' => 'auto',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        return [$user, $plan, $this->task($plan, '科目A 総合演習')];
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => 'AP科目Aを10問ずつ演習する',
            'estimated_minutes' => 90,
            'remaining_minutes' => 90,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => Task::where('plan_id', $plan->id)->count() + 1,
        ]);
    }
}
