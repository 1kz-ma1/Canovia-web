<?php

namespace Tests\Feature;

use App\Models\LearningAnswerEvent;
use App\Models\LearningRun;
use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdaptiveLearningSingleQuestionV5878Test extends TestCase
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
        ]);
    }

    private function fixture(int $count = 4): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '応用情報の学習',
            'category' => '資格学習',
            'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(30),
            'is_public' => false, 'is_collaborative' => false,
        ]);
        $task = Task::create([
            'plan_id' => $plan->id, 'title' => 'ネットワーク問題',
            'estimated_minutes' => 40, 'remaining_minutes' => 25,
            'progress_percent' => 35, 'status' => 'doing',
            'priority' => 2, 'activation_cost' => 2, 'sort_order' => 1,
        ]);
        $pack = QuestionPack::create([
            'slug' => 'adaptive-single-'.Str::random(12),
            'title' => 'APネットワーク基礎', 'exam_code' => 'AP',
            'subject' => '科目A', 'version' => '1',
            'status' => 'published', 'downloadable' => true,
            'metadata' => ['match_terms' => ['AP']],
        ]);
        $questions = collect();
        foreach (range(1, $count) as $number) {
            $questions->push(Question::create([
                'question_pack_id' => $pack->id,
                'external_key' => 'bank-'.$number,
                'source_type' => 'canovia_original',
                'prompt' => 'テスト問題 '.$number,
                'response_schema' => [[
                    'id' => 'answer', 'type' => 'single_choice',
                    'label' => '選んでください', 'required' => true,
                    'choices' => [['id' => 'A', 'label' => '正しい選択肢'],
                        ['id' => 'B', 'label' => '誤った選択肢']],
                ]],
                'grading_rule' => ['type' => 'exact_choice',
                    'field_id' => 'answer', 'answer' => 'A'],
                'learning_metadata' => ['concepts' => ['ネットワーク']],
                'explanation' => 'なぜAが正しいかの解説 '.$number,
                'difficulty' => 3, 'sort_order' => $number, 'is_active' => true,
            ]));
        }
        return [$owner, $plan, $task, $pack, $questions];
    }

    private function start(User $owner, Plan $plan, Task $task, QuestionPack $pack,
        string $mode = 'understanding', ?string $requestId = null): LearningRun
    {
        $this->actingAs($owner)->post(route('plans.tasks.learning.start', [$plan, $task]), [
            'mode' => $mode,
            'question_pack_id' => $pack->id,
            'start_request_id' => $requestId ?? (string) Str::uuid(),
        ])->assertRedirect();
        return LearningRun::latest('id')->firstOrFail();
    }

    private function answer(User $owner, Plan $plan, Task $task, LearningRun $run,
        string $choice = 'A', ?string $requestId = null)
    {
        $item = $run->items()->where('ordinal', $run->fresh()->current_ordinal)->firstOrFail();
        return $this->actingAs($owner)->post(
            route('plans.tasks.learning.answer', [$plan, $task, $run]),
            [
                'learning_run_item_id' => $item->id,
                'choice' => $choice,
                'request_id' => $requestId ?? (string) Str::uuid(),
            ],
        );
    }

    public function test_one_answer_is_persisted_and_can_end_after_a_single_question_without_task_mutation(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $this->actingAs($owner)->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertSee('data-adaptive-learning-start', false)
            ->assertSee('理解モード')->assertSee('演習モード')
            ->assertSee('模擬試験モード：試験別の固定問題セット');
        $run = $this->start($owner, $plan, $task, $pack);
        $this->assertSame('understanding', $run->mode);
        $this->assertSame(2, $run->items()->count());
        $this->actingAs($owner)->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('テスト問題 1')
            ->assertDontSee('テスト問題 2')->assertDontSee('正答：')
            ->assertDontSee('なぜAが正しいかの解説 1');

        $this->answer($owner, $plan, $task, $run)->assertRedirect();
        $event = LearningAnswerEvent::sole();
        $this->assertTrue($event->was_correct);
        $this->assertSame('A', $event->answer_value);
        $this->assertNull($event->evaluation_contribution);
        $this->assertNull($event->evaluation_confidence);
        $this->actingAs($owner)->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('正答：')->assertSee('なぜAが正しいかの解説 1');
        $this->actingAs($owner)->post(route('plans.tasks.learning.finish', [$plan, $task, $run]))
            ->assertRedirect();
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('learning_answer_events', 1);
        $this->assertDatabaseCount('study_practice_attempts', 0);
        $this->assertSame(35, $task->fresh()->progress_percent);
        $this->assertSame(25, $task->fresh()->remaining_minutes);
        $this->actingAs($owner)->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('学習を終了しました')->assertSee('1問回答・1問正解');
    }

    public function test_bank_snapshot_is_stable_and_next_question_is_already_locked(): void
    {
        [$owner, $plan, $task, $pack, $questions] = $this->fixture();
        $run = $this->start($owner, $plan, $task, $pack);
        $initial = $run->items()->get();
        $this->assertSame([$questions[0]->id, $questions[1]->id], $initial->pluck('question_id')->all());
        $this->assertNotNull($initial[0]->presented_at);
        $this->assertNull($initial[1]->presented_at);

        // Changing the Question Bank after the original snapshot cannot
        // silently rewrite the learner's shown question or original grading.
        $questions[0]->update([
            'prompt' => '書き換えられた問題',
            'grading_rule' => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'B'],
            'explanation' => '新しい解説',
        ]);
        $this->answer($owner, $plan, $task, $run, 'A')->assertRedirect();
        $this->assertTrue(LearningAnswerEvent::sole()->was_correct);
        $this->actingAs($owner)->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('テスト問題 1')
            ->assertDontSee('書き換えられた問題')->assertSee('なぜAが正しいかの解説 1');

        $this->actingAs($owner)->post(route('plans.tasks.learning.next', [$plan, $task, $run]))
            ->assertRedirect();
        $this->assertSame(2, $run->fresh()->current_ordinal);
        $this->assertSame($initial[1]->id, $run->items()->where('ordinal', 2)->firstOrFail()->id);
        $this->assertNotNull($run->items()->where('ordinal', 2)->firstOrFail()->presented_at);
        $this->assertSame(3, $run->items()->count()); // background-free Bank refill
        $this->actingAs($owner)->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('テスト問題 2')
            ->assertDontSee('テスト問題 3')->assertDontSee('正答：');
    }

    public function test_practice_mode_hides_explanation_in_collapsible_details_and_resumes_after_reload(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $run = $this->start($owner, $plan, $task, $pack, 'practice');
        $this->answer($owner, $plan, $task, $run, 'B')->assertRedirect();
        $this->assertFalse(LearningAnswerEvent::sole()->was_correct);
        $response = $this->actingAs($owner)->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('不正解')->assertSee('解説を読む（任意）')
            ->assertSee('<details', false);
        $this->assertSame(1, $run->fresh()->current_ordinal);
        $this->actingAs($owner)->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertSee('data-adaptive-learning-resume', false)
            ->assertSee(route('plans.tasks.learning.show', [$plan, $task, $run]));
        $this->assertDatabaseCount('learning_answer_events', 1);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    public function test_duplicate_answers_and_start_retry_are_idempotent_and_changed_answers_conflict(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $rid = (string) Str::uuid();
        $run = $this->start($owner, $plan, $task, $pack, 'understanding', $rid);
        $again = $this->start($owner, $plan, $task, $pack, 'understanding', $rid);
        $this->assertSame($run->id, $again->id);
        $this->assertDatabaseCount('learning_runs', 1);
        $aid = (string) Str::uuid();
        $this->answer($owner, $plan, $task, $run, 'A', $aid)->assertRedirect();
        $this->answer($owner, $plan, $task, $run, 'A', $aid)->assertRedirect();
        $this->answer($owner, $plan, $task, $run, 'A', (string) Str::uuid())->assertRedirect();
        $this->answer($owner, $plan, $task, $run, 'B', $aid)->assertStatus(409);
        $this->assertDatabaseCount('learning_answer_events', 1);
        $this->assertSame('A', LearningAnswerEvent::sole()->answer_value);
    }

    public function test_finish_without_any_answer_is_safe_and_submission_after_finish_is_rejected(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $run = $this->start($owner, $plan, $task, $pack);
        $this->actingAs($owner)->post(route('plans.tasks.learning.finish', [$plan, $task, $run]))
            ->assertRedirect();
        $this->actingAs($owner)->post(route('plans.tasks.learning.finish', [$plan, $task, $run]))
            ->assertRedirect();
        $this->answer($owner, $plan, $task, $run)->assertStatus(409);
        $this->assertDatabaseCount('learning_answer_events', 0);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    public function test_c_mode_cannot_start_without_verified_exam_profile_and_no_fake_exam_record_is_created(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $this->actingAs($owner)->post(route('plans.tasks.learning.start', [$plan, $task]), [
            'start_request_id' => (string) Str::uuid(),
            'question_pack_id' => $pack->id, 'mode' => 'exam',
        ])->assertSessionHasErrors('mode');
        $this->assertDatabaseCount('learning_runs', 0);
    }

    public function test_wrong_plan_task_owner_or_other_users_run_is_not_accessible(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        [$other, $otherPlan, $otherTask] = $this->fixture(1);
        $run = $this->start($owner, $plan, $task, $pack);
        $this->actingAs($other)->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertForbidden();
        $this->actingAs($other)->post(route('plans.tasks.learning.answer', [$plan, $task, $run]), [
            'request_id' => (string) Str::uuid(),
            'learning_run_item_id' => $run->items()->first()->id,
            'choice' => 'A',
        ])->assertForbidden();
        $this->actingAs($owner)->get(route('plans.tasks.learning.show', [$otherPlan, $otherTask, $run]))
            ->assertForbidden();
        $this->actingAs($owner)->get(route('plans.tasks.learning.show', [$plan, $otherTask, $run]))
            ->assertNotFound();
        $this->assertDatabaseCount('learning_answer_events', 0);
        $this->assertSame('active', $run->fresh()->status);
    }

    public function test_unavailable_bank_offers_working_legacy_question_flow_instead_of_dead_end(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $pack->update(['status' => 'draft']);
        $this->actingAs($owner)->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()
            ->assertSee('data-adaptive-learning-no-packs', false)
            ->assertSee('AI演習で1問ずつ回答する')
            ->assertSee(route('plans.tasks.study_practice.show', [$plan, $task]));
    }

    public function test_unpublished_or_nonbank_supported_question_rejected(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture(1);
        $pack->update(['status' => 'draft']);
        $this->actingAs($owner)->post(route('plans.tasks.learning.start', [$plan, $task]), [
            'start_request_id' => (string) Str::uuid(),
            'question_pack_id' => $pack->id, 'mode' => 'understanding',
        ])->assertNotFound();
        $pack->update(['status' => 'published']);
        $pack->questions()->firstOrFail()->update(['grading_rule' => null]);
        $this->actingAs($owner)->post(route('plans.tasks.learning.start', [$plan, $task]), [
            'start_request_id' => (string) Str::uuid(),
            'question_pack_id' => $pack->id, 'mode' => 'understanding',
        ])->assertSessionHasErrors('question_pack_id');
        $this->assertDatabaseCount('learning_runs', 0);
    }

    public function test_compatible_bank_is_not_hidden_behind_thirty_incompatible_published_packs(): void
    {
        [$owner, $plan, $task, $compatible] = $this->fixture(1);

        for ($number = 1; $number <= 30; $number++) {
            $suffix = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            $incompatible = QuestionPack::create([
                'slug' => 'incompatible-bank-'.$suffix,
                'title' => 'A-非対応'.$suffix,
                'exam_code' => 'AP', 'subject' => '科目A',
                'version' => '1', 'status' => 'published',
                'downloadable' => true,
            ]);
            Question::create([
                'question_pack_id' => $incompatible->id,
                'external_key' => 'unsupported-'.$suffix,
                'source_type' => 'canovia_original',
                'prompt' => '採点方式が未対応の問題 '.$suffix,
                'response_schema' => [[
                    'id' => 'answer', 'type' => 'single_choice',
                    'choices' => [
                        ['id' => 'A', 'label' => '選択肢A'],
                        ['id' => 'B', 'label' => '選択肢B'],
                    ],
                ]],
                'grading_rule' => ['type' => 'manual', 'field_id' => 'answer'],
                'explanation' => '未対応の採点方式',
                'difficulty' => 3, 'sort_order' => 1, 'is_active' => true,
            ]);
        }

        $this->assertDatabaseCount('question_packs', 31);
        $this->actingAs($owner)
            ->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()
            ->assertSee($compatible->title)
            ->assertSee('value="'.$compatible->id.'"', false)
            ->assertDontSee('data-adaptive-learning-no-packs', false)
            ->assertDontSee('A-非対応01');

        $run = $this->start($owner, $plan, $task, $compatible);
        $this->answer($owner, $plan, $task, $run)->assertRedirect();
        $this->assertDatabaseCount('learning_answer_events', 1);
    }

    public function test_saved_run_is_shown_before_mode_ranking_even_if_bank_was_retired(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture(1);
        $run = $this->start($owner, $plan, $task, $pack);
        $this->answer($owner, $plan, $task, $run)->assertRedirect();
        $pack->update(['status' => 'retired']);

        $response = $this->actingAs($owner)
            ->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()
            ->assertSee('data-adaptive-learning-no-packs', false)
            ->assertSee('data-adaptive-learning-resume', false)
            ->assertSee(route('plans.tasks.learning.show', [$plan, $task, $run]));

        $html = $response->getContent();
        $resume = strpos($html, 'data-adaptive-learning-resume');
        $ranking = strpos($html, 'data-learning-mode-ranking');
        $this->assertNotFalse($resume);
        $this->assertNotFalse($ranking);
        $this->assertLessThan($ranking, $resume);

        $this->actingAs($owner)
            ->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('正答：');
        $this->assertDatabaseCount('learning_answer_events', 1);
        $this->assertDatabaseCount('study_practice_attempts', 0);
        $this->assertSame(35, $task->fresh()->progress_percent);
    }
}
