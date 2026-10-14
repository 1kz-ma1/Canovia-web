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

/** V6.11 owner-confirmed Study next Action is not autonomous mastery/progress. */
final class AdaptiveLearningNextActionV611Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['session.driver' => 'array', 'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null, 'canovia.admin_email' => null]);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'AP模擬学習',
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(30),
        ]);
        $task = Task::create([
            'plan_id' => $plan->id, 'title' => 'ネットワーク',
            'estimated_minutes' => 30, 'remaining_minutes' => 30,
            'progress_percent' => 35, 'status' => 'doing',
            'priority' => 2, 'activation_cost' => 2, 'sort_order' => 1,
            'next_action_note' => '以前のAction',
        ]);
        $pack = QuestionPack::create([
            'slug' => 'v611-'.Str::lower(Str::random(10)),
            'title' => 'V611測定', 'exam_code' => 'AP', 'subject' => '科目A',
            'version' => '1', 'status' => 'published',
        ]);
        return [$owner, $plan, $task, $pack];
    }

    private function answer(User $actor, Plan $plan, Task $task, QuestionPack $pack,
        bool $correct = false, ?Question $question = null, string $topic = 'ネットワーク',
        string $mode = 'understanding'): LearningAnswerEvent
    {
        $question ??= Question::create([
            'question_pack_id' => $pack->id, 'external_key' => (string) Str::uuid(),
            'source_type' => 'canovia_original', 'prompt' => '独立した問題',
            'response_schema' => [['id' => 'answer', 'type' => 'single_choice',
                'choices' => [['id' => 'A', 'label' => '正答'], ['id' => 'B', 'label' => '誤答']]]],
            'grading_rule' => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A'],
            'learning_metadata' => ['concepts' => [$topic]],
            'explanation' => '模擬解説', 'sort_order' => 1, 'is_active' => true,
        ]);
        $run = LearningRun::create([
            'plan_id' => $plan->id, 'task_id' => $task->id, 'user_id' => $actor->id,
            'start_request_id' => (string) Str::uuid(), 'question_pack_id' => $pack->id,
            'pack_title_snapshot' => $pack->title, 'pack_version_snapshot' => '1',
            'mode' => $mode, 'status' => 'completed', 'current_ordinal' => 1,
            'queue_policy_version' => 'bank_locked_v1',
            'started_at' => now(), 'completed_at' => now(),
        ]);
        $item = LearningRunItem::create([
            'learning_run_id' => $run->id, 'question_id' => $question->id, 'ordinal' => 1,
            'question_snapshot' => ['prompt' => $question->prompt,
                'learning_metadata' => ['concepts' => [$topic]],
                'response_field' => ['type' => 'single_choice']],
            'grading_rule_snapshot' => $question->grading_rule,
            'explanation_snapshot' => $question->explanation,
        ]);
        return LearningAnswerEvent::create([
            'learning_run_item_id' => $item->id, 'request_id' => (string) Str::uuid(),
            'answer_value' => $correct ? 'A' : 'B', 'was_correct' => $correct,
            'grading_method' => 'question_bank_exact_choice', 'answered_at' => now(),
        ]);
    }

    private function preview(User $actor, Plan $plan, Task $task): ?array
    {
        $request = Request::create('/learning/history', 'GET');
        $request->setUserResolver(fn () => $actor);
        return app(AdaptiveLearningHistoryService::class)
            ->forPlanTask($request, $plan, $task, 'unused-logged-in-actor')['next_action_preview'];
    }

    private function confirm(Plan $plan, Task $task, array $preview)
    {
        return $this->post(route('plans.tasks.learning.next_action.confirm', [$plan, $task]), [
            'source_signature' => $preview['source_signature'],
            'expected_next_action_hash' => $preview['expected_next_action_hash'],
        ]);
    }

    public function test_real_owner_can_confirm_two_distinct_misses_without_fabricating_progress(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $this->actingAs($owner);
        $this->answer($owner, $plan, $task, $pack);
        $url = route('plans.tasks.learning.history', [$plan, $task]);
        $this->get($url)->assertOk()->assertDontSee('data-learning-next-action-preview', false);
        $this->assertNull($this->preview($owner, $plan, $task));

        $this->answer($owner, $plan, $task, $pack);
        $candidate = $this->preview($owner, $plan, $task);
        $this->assertNotNull($candidate);
        $this->assertSame('ネットワーク', $candidate['topic']);
        $this->get($url)->assertOk()->assertSee('data-learning-next-action-preview', false)
            ->assertSee('この復習を次のActionに反映する');
        $this->assertSame('以前のAction', $task->fresh()->next_action_note);
        $this->confirm($plan, $task, $candidate)->assertRedirect($url);
        $this->assertSame($candidate['label'], $task->fresh()->next_action_note);
        $this->assertSame(35, $task->fresh()->progress_percent);
        $this->assertSame(30, $task->fresh()->remaining_minutes);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('study_practice_attempts', 0);
        $this->confirm($plan, $task, $candidate)->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('data-learning-next-action-adopted', false);
    }

    public function test_stale_answers_adjustment_and_changed_task_action_fail_closed(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $this->actingAs($owner);
        $first = $this->answer($owner, $plan, $task, $pack);
        $this->answer($owner, $plan, $task, $pack);
        $old = $this->preview($owner, $plan, $task);
        $this->answer($owner, $plan, $task, $pack, true);
        $this->confirm($plan, $task, $old)->assertStatus(409);
        $this->assertSame('以前のAction', $task->fresh()->next_action_note);

        $fresh = $this->preview($owner, $plan, $task);
        LearningAnswerEvaluationAdjustment::create([
            'learning_answer_event_id' => $first->id, 'user_id' => $owner->id,
            'reason' => 'accidental_tap', 'effect' => 'exclude_from_recommendations',
            'created_at' => now(),
        ]);
        $this->confirm($plan, $task, $fresh)->assertStatus(409);
        $this->assertNull($this->preview($owner, $plan, $task));

        $this->answer($owner, $plan, $task, $pack);
        $candidate = $this->preview($owner, $plan, $task);
        $this->assertNotNull($candidate);
        $task->update(['next_action_note' => '本人の別の変更']);
        $this->confirm($plan, $task, $candidate)->assertStatus(409);
        $this->assertSame('本人の別の変更', $task->fresh()->next_action_note);
    }

    public function test_repeated_same_question_foreign_actor_and_exam_do_not_form_action(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $same = $this->answer($owner, $plan, $task, $pack);
        $sourceQuestion = $same->item->question;
        $this->answer($owner, $plan, $task, $pack, false, $sourceQuestion);
        $other = User::factory()->create();
        $this->answer($other, $plan, $task, $pack);
        $this->answer($owner, $plan, $task, $pack, false, null, 'ネットワーク', 'exam');
        $this->actingAs($owner)->get(route('plans.tasks.learning.history', [$plan, $task]))
            ->assertOk()->assertDontSee('data-learning-next-action-preview', false);
        $this->assertNull($this->preview($owner, $plan, $task));
        $this->actingAs($other)->post(route('plans.tasks.learning.next_action.confirm', [$plan, $task]), [
            'source_signature' => str_repeat('0', 64),
            'expected_next_action_hash' => str_repeat('0', 64),
        ])->assertForbidden();
        $this->assertSame('以前のAction', $task->fresh()->next_action_note);
    }

    public function test_two_recent_correct_rescind_candidate_and_done_task_cannot_adopt(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $this->actingAs($owner);
        $this->answer($owner, $plan, $task, $pack);
        $this->answer($owner, $plan, $task, $pack);
        $before = $this->preview($owner, $plan, $task);
        $this->answer($owner, $plan, $task, $pack, true);
        $this->answer($owner, $plan, $task, $pack, true);
        $this->assertNull($this->preview($owner, $plan, $task));
        $this->confirm($plan, $task, $before)->assertStatus(409);
        $task->update(['status' => 'done', 'progress_percent' => 100]);
        $this->assertNull($this->preview($owner, $plan, $task));
    }
}
