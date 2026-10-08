<?php

namespace Tests\Feature;

use App\Models\LearningAnswerEvent;
use App\Models\LearningExamResponseDraft;
use App\Models\LearningRun;
use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdaptiveLearningExamSimulationV5880Test extends TestCase
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
            'adaptive_exam_profiles.profiles' => [],
        ]);
    }

    private function enableFixtureProfile(): void
    {
        config(['adaptive_exam_profiles.profiles' => [
            'fixture-v1' => [
                'status' => 'verified', 'version' => '2026-v1',
                'exam_code' => 'TEST', 'subject' => 'Part A',
                'question_count' => 3, 'duration_minutes' => 30,
                'response_format' => 'single_choice',
                'source_reference' => 'Official test fixture only',
                'verified_at' => '2026-10-08',
            ],
        ]]);
    }

    private function fixture(int $questionCount = 3, bool $matching = true): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => '架空の試験を準備',
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(30),
        ]);
        $task = Task::create([
            'plan_id' => $plan->id, 'title' => '模擬試験に取り組む',
            'estimated_minutes' => 35, 'remaining_minutes' => 21,
            'progress_percent' => 40, 'status' => 'doing',
            'priority' => 2, 'activation_cost' => 2, 'sort_order' => 0,
        ]);
        $pack = QuestionPack::create([
            'slug' => 'mock-fixture-'.Str::random(12),
            'title' => '架空の模試セット',
            'exam_code' => 'TEST', 'subject' => 'Part A', 'version' => '1',
            'status' => 'published', 'downloadable' => true,
            'metadata' => $matching ? [
                'exam_simulation_profile_key' => 'fixture-v1',
                'exam_simulation_profile_version' => '2026-v1',
            ] : [],
        ]);
        $questions = [];
        foreach (range(1, $questionCount) as $index) {
            $questions[] = Question::create([
                'question_pack_id' => $pack->id,
                'external_key' => 'fixture-'.$index,
                'source_type' => 'canovia_original',
                'prompt' => '固定の第'.$index.'問',
                'response_schema' => [[
                    'id' => 'answer', 'type' => 'single_choice', 'required' => true,
                    'label' => '選択', 'choices' => [
                        ['id' => 'A', 'label' => '選択肢A'],
                        ['id' => 'B', 'label' => '選択肢B'],
                    ],
                ]],
                'grading_rule' => [
                    'type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A',
                ],
                'explanation' => '終了後だけの解説'.$index,
                'learning_metadata' => ['concepts' => ['分野'.$index]],
                'is_active' => true, 'difficulty' => 3, 'sort_order' => $index,
            ]);
        }
        return [$owner, $plan, $task, $pack, $questions];
    }

    private function start(User $owner, Plan $plan, Task $task, QuestionPack $pack,
        ?string $id = null): LearningRun
    {
        $this->actingAs($owner)->post(route('plans.tasks.learning.exam.start', [$plan, $task]), [
            'start_request_id' => $id ?? (string) Str::uuid(),
            'question_pack_id' => $pack->id, 'exam_profile_key' => 'fixture-v1',
        ])->assertRedirect();

        return LearningRun::latest('id')->firstOrFail();
    }

    private function answer(User $owner, Plan $plan, Task $task, LearningRun $run,
        int $ordinal, string $choice = 'A', ?string $requestId = null)
    {
        $item = $run->items()->where('ordinal', $ordinal)->firstOrFail();
        return $this->actingAs($owner)->post(
            route('plans.tasks.learning.exam.answer', [$plan, $task, $run]),
            ['request_id' => $requestId ?? (string) Str::uuid(),
                'learning_run_item_id' => $item->id, 'choice' => $choice],
        );
    }

    public function test_no_unverified_real_exam_can_be_started_without_registered_profile(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $this->actingAs($owner)->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertDontSee('data-adaptive-exam-entry', false);
        $this->actingAs($owner)->post(route('plans.tasks.learning.exam.start', [$plan, $task]), [
            'start_request_id' => (string) Str::uuid(),
            'question_pack_id' => $pack->id,
            'exam_profile_key' => 'fixture-v1',
        ])->assertNotFound();
        $this->assertDatabaseCount('learning_runs', 0);
    }

    public function test_verified_fixture_freezes_all_questions_and_does_not_reveal_answers_during_exam(): void
    {
        $this->enableFixtureProfile();
        [$owner, $plan, $task, $pack, $questions] = $this->fixture();
        $this->actingAs($owner)->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertSee('data-adaptive-exam-entry', false)
            ->assertSee('3問 / 30分');

        $id = (string) Str::uuid();
        $run = $this->start($owner, $plan, $task, $pack, $id);
        $this->assertSame('exam', $run->mode);
        $this->assertSame('exam_frozen_v1', $run->queue_policy_version);
        $this->assertSame(3, $run->items()->count());
        $this->assertSame(0, $run->candidates()->count());
        $this->assertNotNull($run->exam_deadline_at);
        $this->assertSame('continue_timer', $run->exam_profile_snapshot['resume_policy']);
        $this->start($owner, $plan, $task, $pack, $id);
        $this->assertDatabaseCount('learning_runs', 1);

        $this->actingAs($owner)->get(route('plans.tasks.learning.exam.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('固定の第1問')->assertDontSee('固定の第2問')
            ->assertDontSee('正答：')->assertDontSee('採点結果：')
            ->assertDontSee('終了後だけの解説1');
        // Never permit the ordinary A/B immediate-feedback routes to view this exam.
        $this->actingAs($owner)->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertNotFound();
        $this->actingAs($owner)->post(route('plans.tasks.learning.answer', [$plan, $task, $run]), [
            'request_id' => (string) Str::uuid(),
            'learning_run_item_id' => $run->items()->first()->id,
            'choice' => 'A',
        ])->assertNotFound();

        $original = $run->items()->where('ordinal', 1)->firstOrFail();
        $questions[0]->update([
            'prompt' => 'Bankで後から変更',
            'grading_rule' => [
                'type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'B',
            ],
            'explanation' => 'Bankで後から変更した解説',
        ]);
        $this->actingAs($owner)->get(route('plans.tasks.learning.exam.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('固定の第1問')->assertDontSee('Bankで後から変更');
        $this->assertSame('A', $original->grading_rule_snapshot['answer']);
        $this->assertDatabaseCount('learning_answer_events', 0);
        $this->assertDatabaseCount('learning_exam_response_drafts', 0);
        $this->assertSame(40, $task->fresh()->progress_percent);
    }

    public function test_exam_answers_are_only_drafts_until_finish_and_scored_once_with_unanswered_denominator(): void
    {
        $this->enableFixtureProfile();
        [$owner, $plan, $task, $pack] = $this->fixture();
        $run = $this->start($owner, $plan, $task, $pack);
        $requestId = (string) Str::uuid();

        $this->answer($owner, $plan, $task, $run, 1, 'B', $requestId)->assertRedirect();
        $this->answer($owner, $plan, $task, $run, 1, 'B', $requestId)->assertRedirect();
        $this->answer($owner, $plan, $task, $run, 1, 'A', $requestId)->assertStatus(409);
        $this->assertDatabaseCount('learning_exam_response_drafts', 1);
        $this->assertDatabaseCount('learning_answer_events', 0);
        $this->assertSame(2, $run->fresh()->current_ordinal);

        // Explicit navigation permits a pre-deadline correction.
        $this->actingAs($owner)->post(route('plans.tasks.learning.exam.navigate', [$plan, $task, $run]), [
            'ordinal' => 1,
        ])->assertRedirect();
        $this->answer($owner, $plan, $task, $run, 1, 'A')->assertRedirect();
        $this->assertDatabaseCount('learning_exam_response_drafts', 1);
        $this->assertSame('A', LearningExamResponseDraft::sole()->answer_value);
        $this->assertDatabaseCount('learning_answer_events', 0);

        $this->actingAs($owner)->get(route('plans.tasks.learning.exam.show', [$plan, $task, $run]))
            ->assertOk()->assertDontSee('正答：')->assertDontSee('採点結果：');
        $this->actingAs($owner)->post(route('plans.tasks.learning.exam.finish', [$plan, $task, $run]))
            ->assertRedirect();
        $this->actingAs($owner)->post(route('plans.tasks.learning.exam.finish', [$plan, $task, $run]))
            ->assertRedirect();

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('learning_answer_events', 1);
        $this->assertTrue(LearningAnswerEvent::sole()->was_correct);
        $this->assertDatabaseCount('study_practice_attempts', 0);
        $this->assertSame(40, $task->fresh()->progress_percent);
        $this->assertSame(21, $task->fresh()->remaining_minutes);
        $this->actingAs($owner)->get(route('plans.tasks.learning.exam.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('採点結果：1/3問正解')->assertSee('終了後だけの解説1')
            ->assertSee('未回答')->assertSee('正答：');
        $this->answer($owner, $plan, $task, $run, 2)->assertStatus(409);
    }

    public function test_timeout_blocks_answers_and_resume_does_not_reset_exam_deadline(): void
    {
        $this->enableFixtureProfile();
        [$owner, $plan, $task, $pack] = $this->fixture();
        $this->travelTo(now()->startOfMinute());
        $run = $this->start($owner, $plan, $task, $pack);
        $deadline = $run->fresh()->exam_deadline_at;
        $this->travel(31)->minutes();
        $this->actingAs($owner)->get(route('plans.tasks.learning.exam.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('制限時間が経過しました')->assertDontSee('正答：');
        $this->answer($owner, $plan, $task, $run, 1)->assertStatus(409);
        $this->actingAs($owner)->post(route('plans.tasks.learning.exam.navigate', [$plan, $task, $run]), [
            'ordinal' => 2,
        ])->assertStatus(409);
        $this->assertEquals($deadline, $run->fresh()->exam_deadline_at);
        $this->assertDatabaseCount('learning_answer_events', 0);
        $this->actingAs($owner)->post(route('plans.tasks.learning.exam.finish', [$plan, $task, $run]))
            ->assertRedirect();
        $this->actingAs($owner)->get(route('plans.tasks.learning.exam.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('採点結果：0/3問正解');
        $this->travelBack();
    }

    public function test_profile_pack_or_question_count_mismatch_rejected_without_any_partial_run(): void
    {
        $this->enableFixtureProfile();
        [$owner, $plan, $task, $pack] = $this->fixture(2);
        $this->actingAs($owner)->post(route('plans.tasks.learning.exam.start', [$plan, $task]), [
            'start_request_id' => (string) Str::uuid(),
            'question_pack_id' => $pack->id, 'exam_profile_key' => 'fixture-v1',
        ])->assertSessionHasErrors('question_pack_id');
        $this->assertDatabaseCount('learning_runs', 0);

        [$otherOwner, $otherPlan, $otherTask, $wrongPack] = $this->fixture(3, false);
        $this->actingAs($otherOwner)->post(route('plans.tasks.learning.exam.start', [$otherPlan, $otherTask]), [
            'start_request_id' => (string) Str::uuid(),
            'question_pack_id' => $wrongPack->id, 'exam_profile_key' => 'fixture-v1',
        ])->assertStatus(409);
        $this->assertDatabaseCount('learning_runs', 0);
        $wrongPack->update(['metadata' => [
            'exam_simulation_profile_key' => 'fixture-v1',
            'exam_simulation_profile_version' => '2026-v1',
        ]]);
        $wrongPack->questions()->firstOrFail()->update(['grading_rule' => null]);
        $this->actingAs($otherOwner)->post(route('plans.tasks.learning.exam.start', [$otherPlan, $otherTask]), [
            'start_request_id' => (string) Str::uuid(),
            'question_pack_id' => $wrongPack->id, 'exam_profile_key' => 'fixture-v1',
        ])->assertSessionHasErrors('question_pack_id');
        $this->assertDatabaseCount('learning_runs', 0);
    }

    public function test_exam_run_is_not_readable_or_mutable_by_a_different_user_or_plan(): void
    {
        $this->enableFixtureProfile();
        [$owner, $plan, $task, $pack] = $this->fixture();
        $run = $this->start($owner, $plan, $task, $pack);
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get(route('plans.tasks.learning.exam.show', [$plan, $task, $run]))
            ->assertForbidden();
        $this->actingAs($stranger)->post(route('plans.tasks.learning.exam.finish', [$plan, $task, $run]))
            ->assertForbidden();
        $otherPlan = $this->fixture()[1];
        $this->actingAs($owner)->get(route('plans.tasks.learning.exam.show', [$otherPlan, $task, $run]))
            ->assertNotFound();
        $this->assertDatabaseCount('learning_answer_events', 0);
        $this->assertSame('active', $run->fresh()->status);
    }
}
