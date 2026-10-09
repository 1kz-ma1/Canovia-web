<?php

namespace Tests\Feature;

use App\Models\LearningAnswerEvent;
use App\Models\LearningAnswerEvaluationAdjustment;
use App\Models\LearningRun;
use App\Models\LearningRunItem;
use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\Task;
use App\Models\User;
use App\Services\AdaptiveLearningCandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdaptiveLearningCrossRunCandidateL4Test extends TestCase
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
            'study.adaptive_learning.locked_queue_size' => 2,
            'study.adaptive_learning.signal_window' => 8,
            'study.adaptive_learning.minimum_misses' => 2,
            'study.adaptive_learning.candidate_limit' => 8,
        ]);
    }

    private function fixture(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $user->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => '応用情報対策',
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addMonth(),
            'is_public' => false, 'is_collaborative' => false,
        ]);
        $task = Task::create([
            'plan_id' => $plan->id, 'title' => '短時間演習',
            'estimated_minutes' => 60, 'remaining_minutes' => 45,
            'progress_percent' => 12, 'status' => 'doing',
            'priority' => 2, 'activation_cost' => 2, 'sort_order' => 1,
        ]);
        $pack = QuestionPack::create([
            'slug' => 'l4-cross-run-'.Str::lower(Str::random(10)),
            'title' => 'AP分野別 Bank', 'exam_code' => 'AP', 'subject' => '科目A',
            'version' => '1', 'status' => 'published', 'downloadable' => true,
            'metadata' => ['match_terms' => ['AP']],
        ]);
        $questions = [];
        foreach (['db','db','net','net','net','db','net','algo','net','net','algo'] as $index => $topic) {
            $questions[] = Question::create([
                'question_pack_id' => $pack->id,
                'external_key' => 'topic-'.$index,
                'source_type' => 'canovia_original',
                'prompt' => 'テスト問題 '.$index,
                'response_schema' => [[
                    'id' => 'answer', 'type' => 'single_choice',
                    'label' => '回答', 'required' => true,
                    'choices' => [
                        ['id' => 'A', 'label' => '正答'],
                        ['id' => 'B', 'label' => '誤答'],
                    ],
                ]],
                'grading_rule' => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A'],
                'learning_metadata' => ['concepts' => [$topic]],
                'explanation' => 'テスト解説',
                'difficulty' => 2, 'sort_order' => $index + 1,
                'is_active' => true,
            ]);
        }
        return [$user, $plan, $task, $pack, $questions];
    }

    private function historicalAnswer(User $actor, Plan $plan, Task $task,
        QuestionPack $pack, Question $question, bool $correct = false,
        bool $adjusted = false): LearningAnswerEvent
    {
        $run = LearningRun::create([
            'plan_id' => $plan->id, 'task_id' => $task->id,
            'user_id' => $actor->id, 'start_request_id' => (string) Str::uuid(),
            'question_pack_id' => $pack->id, 'pack_title_snapshot' => $pack->title,
            'pack_version_snapshot' => $pack->version,
            'mode' => 'understanding', 'status' => 'completed',
            'current_ordinal' => 1, 'queue_policy_version' => 'bank_locked_v1',
            'started_at' => now(), 'completed_at' => now(),
        ]);
        $item = LearningRunItem::create([
            'learning_run_id' => $run->id, 'question_id' => $question->id,
            'ordinal' => 1,
            'question_snapshot' => [
                'prompt' => $question->prompt,
                'learning_metadata' => $question->learning_metadata,
                'response_field' => ['id' => 'answer', 'type' => 'single_choice',
                    'choices' => $question->response_schema[0]['choices']],
            ],
            'grading_rule_snapshot' => $question->grading_rule,
            'explanation_snapshot' => $question->explanation,
        ]);
        $event = LearningAnswerEvent::create([
            'learning_run_item_id' => $item->id, 'request_id' => (string) Str::uuid(),
            'answer_value' => $correct ? 'A' : 'B', 'was_correct' => $correct,
            'grading_method' => 'question_bank_exact_choice', 'answered_at' => now(),
        ]);
        if ($adjusted) {
            LearningAnswerEvaluationAdjustment::create([
                'learning_answer_event_id' => $event->id,
                'user_id' => $actor->id,
                'reason' => 'accidental_tap', 'effect' => 'exclude_from_recommendations',
                'created_at' => now(),
            ]);
        }
        return $event;
    }

    private function start(User $user, Plan $plan, Task $task, QuestionPack $pack): LearningRun
    {
        $this->actingAs($user)->post(route('plans.tasks.learning.start', [$plan, $task]), [
            'start_request_id' => (string) Str::uuid(),
            'mode' => 'understanding', 'question_pack_id' => $pack->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        return LearningRun::where('plan_id', $plan->id)
            ->where('task_id', $task->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')->latest('id')->firstOrFail();
    }

    public function test_two_distinct_misses_in_short_prior_runs_seed_next_first_questions_and_balance_breadth(): void
    {
        [$owner, $plan, $task, $pack, $q] = $this->fixture();
        $this->historicalAnswer($owner, $plan, $task, $pack, $q[2]);
        $this->historicalAnswer($owner, $plan, $task, $pack, $q[3]);

        $run = $this->start($owner, $plan, $task, $pack);
        $policy = app(AdaptiveLearningCandidateService::class);
        $this->assertSame(['net'], $policy->recurringMissedTopics($run));
        $this->assertSame([$q[4]->id, $q[6]->id],
            $run->items()->orderBy('ordinal')->pluck('question_id')->all(),
            'Prior short runs should affect only the new Run, not historical locked items.');

        $candidates = $run->candidates()->orderBy('position')->get();
        $this->assertSame([$q[8]->id, $q[9]->id, $q[0]->id],
            $candidates->take(3)->pluck('question_id')->all(),
            'Two focused questions must be followed by one breadth question.');
        $this->assertSame('repeated_miss_review', $candidates[0]->reason);
        $this->assertSame('bank_order', $candidates[2]->reason);

        $lockedIds = $run->items()->pluck('question_id')->all();
        $policy->refresh($run);
        $this->assertSame($lockedIds, $run->items()->pluck('question_id')->all());
        $this->assertSame($candidates->pluck('question_id')->all(),
            $run->candidates()->orderBy('position')->pluck('question_id')->all());

        $this->assertDatabaseCount('learning_answer_events', 2);
        $this->assertSame(12, $task->fresh()->progress_percent);
        $this->assertSame(45, $task->fresh()->remaining_minutes);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    public function test_repeating_one_source_question_does_not_become_a_new_weakness(): void
    {
        config(['study.adaptive_learning.candidate_limit' => 12]);
        [$owner, $plan, $task, $pack, $q] = $this->fixture();
        $this->historicalAnswer($owner, $plan, $task, $pack, $q[2]);
        $this->historicalAnswer($owner, $plan, $task, $pack, $q[2]);
        $run = $this->start($owner, $plan, $task, $pack);
        $this->assertSame([], app(AdaptiveLearningCandidateService::class)->recurringMissedTopics($run));
        $this->assertSame([$q[0]->id, $q[1]->id], $run->items()->pluck('question_id')->all());
        $this->assertSame('recent_question_revisit',
            $run->candidates()->where('question_id', $q[2]->id)->firstOrFail()->reason);
    }

    public function test_adjusted_mistap_and_another_actor_or_task_never_feed_this_user_signal(): void
    {
        [$owner, $plan, $task, $pack, $q] = $this->fixture();
        $other = User::factory()->create();
        $this->historicalAnswer($owner, $plan, $task, $pack, $q[2], false, true);
        $this->historicalAnswer($other, $plan, $task, $pack, $q[3]);
        $otherTask = Task::create([
            'plan_id' => $plan->id, 'title' => '別の科目',
            'estimated_minutes' => 10, 'remaining_minutes' => 10,
            'progress_percent' => 0, 'status' => 'todo',
            'priority' => 1, 'activation_cost' => 1, 'sort_order' => 2,
        ]);
        $this->historicalAnswer($owner, $plan, $otherTask, $pack, $q[4]);

        $run = $this->start($owner, $plan, $task, $pack);
        $this->assertSame([], app(AdaptiveLearningCandidateService::class)->recurringMissedTopics($run));
        $this->assertSame([$q[0]->id, $q[1]->id], $run->items()->pluck('question_id')->all());
        $this->assertSame(0, $run->items()->whereHas('answer')->count());
    }

    public function test_two_recent_correct_answers_reopen_breadth_across_sessions(): void
    {
        [$owner, $plan, $task, $pack, $q] = $this->fixture();
        $this->historicalAnswer($owner, $plan, $task, $pack, $q[2]);
        $this->historicalAnswer($owner, $plan, $task, $pack, $q[3]);
        $this->historicalAnswer($owner, $plan, $task, $pack, $q[4], true);
        $this->historicalAnswer($owner, $plan, $task, $pack, $q[6], true);

        $run = $this->start($owner, $plan, $task, $pack);
        $this->assertSame([], app(AdaptiveLearningCandidateService::class)->recurringMissedTopics($run));
        $this->assertSame([$q[0]->id, $q[1]->id], $run->items()->pluck('question_id')->all());
    }
}
