<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use App\Services\QuestionBankGrader;
use App\Services\StudyPracticeAttemptReviewService;
use App\Services\StudyPracticeReviewReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyPracticeAttemptReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_result_shows_open_review_from_attempt_with_errors_first_and_no_database_changes(): void
    {
        [$user, $plan, $task, $attempt] = $this->fixture();
        $before = $attempt->fresh()->getAttributes();
        $taskBefore = $task->fresh()->getAttributes();
        $response = $this->actingAs($user)->withSession(["study_practice.{$plan->id}.{$task->id}" => [
            'attempt_id' => $attempt->id, 'assessment' => ['score_percent' => 99],
        ]])->get(route('plans.tasks.study_practice.show', [$plan, $task]))->assertOk();
        $response->assertSee('data-attempt-review', false)->assertSee('B：保存された選択肢B')
            ->assertSee('記録された判断理由')->assertSee('誤解の候補')->assertSee('保存された選択肢D');
        $response->assertViewHas('attemptReview', fn ($r) => array_column($r['cards'], 'id') === ['q2', 'q3', 'q4', 'q1']);
        $response->assertViewHas('assessment', fn ($a) => $a['score_percent'] === 25);
        preg_match('/<section[^>]*data-attempt-review.*?<\/header>/s', $response->getContent(), $match);
        $this->assertNotEmpty($match);
        $this->assertSame($before, $attempt->fresh()->getAttributes());
        $this->assertSame($taskBefore, $task->fresh()->getAttributes());
    }

    public function test_old_attempt_without_session_is_readable_after_browser_session_is_lost(): void
    {
        [$user, $plan, $task, $attempt] = $this->fixture();
        $attempt->update(['questions' => [['id' => 'old', 'prompt' => '旧形式の問題',
            'type' => 'single_choice', 'choices' => $this->choices(), 'correct_answer' => 'FAKE_ANSWER']],
            'answers' => [['question_id' => 'old', 'answer' => 'B']],
            'assessment' => ['question_feedback' => [['question_id' => 'old', 'correctness' => 'incorrect']]]]);
        $before = $attempt->fresh()->getAttributes();
        $this->actingAs($user)->get(route('plans.tasks.study_practice.history', [$plan, $task]))
            ->assertOk()->assertSee(route('plans.tasks.study_practice.review', [$plan, $task, $attempt->id]), false);
        foreach ([1, 2] as $_) {
            $this->get(route('plans.tasks.study_practice.review', [$plan, $task, $attempt->id]))->assertOk()
                ->assertSee('旧形式の問題')->assertSee('B：保存された選択肢B')
                ->assertSee('独立検証されていない')->assertSee('保存済み正答・解説の根拠がない')
                ->assertDontSee('FAKE_ANSWER');
        }
        $this->assertSame($before, $attempt->fresh()->getAttributes());
    }

    public function test_duplicate_ids_never_borrow_an_answer_or_feedback_by_position(): void
    {
        [, , , $attempt] = $this->fixture();
        $attempt->questions = [['id' => 'same', 'prompt' => '一つ目'], ['id' => 'same', 'prompt' => '二つ目']];
        $attempt->answers = [['question_id' => 'same', 'answer' => 'NEVER_ATTACH']];
        $attempt->assessment = ['question_feedback' => [['question_id' => 'same', 'correctness' => 'correct', 'feedback' => 'NEVER_ATTACH']]];
        $review = app(StudyPracticeAttemptReviewService::class)->forAttempt($attempt);
        $this->assertSame(2, $review['counts']['ungraded']);
        $this->assertTrue($review['cards'][0]['mapping_issue']);
        $this->assertSame('', $review['cards'][0]['feedback']);
        $this->assertSame('', $review['cards'][1]['feedback']);
        $attempt->questions = [['id' => 'q', 'type' => 'text', 'prompt' => '重複回答']];
        $attempt->answers = [['question_id' => 'q', 'answer' => 'FIRST'], ['question_id' => 'q', 'answer' => 'SECOND']];
        $this->assertFalse(app(StudyPracticeAttemptReviewService::class)->forAttempt($attempt)['cards'][0]['fields'][0]['recorded']);
    }

    public function test_other_actor_and_wrong_task_cannot_read_private_attempt_even_on_a_public_plan(): void
    {
        [$user, $plan, $task, $attempt] = $this->fixture();
        $plan->update(['is_public' => true]);
        $this->actingAs(User::factory()->create())
            ->get(route('plans.tasks.study_practice.review', [$plan, $task, $attempt->id]))->assertForbidden();
        $this->get(route('plans.tasks.study_practice.history', [$plan, $task]))->assertForbidden();
        $other = Task::create(['plan_id' => $plan->id, 'title' => '別Task', 'status' => 'todo', 'sort_order' => 2]);
        $this->actingAs($user)->get(route('plans.tasks.study_practice.review', [$plan, $other, $attempt->id]))->assertNotFound();
        $attempt->update(['user_id' => null, 'actor_token' => 'a-different-guest']);
        $this->get(route('plans.tasks.study_practice.review', [$plan, $task, $attempt->id]))->assertNotFound();
    }

    public function test_new_bank_reference_is_bound_to_saved_question_and_survives_bank_edits(): void
    {
        [$user, $plan, $task, $attempt] = $this->fixture();
        $pack = QuestionPack::create(['slug' => 'review-reference', 'title' => '公開確認用',
            'exam_code' => 'AP', 'subject' => '科目A', 'version' => '1', 'status' => 'published']);
        $model = Question::create(['question_pack_id' => $pack->id, 'external_key' => 'review-q',
            'source_type' => 'canovia_original', 'source_reference' => '保存された出典', 'prompt' => '保存されたBank問題',
            'response_schema' => [['id' => 'answer', 'type' => 'single_choice', 'required' => true, 'choices' => $this->choices()]],
            'grading_rule' => ['field_id' => 'answer', 'type' => 'exact_choice', 'answer' => 'A'],
            'learning_metadata' => ['concepts' => ['署名']], 'explanation' => '保存時の解説', 'is_active' => true]);
        $question = ['id' => 'bank_'.$model->id, 'source_question_id' => $model->id,
            'prompt' => $model->prompt, 'response_fields' => $model->response_schema];
        $pack->update(['status' => 'draft']);
        $this->assertSame([], app(StudyPracticeReviewReferenceService::class)->capture([$question], collect([$model])->keyBy('id'))['entries']);
        $pack->update(['status' => 'published', 'metadata' => ['review_state' => 'pending_human_content_and_rights_review']]);
        $model->unsetRelation('pack');
        $this->assertSame([], app(StudyPracticeReviewReferenceService::class)->capture([$question], collect([$model])->keyBy('id'))['entries']);
        $pack->update(['metadata' => []]);
        $model->unsetRelation('pack');
        $assessment = app(QuestionBankGrader::class)->grade($task, [$question], []);
        $this->assertCount(1, $assessment['review_reference_v1']['entries']);
        $reordered = array_reverse($question, true);
        $this->assertNotNull(app(StudyPracticeReviewReferenceService::class)->forQuestion($assessment, $reordered));
        $attempt->update(['questions' => [$question], 'assessment' => $assessment]);
        $model->update(['prompt' => '変更後の問題', 'explanation' => 'NEVER_REBUILD',
            'grading_rule' => ['field_id' => 'answer', 'type' => 'exact_choice', 'answer' => 'D']]);
        $this->actingAs($user)->get(route('plans.tasks.study_practice.review', [$plan, $task, $attempt->id]))
            ->assertOk()->assertSee('保存時の解説')->assertSee('A：保存された選択肢A')->assertDontSee('NEVER_REBUILD');
        $changed = $question; $changed['prompt'] = '不一致';
        $this->assertNull(app(StudyPracticeReviewReferenceService::class)->forQuestion($assessment, $changed));
        $this->assertSame([], app(StudyPracticeReviewReferenceService::class)->capture([$changed], collect([$model])->keyBy('id'))['entries']);
    }

    public function test_plan_viewer_and_another_guest_actor_cannot_read_the_learners_record(): void
    {
        [$user, $plan, $task, $attempt] = $this->fixture();
        $viewer = User::factory()->create();
        $plan->update(['is_collaborative' => true]);
        \App\Models\PlanMember::create(['plan_id' => $plan->id, 'user_id' => $viewer->id,
            'role' => 'viewer', 'invited_by_user_id' => $user->id, 'joined_at' => now()]);
        $this->actingAs($viewer)->get(route('plans.tasks.study_practice.review', [$plan, $task, $attempt->id]))->assertNotFound();
        $this->get(route('plans.tasks.study_practice.history', [$plan, $task]))->assertOk()->assertDontSee('レビュー用の保存回');

        auth()->logout();
        $plan->update(['user_id' => null, 'is_collaborative' => false]);
        $actor = Str::random(64);
        $attempt->update(['user_id' => null, 'actor_token' => $actor]);
        $this->withCookie('pace_keeper_owner_token_'.$plan->id, $plan->owner_token)
            ->withSession(['pace_keeper.actor_token' => $actor])
            ->get(route('plans.tasks.study_practice.review', [$plan, $task, $attempt->id]))->assertOk();
        $this->withSession(['pace_keeper.actor_token' => Str::random(64)])
            ->get(route('plans.tasks.study_practice.review', [$plan, $task, $attempt->id]))->assertNotFound();
    }

    public function test_external_ai_cannot_import_a_claimed_bank_reference(): void
    {
        [$user, $plan, $task, $attempt] = $this->fixture();
        $assessment = $attempt->assessment;
        $assessment += ['flow' => 'study_assessment', 'target_plan' => ['id' => $plan->id],
            'target_task' => ['id' => $task->id], 'recommended_task_progress_percent' => 99,
            'review_reference_v1' => ['source' => 'server_published_bank_v1', 'entries' => [['answer_text' => 'FORGED']]]];
        $this->actingAs($user)->withSession(["study_practice.{$plan->id}.{$task->id}" => [
            'title' => '外部評価', 'questions' => $attempt->questions, 'answers' => $attempt->answers,
            'evaluation_prompt' => '合成評価', 'attempt_token' => (string) Str::uuid(),
        ]])->post(route('plans.tasks.study_practice.assessment', [$plan, $task]), ['assessment_json' => json_encode($assessment)])
            ->assertRedirect()->assertSessionHasNoErrors();
        $latest = StudyPracticeAttempt::latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('review_reference_v1', $latest->assessment);
    }

    private function choices(): array
    {
        return array_map(fn ($id) => ['id' => $id, 'label' => '保存された選択肢'.$id], ['A', 'B', 'C', 'D']);
    }

    private function fixture(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create(['user_id' => $user->id, 'owner_token' => Str::random(64), 'public_slug' => (string) Str::uuid(),
            'title' => 'AP 科目A', 'category' => '資格学習', 'start_date' => today(), 'deadline' => today()->addMonth()]);
        $task = Task::create(['plan_id' => $plan->id, 'title' => '署名の理解', 'status' => 'doing', 'sort_order' => 1, 'progress_percent' => 40]);
        $questions = []; $answers = []; $feedback = [];
        foreach (['correct', 'incorrect', 'partial', 'ungraded'] as $i => $status) {
            $id = 'q'.($i + 1);
            $questions[] = ['id' => $id, 'prompt' => '保存された問題'.$id, 'response_fields' => [
                ['id' => 'answer', 'type' => 'single_choice', 'required' => true, 'choices' => $this->choices()],
                ['id' => 'reasoning', 'type' => 'textarea', 'label' => '判断理由', 'required' => false],
            ]];
            $answers[] = ['question_id' => $id, 'fields' => [['field_id' => 'reasoning', 'value' => '記録された判断理由'], ['field_id' => 'answer', 'value' => 'B']]];
            $feedback[] = ['question_id' => $id, 'correctness' => $status, 'feedback' => '保存評価'.$id, 'misconceptions' => ['誤解の候補']];
        }
        $attempt = StudyPracticeAttempt::create(['plan_id' => $plan->id, 'task_id' => $task->id, 'user_id' => $user->id,
            'request_hash' => hash('sha256', Str::uuid()), 'exercise_title' => 'レビュー用の保存回',
            'questions' => $questions, 'answers' => array_reverse($answers),
            'assessment' => ['score_percent' => 25, 'question_feedback' => array_reverse($feedback)],
            'score_percent' => 25, 'recommended_task_progress_percent' => 40]);
        return [$user, $plan, $task, $attempt];
    }
}
