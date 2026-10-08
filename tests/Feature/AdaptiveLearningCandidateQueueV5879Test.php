<?php

namespace Tests\Feature;

use App\Models\LearningRun;
use App\Models\LearningRunCandidate;
use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\Task;
use App\Models\User;
use App\Services\AdaptiveLearningCandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdaptiveLearningCandidateQueueV5879Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['session.driver' => 'array',
            'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'study.adaptive_learning.locked_queue_size' => 2,
            'study.adaptive_learning.candidate_limit' => 6,
            'study.adaptive_learning.minimum_misses' => 2,
            'study.adaptive_learning.signal_window' => 8,
        ]);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '応用情報', 'category' => '資格学習',
            'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(30),
        ]);
        $task = Task::create([
            'plan_id' => $plan->id, 'title' => 'ネットワーク演習',
            'estimated_minutes' => 60, 'remaining_minutes' => 60,
            'progress_percent' => 0, 'status' => 'todo', 'priority' => 2,
            'activation_cost' => 2, 'sort_order' => 1,
        ]);
        $pack = QuestionPack::create([
            'slug' => 'adaptive-queue-'.Str::random(10), 'title' => 'AP問題集',
            'exam_code' => 'AP', 'subject' => 'A', 'version' => '1',
            'status' => 'published', 'downloadable' => true,
        ]);
        $topics = ['net', 'net', 'net', 'db', 'net', 'net', 'db'];
        $questions = [];
        foreach ($topics as $index => $topic) {
            $q = Question::create([
                'question_pack_id' => $pack->id,
                'external_key' => 'q'.$index,
                'source_type' => 'canovia_original',
                'prompt' => "問{$index}",
                'response_schema' => [[
                    'id' => 'answer', 'type' => 'single_choice',
                    'required' => true, 'choices' => [
                        ['id' => 'A','label' => '正答'], ['id' => 'B','label' => '誤答'],
                    ],
                ]],
                'grading_rule' => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A'],
                'learning_metadata' => ['concepts' => [$topic]],
                'explanation' => '解説'.$index, 'is_active' => true,
                'difficulty' => 3, 'sort_order' => $index + 1,
            ]);
            $questions[] = $q;
        }
        $this->actingAs($owner)->post(route('plans.tasks.learning.start', [$plan, $task]), [
            'start_request_id' => (string) Str::uuid(),
            'mode' => 'understanding',
            'question_pack_id' => $pack->id,
        ])->assertRedirect();

        return [$owner, $plan, $task, $pack, $questions, LearningRun::sole()];
    }

    private function answer(User $owner, Plan $plan, Task $task, LearningRun $run, string $choice): void
    {
        $item = $run->items()->where('ordinal', $run->fresh()->current_ordinal)->firstOrFail();
        $this->actingAs($owner)->post(route('plans.tasks.learning.answer', [$plan, $task, $run]), [
            'learning_run_item_id' => $item->id,
            'request_id' => (string) Str::uuid(),
            'choice' => $choice,
        ])->assertRedirect();
    }

    private function next(User $owner, Plan $plan, Task $task, LearningRun $run): void
    {
        $this->actingAs($owner)->post(route('plans.tasks.learning.next', [$plan, $task, $run]))
            ->assertRedirect();
    }

    public function test_single_wrong_answer_does_not_reorder_future_bank_candidates(): void
    {
        [$owner, $plan, $task, $pack, $questions, $run] = $this->fixture();
        $this->assertSame([$questions[0]->id, $questions[1]->id],
            $run->items()->pluck('question_id')->all());
        $this->assertSame([$questions[2]->id, $questions[3]->id, $questions[4]->id],
            $run->candidates()->limit(3)->pluck('question_id')->all());
        $this->answer($owner, $plan, $task, $run, 'B');
        $this->assertSame([], app(AdaptiveLearningCandidateService::class)->recurringMissedTopics($run));
        $this->assertSame([$questions[2]->id, $questions[3]->id, $questions[4]->id],
            $run->candidates()->limit(3)->pluck('question_id')->all());
        $this->next($owner, $plan, $task, $run);
        $this->assertSame($questions[1]->id, $run->items()->where('ordinal', 2)->firstOrFail()->question_id);
        $this->assertSame($questions[2]->id, $run->items()->where('ordinal', 3)->firstOrFail()->question_id);
        $this->assertDatabaseCount('learning_answer_events', 1);
    }

    public function test_repeated_misses_only_reorder_unlocked_future_questions(): void
    {
        [$owner, $plan, $task, $pack, $questions, $run] = $this->fixture();
        $initialFirst = $run->items()->where('ordinal', 1)->sole();
        $initialSecond = $run->items()->where('ordinal', 2)->sole();
        $this->answer($owner, $plan, $task, $run, 'B'); // first miss
        $this->next($owner, $plan, $task, $run);       // q3 already locked
        $lockedThird = $run->items()->where('ordinal', 3)->sole();

        $this->answer($owner, $plan, $task, $run, 'B'); // second net miss
        $this->assertSame(['net'], app(AdaptiveLearningCandidateService::class)->recurringMissedTopics($run));
        $this->assertSame($questions[4]->id, $run->candidates()->firstOrFail()->question_id);
        $this->assertSame('repeated_miss_review', $run->candidates()->firstOrFail()->reason);
        $this->next($owner, $plan, $task, $run);

        $this->assertSame($initialFirst->id, $run->items()->where('ordinal', 1)->sole()->id);
        $this->assertSame($initialSecond->id, $run->items()->where('ordinal', 2)->sole()->id);
        $this->assertSame($lockedThird->id, $run->items()->where('ordinal', 3)->sole()->id);
        $this->assertSame($questions[4]->id, $run->items()->where('ordinal', 4)->sole()->question_id);
        $this->assertSame($questions[3]->id, $run->candidates()->firstOrFail()->question_id);
        $this->assertSame(0, $task->fresh()->progress_percent);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    public function test_two_newer_correct_answers_reopen_broad_question_selection(): void
    {
        [$owner, $plan, $task, $pack, $questions, $run] = $this->fixture();
        $this->answer($owner, $plan, $task, $run, 'B');
        $this->next($owner, $plan, $task, $run);
        $this->answer($owner, $plan, $task, $run, 'B');
        $this->next($owner, $plan, $task, $run);
        $this->answer($owner, $plan, $task, $run, 'A'); // q3 net correct
        $this->next($owner, $plan, $task, $run);
        $this->answer($owner, $plan, $task, $run, 'A'); // q5 net correct
        $this->assertSame([], app(AdaptiveLearningCandidateService::class)->recurringMissedTopics($run));
        $this->assertSame($questions[3]->id, $run->candidates()->firstOrFail()->question_id);
    }

    public function test_legacy_learning_run_without_topic_metadata_falls_back_safely(): void
    {
        [$owner, $plan, $task, $pack, $questions, $run] = $this->fixture();
        $first = $run->items()->where('ordinal', 1)->sole();
        $snapshot = $first->question_snapshot;
        unset($snapshot['learning_metadata']);
        $first->update(['question_snapshot' => $snapshot]);
        $this->answer($owner, $plan, $task, $run, 'B');
        $this->assertSame([], app(AdaptiveLearningCandidateService::class)->recurringMissedTopics($run));
        $this->assertSame('bank_order', $run->candidates()->firstOrFail()->reason);
    }
}
