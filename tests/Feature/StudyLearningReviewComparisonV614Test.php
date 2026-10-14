<?php

namespace Tests\Feature;

use App\Models\LearningAnswerEvaluationAdjustment;
use App\Models\LearningAnswerEvent;
use App\Models\LearningRun;
use App\Models\LearningRunItem;
use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\Task;
use App\Models\User;
use App\Services\AdaptiveLearningHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StudyLearningReviewComparisonV614Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array',
            'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => '再測定を比較するPlan',
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(30),
        ]);
        $task = Task::create([
            'plan_id' => $plan->id, 'title' => 'ネットワーク',
            'estimated_minutes' => 40, 'remaining_minutes' => 35,
            'progress_percent' => 10, 'status' => 'doing', 'priority' => 2,
            'activation_cost' => 2, 'sort_order' => 1,
            'next_action_note' => 'ユーザーによる既存Action',
        ]);
        $pack = QuestionPack::create([
            'slug' => 'v614-'.Str::lower(Str::random(12)),
            'title' => '合成ネットワーク問題',
            'exam_code' => 'AP', 'subject' => '科目A',
            'version' => '1', 'status' => 'published',
        ]);
        return [$owner, $plan, $task, $pack];
    }

    private function question(QuestionPack $pack, string $topic, int $sort): Question
    {
        return Question::create([
            'question_pack_id' => $pack->id, 'external_key' => (string) Str::uuid(),
            'source_type' => 'canovia_original', 'prompt' => "測定する {$topic} {$sort}",
            'response_schema' => [['id' => 'answer', 'type' => 'single_choice',
                'choices' => [['id' => 'A', 'label' => '正答'], ['id' => 'B', 'label' => '誤答']]]],
            'grading_rule' => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A'],
            'learning_metadata' => ['concepts' => [$topic]],
            'explanation' => '検証のみ', 'sort_order' => $sort, 'is_active' => true,
        ]);
    }

    private function makeRun(User $actor, Plan $plan, Task $task, QuestionPack $pack,
        string $policy = 'bank_locked_v1', string $mode = 'understanding'): LearningRun
    {
        return LearningRun::create([
            'plan_id' => $plan->id, 'task_id' => $task->id, 'user_id' => $actor->id,
            'start_request_id' => (string) Str::uuid(), 'question_pack_id' => $pack->id,
            'pack_title_snapshot' => $pack->title, 'pack_version_snapshot' => '1',
            'mode' => $mode, 'status' => 'completed', 'current_ordinal' => 2,
            'queue_policy_version' => $policy,
            'started_at' => now(), 'completed_at' => now(),
        ]);
    }

    private function answer(LearningRun $run, Question $question, int $ordinal,
        bool $correct, bool $excluded = false): LearningAnswerEvent
    {
        $item = LearningRunItem::create([
            'learning_run_id' => $run->id, 'question_id' => $question->id,
            'ordinal' => $ordinal,
            'question_snapshot' => ['prompt' => $question->prompt,
                'learning_metadata' => $question->learning_metadata],
            'grading_rule_snapshot' => $question->grading_rule,
            'explanation_snapshot' => $question->explanation,
        ]);
        $answer = LearningAnswerEvent::create([
            'learning_run_item_id' => $item->id,
            'request_id' => (string) Str::uuid(),
            'answer_value' => $correct ? 'A' : 'B', 'was_correct' => $correct,
            'grading_method' => 'question_bank_exact_choice',
            'answered_at' => now(),
        ]);
        if ($excluded) {
            LearningAnswerEvaluationAdjustment::create([
                'learning_answer_event_id' => $answer->id,
                'user_id' => $run->user_id,
                'reason' => 'accidental_tap',
                'effect' => 'exclude_from_recommendations',
                'created_at' => now(),
            ]);
        }
        return $answer;
    }

    private function summary(User $actor, Plan $plan, Task $task): array
    {
        $request = Request::create('/learning/history');
        $request->setUserResolver(fn () => $actor);
        return app(AdaptiveLearningHistoryService::class)
            ->forPlanTask($request, $plan, $task, '');
    }

    public function test_before_after_same_source_and_topic_state_without_mastery_mutation(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $a = $this->question($pack, 'DNS', 1);
        $b = $this->question($pack, 'DNS', 2);
        $original = $this->makeRun($owner, $plan, $task, $pack);
        $this->answer($original, $a, 1, false);
        $this->answer($original, $b, 2, true);
        // Two distinct misses across independent source questions are present
        // in the historical scope; repeated tests must stay factual.
        $another = $this->makeRun($owner, $plan, $task, $pack);
        $this->answer($another, $b, 1, false);
        $focused = $this->makeRun($owner, $plan, $task, $pack, 'bank_topic_review_initial_v613');
        $this->answer($focused, $a, 1, true);
        $this->answer($focused, $b, 2, true);

        $comparison = $this->summary($owner, $plan, $task)['review_comparisons'][0];
        $this->assertSame($focused->id, $comparison['run_id']);
        $this->assertSame('DNS', $comparison['topic']);
        $this->assertSame('two_recent_correct', $comparison['topic_state']);
        $this->assertSame(2, $comparison['comparable_count']);
        $this->assertSame(2, $comparison['wrong_to_correct_count']);
        $this->assertSame([false, false], array_column($comparison['answers'], 'before'));
        $this->assertSame([true, true], array_column($comparison['answers'], 'after'));
        $this->actingAs($owner)->get(route('plans.tasks.learning.history', [$plan, $task]))
            ->assertOk()->assertSee('data-learning-review-comparisons', false)
            ->assertSee('data-learning-review-topic-rechecked', false)
            ->assertSee('今回：正解')->assertSee('前回：不正解')
            ->assertDontSee('data-learning-next-action-preview', false);
        $this->assertSame(10, $task->fresh()->progress_percent);
        $this->assertSame(35, $task->fresh()->remaining_minutes);
        $this->assertSame('ユーザーによる既存Action', $task->fresh()->next_action_note);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_no_previous_source_and_excluded_answers_are_not_improvements(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $old = $this->question($pack, 'DNS', 1);
        $novel = $this->question($pack, 'DNS', 2);
        $original = $this->makeRun($owner, $plan, $task, $pack);
        $this->answer($original, $old, 1, false, true); // excluded baseline
        $focused = $this->makeRun($owner, $plan, $task, $pack, 'bank_topic_review_initial_v613');
        $this->answer($focused, $old, 1, true);
        $this->answer($focused, $novel, 2, false, true); // excluded after
        $entry = $this->summary($owner, $plan, $task)['review_comparisons'][0];
        $this->assertSame(0, $entry['comparable_count']);
        $this->assertSame(0, $entry['wrong_to_correct_count']);
        $this->assertNull($entry['answers'][0]['before']);
        $this->assertTrue($entry['answers'][0]['after']);
        $this->assertNull($entry['answers'][1]['before']);
        $this->assertNull($entry['answers'][1]['after']);
        $this->assertSame('not_determined', $entry['topic_state']);
        $this->actingAs($owner)->get(route('plans.tasks.learning.history', [$plan, $task]))
            ->assertOk()->assertSee('比較できる過去回答なし')
            ->assertSee('今回：評価から除外')
            ->assertDontSee('data-learning-review-topic-rechecked', false);
    }

    public function test_other_actor_other_task_and_exam_answers_cannot_supply_a_baseline(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $q1 = $this->question($pack, 'NW', 1);
        $q2 = $this->question($pack, 'NW', 2);
        $foreign = User::factory()->create();
        $foreignRun = $this->makeRun($foreign, $plan, $task, $pack);
        $this->answer($foreignRun, $q1, 1, false);
        $otherTask = Task::create([
            'plan_id' => $plan->id, 'title' => '他Task',
            'estimated_minutes' => 30, 'remaining_minutes' => 30,
            'progress_percent' => 0, 'status' => 'todo',
            'priority' => 2, 'activation_cost' => 2, 'sort_order' => 2,
        ]);
        $this->answer($this->makeRun($owner, $plan, $otherTask, $pack), $q2, 1, false);
        $this->answer($this->makeRun($owner, $plan, $task, $pack, 'bank_locked_v1', 'exam'), $q1, 1, false);
        $focused = $this->makeRun($owner, $plan, $task, $pack, 'bank_topic_review_initial_v613');
        $this->answer($focused, $q1, 1, true);
        $this->answer($focused, $q2, 2, true);
        $comparison = $this->summary($owner, $plan, $task)['review_comparisons'][0];
        $this->assertSame(0, $comparison['comparable_count']);
        $this->assertNull($comparison['answers'][0]['before']);
        $this->assertNull($comparison['answers'][1]['before']);
        $this->assertSame([], $this->summary($foreign, $plan, $otherTask)['review_comparisons']);
        $this->actingAs($foreign)
            ->get(route('plans.tasks.learning.history', [$plan, $task]))
            ->assertForbidden();
    }

    public function test_nonfocused_answers_do_not_create_comparison_and_snapshot_text_is_escaped(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $question = $this->question($pack, '<script>attack()</script>', 1);
        $this->answer($this->makeRun($owner, $plan, $task, $pack), $question, 1, false);
        $this->assertSame([], $this->summary($owner, $plan, $task)['review_comparisons']);
        $focused = $this->makeRun($owner, $plan, $task, $pack, 'bank_topic_review_initial_v613');
        $this->answer($focused, $question, 1, true);
        $page = $this->actingAs($owner)->get(route('plans.tasks.learning.history', [$plan, $task]));
        $page->assertOk()->assertSee('data-learning-review-comparisons', false)
            ->assertSee('&lt;script&gt;attack()&lt;/script&gt;', false)
            ->assertDontSee('<script>attack()</script>', false);
        $this->assertNull($this->summary($owner, $plan, $task)['review_comparisons'][0]['topic']);
    }
}
