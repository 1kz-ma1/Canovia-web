<?php

namespace Tests\Feature;

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

final class StudyLearningDelayedRecheckV617Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array', 'cache.default' => 'array',
            'native_ai.driver' => 'disabled',
            'study.adaptive_learning.locked_queue_size' => 1,
            'canovia.super_admin_user_id' => null, 'canovia.admin_email' => null,
        ]);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'Delayed Review',
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addMonth(),
        ]);
        $task = Task::create([
            'plan_id' => $plan->id, 'title' => 'DNS',
            'estimated_minutes' => 60, 'remaining_minutes' => 40,
            'progress_percent' => 15, 'status' => 'doing', 'priority' => 2,
            'activation_cost' => 1, 'sort_order' => 1,
            'next_action_note' => '既存の本人Action',
        ]);
        $pack = QuestionPack::create([
            'slug' => 'v617-'.Str::lower(Str::random(12)),
            'title' => '合成DNS問題',
            'exam_code' => 'AP', 'subject' => '科目A',
            'version' => '1', 'status' => 'published',
        ]);
        $questions = [];
        for ($i = 1; $i <= 4; $i++) {
            $questions[] = Question::create([
                'question_pack_id' => $pack->id, 'external_key' => (string) Str::uuid(),
                'source_type' => 'canovia_original', 'prompt' => "DNS Question {$i}",
                'response_schema' => [[
                    'id' => 'answer', 'type' => 'single_choice',
                    'label' => '回答', 'required' => true,
                    'choices' => [['id' => 'A', 'label' => '正答'], ['id' => 'B', 'label' => '誤答']],
                ]],
                'grading_rule' => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A'],
                'learning_metadata' => ['concepts' => ['DNS']],
                'explanation' => '合成データ', 'sort_order' => $i, 'is_active' => true,
            ]);
        }
        return [$owner, $plan, $task, $pack, $questions];
    }

    private function record(User $user, Plan $plan, Task $task, QuestionPack $pack,
        Question $question, bool $correct, $when, string $mode = 'understanding'): LearningAnswerEvent
    {
        $run = LearningRun::create([
            'plan_id' => $plan->id, 'task_id' => $task->id, 'user_id' => $user->id,
            'start_request_id' => (string) Str::uuid(), 'question_pack_id' => $pack->id,
            'pack_title_snapshot' => $pack->title, 'pack_version_snapshot' => '1',
            'mode' => $mode, 'status' => 'completed', 'current_ordinal' => 1,
            'queue_policy_version' => 'bank_locked_v1',
            'started_at' => $when, 'completed_at' => $when,
        ]);
        $item = LearningRunItem::create([
            'learning_run_id' => $run->id, 'question_id' => $question->id,
            'ordinal' => 1, 'question_snapshot' => [
                'prompt' => $question->prompt,
                'learning_metadata' => $question->learning_metadata,
                'response_field' => ['id' => 'answer', 'type' => 'single_choice',
                    'choices' => $question->response_schema[0]['choices']],
            ],
            'grading_rule_snapshot' => $question->grading_rule,
            'explanation_snapshot' => $question->explanation,
        ]);
        return LearningAnswerEvent::create([
            'learning_run_item_id' => $item->id, 'request_id' => (string) Str::uuid(),
            'answer_value' => $correct ? 'A' : 'B', 'was_correct' => $correct,
            'grading_method' => 'question_bank_exact_choice', 'answered_at' => $when,
        ]);
    }

    private function history(User $actor, Plan $plan, Task $task): array
    {
        $request = Request::create('/learning/history');
        $request->setUserResolver(fn () => $actor);
        return app(AdaptiveLearningHistoryService::class)
            ->forPlanTask($request, $plan, $task, '');
    }

    private function seedProgress(User $owner, Plan $plan, Task $task, QuestionPack $pack,
        array $q, $lastAt): void
    {
        $this->record($owner, $plan, $task, $pack, $q[0], false, $lastAt->copy()->subDays(4));
        $this->record($owner, $plan, $task, $pack, $q[1], false, $lastAt->copy()->subDays(3));
        $this->record($owner, $plan, $task, $pack, $q[0], true, $lastAt->copy()->subMinute());
        $this->record($owner, $plan, $task, $pack, $q[1], true, $lastAt);
    }

    private function submitDelayed(Plan $plan, Task $task, QuestionPack $pack, array $cue,
        array $overrides = [])
    {
        return $this->postJson(route('plans.tasks.learning.start', [$plan, $task]), array_merge([
            'start_request_id' => (string) Str::uuid(),
            'question_pack_id' => $pack->id, 'mode' => 'understanding',
            'delayed_distinct' => '1', 'delayed_topic' => $cue['topic'],
            'delayed_source_signature' => $cue['source_signature'],
        ], $overrides));
    }

    public function test_pending_then_due_only_after_24h_with_two_existing_wrong_sources(): void
    {
        [$owner, $plan, $task, $pack, $q] = $this->fixture();
        $this->seedProgress($owner, $plan, $task, $pack, $q, now()->subHours(23));
        $this->actingAs($owner);
        $history = $this->history($owner, $plan, $task);
        $this->assertNull($history['next_action_preview']);
        $this->assertSame('later', $history['delayed_recheck']['state']);
        $this->get(route('plans.tasks.learning.history', [$plan, $task]))
            ->assertOk()->assertSee('data-learning-delayed-not-yet', false);
        $this->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertDontSee('data-learning-delayed-start', false);
        $reply = $this->submitDelayed($plan, $task, $pack, $history['delayed_recheck']);
        $reply->assertStatus(409);

        // This is a controlled timestamp correction in an isolated test,
        // not an actual scheduled task or a live clock manipulation.
        LearningAnswerEvent::query()->orderByDesc('id')->firstOrFail()
            ->update(['answered_at' => now()->subHours(25)]);
        LearningAnswerEvent::query()->orderByDesc('id')->skip(1)->firstOrFail()
            ->update(['answered_at' => now()->subHours(25)->subMinute()]);
        $cue = $this->history($owner, $plan, $task)['delayed_recheck'];
        $this->assertSame('due', $cue['state']);
        $this->assertSame('DNS', $cue['topic']);
        $this->assertSame(2, $cue['older_distinct_misses']);
        $this->get(route('plans.tasks.learning.history', [$plan, $task]))
            ->assertOk()->assertSee('data-learning-delayed-due', false)
            ->assertSee('data-learning-delayed-open', false);
        $this->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertSee('data-learning-delayed-start', false)
            ->assertSee('後日の別問題2問を始める');
    }

    public function test_due_owner_explicitly_starts_two_never_answered_questions_and_records_history(): void
    {
        [$owner, $plan, $task, $pack, $q] = $this->fixture();
        $this->seedProgress($owner, $plan, $task, $pack, $q, now()->subHours(26));
        $this->actingAs($owner);
        $cue = $this->history($owner, $plan, $task)['delayed_recheck'];
        $this->assertSame('due', $cue['state']);
        $this->submitDelayed($plan, $task, $pack, $cue)->assertRedirect();
        $run = LearningRun::query()->where('queue_policy_version',
            'bank_topic_delayed_distinct_initial_v617')->sole();
        $this->assertEqualsCanonicalizing([$q[2]->id, $q[3]->id],
            $run->items()->orderBy('ordinal')->limit(2)->pluck('question_id')->all());
        foreach ([1, 2] as $ordinal) {
            $item = $run->items()->where('ordinal', $ordinal)->firstOrFail();
            $this->postJson(route('plans.tasks.learning.answer', [$plan, $task, $run]), [
                'learning_run_item_id' => $item->id,
                'request_id' => (string) Str::uuid(), 'choice' => 'A',
            ])->assertRedirect();
            if ($ordinal === 1) {
                $this->postJson(route('plans.tasks.learning.next', [$plan, $task, $run]), [])
                    ->assertRedirect();
            }
        }
        $history = $this->history($owner, $plan, $task);
        $this->assertSame('later', $history['delayed_recheck']['state']);
        $entry = $history['review_comparisons'][0];
        $this->assertSame($run->id, $entry['run_id']);
        $this->assertSame(['different_source', 'different_source'],
            array_column($entry['answers'], 'source_relation'));
        $this->assertSame(15, $task->fresh()->progress_percent);
        $this->assertSame(40, $task->fresh()->remaining_minutes);
        $this->assertSame('既存の本人Action', $task->fresh()->next_action_note);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    public function test_stale_or_forged_preview_closed_task_and_foreign_actor_fail_closed(): void
    {
        [$owner, $plan, $task, $pack, $q] = $this->fixture();
        $this->seedProgress($owner, $plan, $task, $pack, $q, now()->subDays(2));
        $this->actingAs($owner);
        $cue = $this->history($owner, $plan, $task)['delayed_recheck'];
        $this->submitDelayed($plan, $task, $pack, $cue, ['delayed_topic' => 'SQL'])->assertStatus(409);
        $this->submitDelayed($plan, $task, $pack, $cue,
            ['delayed_source_signature' => str_repeat('0', 64)])->assertStatus(409);
        $this->submitDelayed($plan, $task, $pack, $cue, ['mode' => 'practice'])->assertStatus(409);
        $this->submitDelayed($plan, $task, $pack, $cue, ['delayed_distinct' => null])->assertStatus(422);
        $this->submitDelayed($plan, $task, $pack, $cue,
            ['review_topic' => 'DNS', 'review_source_signature' => $cue['source_signature']])
            ->assertStatus(409);
        $other = User::factory()->create();
        $this->actingAs($other);
        $this->submitDelayed($plan, $task, $pack, $cue)->assertForbidden();
        $this->actingAs($owner);

        // A later answer invalidates the initial signature and the due state.
        $this->record($owner, $plan, $task, $pack, $q[0], true, now());
        $this->submitDelayed($plan, $task, $pack, $cue)->assertStatus(409);
        $task->update(['status' => 'done', 'progress_percent' => 100]);
        $this->assertNull($this->history($owner, $plan, $task)['delayed_recheck']);
        $this->assertDatabaseMissing('learning_runs',
            ['queue_policy_version' => 'bank_topic_delayed_distinct_initial_v617']);
    }

    public function test_no_distinct_bank_or_only_one_wrong_question_has_no_start(): void
    {
        [$owner, $plan, $task, $pack, $q] = $this->fixture();
        $this->seedProgress($owner, $plan, $task, $pack, $q, now()->subDays(2));
        $this->actingAs($owner);
        $cue = $this->history($owner, $plan, $task)['delayed_recheck'];
        $q[2]->update(['is_active' => false]);
        $q[3]->update(['is_active' => false]);
        $this->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertSee('data-learning-delayed-no-pack', false)
            ->assertDontSee('data-learning-delayed-start', false);
        $this->submitDelayed($plan, $task, $pack, $cue)->assertStatus(409);

        $secondTask = Task::create([
            'plan_id' => $plan->id, 'title' => '別のTask',
            'estimated_minutes' => 30, 'remaining_minutes' => 30,
            'progress_percent' => 0, 'status' => 'todo',
            'priority' => 2, 'activation_cost' => 2, 'sort_order' => 2,
        ]);
        $this->record($owner, $plan, $secondTask, $pack, $q[0], false, now()->subDays(4));
        $this->record($owner, $plan, $secondTask, $pack, $q[0], true, now()->subDays(2));
        $this->assertNull($this->history($owner, $plan, $secondTask)['delayed_recheck']);
    }
}
