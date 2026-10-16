<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Models\User;
use App\Services\QuestionBankGrader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyPracticeRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_retry_is_independent_ungraded_and_never_changes_source_progress_or_draft(): void
    {
        [$user, $plan, $task, $attempt] = $this->fixture();
        Http::preventStrayRequests();
        $before = $attempt->fresh()->getAttributes(); $taskBefore = $task->fresh()->getAttributes();
        $key = "study_practice.{$plan->id}.{$task->id}";
        $this->actingAs($user)->withSession([$key => ['title' => '別の途中回答']]);
        $this->get(route('plans.tasks.study_practice.retry.create', [$plan, $task, $attempt->id]))->assertOk()->assertSee('同じ問題への再回答');
        $response = $this->submit($plan, $task, $attempt);
        $response->assertRedirect()->assertSessionHas($key.'.title', '別の途中回答');
        $retry = StudyPracticeSession::sole();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('正誤保留：1問')->assertSee('元の第2問');
        $this->assertSame('ungraded', data_get($retry->assessment_payload, 'assessment.question_feedback.0.correctness'));
        $this->assertFalse($retry->provider_payload['mastery_evidence']);
        $this->assertSame([], $retry->selected_questions);
        $this->assertSame($before, $attempt->fresh()->getAttributes());
        $this->assertSame($taskBefore, $task->fresh()->getAttributes());
        $this->assertDatabaseCount('study_practice_attempts', 1);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('learning_answer_events', 0);
        $this->get(route('plans.tasks.study_practice.review', [$plan, $task, $attempt->id]))->assertOk()->assertSee('の再回答記録を開く');
        Http::assertNothingSent();
    }

    public function test_retry_uuid_is_idempotent_and_conflicting_replays_cannot_edit_saved_results(): void
    {
        [$user, $plan, $task, $attempt] = $this->fixture(); $this->actingAs($user);
        $uuid = (string) Str::uuid();
        $first = $this->submit($plan, $task, $attempt, $uuid)->assertRedirect();
        $again = $this->submit($plan, $task, $attempt, $uuid)->assertRedirect();
        $this->assertSame($first->headers->get('Location'), $again->headers->get('Location'));
        $before = StudyPracticeSession::sole()->getAttributes();
        $this->submit($plan, $task, $attempt, $uuid, ['q2' => ['answer' => 'B']])->assertConflict();
        $this->assertSame($before, StudyPracticeSession::sole()->getAttributes());
        $this->assertDatabaseCount('study_practice_sessions', 1);
        $this->postJson(route('plans.tasks.study_practice.draft', [$plan, $task]), [
            'practice_session_id' => StudyPracticeSession::sole()->id, 'answers_json' => '{"q2":{"answer":"B"}}',
        ])->assertStatus(409);
        $this->assertSame($before, StudyPracticeSession::sole()->getAttributes());
    }

    public function test_other_user_wrong_task_and_guest_identity_cannot_access_or_append_retry_records(): void
    {
        [$user, $plan, $task, $attempt] = $this->fixture(); $this->actingAs($user);
        $response = $this->submit($plan, $task, $attempt)->assertRedirect();
        $this->actingAs(User::factory()->create(['first_run_completed_at' => now()]));
        $this->get($response->headers->get('Location'))->assertForbidden();
        $this->submit($plan, $task, $attempt)->assertForbidden();
        $other = Task::create(['plan_id' => $plan->id, 'title' => '別Task', 'status' => 'todo', 'sort_order' => 2]);
        $this->actingAs($user)->get(route('plans.tasks.study_practice.retry.show', [$plan, $other, $attempt->id, StudyPracticeSession::sole()->id]))->assertNotFound();
        $this->assertDatabaseCount('study_practice_sessions', 1);
        auth()->logout(); $actor = Str::random(64);
        $plan->update(['user_id' => null]); $attempt->update(['user_id' => null, 'actor_token' => $actor]);
        $this->withCredentials()->withCookie('pace_keeper_owner_token_'.$plan->id, $plan->owner_token)->withSession(['pace_keeper.actor_token' => $actor]);
        $guestResult = $this->submit($plan, $task, $attempt)->assertRedirect();
        $this->withSession(['pace_keeper.actor_token' => Str::random(64)])
            ->get($guestResult->headers->get('Location'))->assertNotFound();
        $this->submit($plan, $task, $attempt)->assertNotFound();
        $this->assertDatabaseCount('study_practice_sessions', 2);
    }

    public function test_saved_bank_reference_can_grade_recall_after_bank_changes_without_mastery_or_new_attempt(): void
    {
        [$user, $plan, $task, $attempt] = $this->fixture(); $this->actingAs($user);
        $question = $attempt->questions[1];
        $pack = QuestionPack::create(['slug' => 'retry-pack', 'title' => '公開問題集', 'exam_code' => 'AP', 'subject' => '科目A', 'version' => '1', 'status' => 'published']);
        $model = Question::create(['question_pack_id' => $pack->id, 'external_key' => 'retry',
            'source_type' => 'canovia_original', 'source_reference' => '凍結出典', 'prompt' => $question['prompt'],
            'response_schema' => $question['response_fields'], 'grading_rule' => ['field_id' => 'answer', 'type' => 'exact_choice', 'answer' => 'A'],
            'learning_metadata' => ['concepts' => ['署名']], 'explanation' => '保存された解説', 'is_active' => true]);
        $question['source_question_id'] = $model->id;
        $grade = app(QuestionBankGrader::class)->grade($task, [$question], []);
        $attempt->update(['questions' => [$question], 'assessment' => $grade]);
        $before = $attempt->fresh()->getAttributes();
        $model->update(['grading_rule' => ['field_id' => 'answer', 'type' => 'exact_choice', 'answer' => 'D'], 'explanation' => '現在の別解説']);
        $response = $this->submit($plan, $task, $attempt)->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('照合：1/1問')->assertSee('保存された解説')->assertDontSee('現在の別解説');
        $this->assertSame('correct', data_get(StudyPracticeSession::sole()->assessment_payload, 'assessment.question_feedback.0.correctness'));
        $this->assertSame($before, $attempt->fresh()->getAttributes());
        $this->assertSame(40, $task->fresh()->progress_percent);
        $this->assertDatabaseCount('study_practice_attempts', 1);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_legacy_and_valid_composite_answers_are_retained_but_ap_mixed_single_choices_remain_blocked(): void
    {
        [$user, $plan, $task, $attempt] = $this->fixture(); $this->actingAs($user);
        $legacy = ['id' => 'q2', 'prompt' => '旧形式', 'type' => 'single_choice', 'choices' => $this->choices()];
        $attempt->update(['questions' => [$legacy], 'answers' => [['question_id' => 'q2', 'answer' => 'B']]]);
        $this->submit($plan, $task, $attempt)->assertRedirect();
        $field = ['id' => 'answer', 'type' => 'single_choice', 'required' => true, 'choices' => $this->choices()];
        $mixed = ['id' => 'q2', 'prompt' => '二つの独立回答', 'response_fields' => [$field, array_replace($field, ['id' => 'other'])]];
        $attempt->update(['questions' => [$mixed]]);
        $this->get(route('plans.tasks.study_practice.retry.create', [$plan, $task, $attempt->id]))->assertOk()->assertSee('安全に再回答できる誤答');
        $this->submit($plan, $task, $attempt)->assertConflict();
        $plan->update(['title' => '複合形式の学習']);
        $response = $this->submit($plan, $task, $attempt, null, ['q2' => ['answer' => 'A', 'other' => 'B']])->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('正誤保留：1問');
        $latest = StudyPracticeSession::latest('id')->firstOrFail();
        $this->assertCount(2, $latest->assessment_payload['answers'][0]['fields']);
    }

    public function test_tampered_question_ids_choices_and_missing_answers_cannot_create_a_result(): void
    {
        [$user, $plan, $task, $attempt] = $this->fixture(); $this->actingAs($user);
        foreach ([['q2' => ['answer' => 'INVALID']], ['q2' => []], ['q2' => ['answer' => 'A', 'unknown' => 'x']], ['other_q' => ['answer' => 'A']]] as $answers) {
            $this->submit($plan, $task, $attempt, null, $answers)->assertUnprocessable();
        }
        $this->assertDatabaseCount('study_practice_sessions', 0);
        $this->assertDatabaseCount('study_practice_attempts', 1);
    }

    private function submit(Plan $plan, Task $task, StudyPracticeAttempt $source, ?string $uuid = null, ?array $answers = null)
    {
        return $this->postJson(route('plans.tasks.study_practice.retry.store', [$plan, $task, $source->id]), [
            'retry_request_id' => $uuid ?? (string) Str::uuid(), 'answers' => $answers ?? ['q2' => ['answer' => 'A']],
        ]);
    }

    private function choices(): array { return array_map(fn ($id) => ['id' => $id, 'label' => '選択肢'.$id], ['A', 'B', 'C', 'D']); }

    private function fixture(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create(['user_id' => $user->id, 'owner_token' => Str::random(64), 'public_slug' => (string) Str::uuid(),
            'title' => 'AP 科目A', 'category' => '資格学習', 'start_date' => today(), 'deadline' => today()->addMonth()]);
        $task = Task::create(['plan_id' => $plan->id, 'title' => '署名の演習', 'status' => 'doing', 'sort_order' => 1, 'progress_percent' => 40]);
        $questions = array_map(fn ($id) => ['id' => $id, 'prompt' => '元の問題'.$id,
            'response_fields' => [['id' => 'answer', 'type' => 'single_choice', 'required' => true, 'choices' => $this->choices()]]], ['q1', 'q2']);
        $attempt = StudyPracticeAttempt::create(['plan_id' => $plan->id, 'task_id' => $task->id, 'user_id' => $user->id,
            'request_hash' => hash('sha256', Str::uuid()), 'exercise_title' => '元の採点結果', 'questions' => $questions,
            'answers' => [['question_id' => 'q1', 'answer' => 'A'], ['question_id' => 'q2', 'answer' => 'B']],
            'assessment' => ['question_feedback' => [['question_id' => 'q1', 'correctness' => 'correct'], ['question_id' => 'q2', 'correctness' => 'incorrect']]],
            'score_percent' => 50, 'recommended_task_progress_percent' => 40]);
        return [$user, $plan, $task, $attempt];
    }
}
