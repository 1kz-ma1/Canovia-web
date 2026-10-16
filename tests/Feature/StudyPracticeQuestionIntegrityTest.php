<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyPracticeQuestionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_ap_import_rejects_two_independent_answers_without_replacing_a_saved_draft(): void
    {
        [$plan, $task] = $this->target();
        $this->import($plan, $task, [$this->choice(), $this->reasoning()])->assertSessionHasNoErrors();
        $session = StudyPracticeSession::sole();
        $session->update(['draft_answers' => ['q3' => ['answer' => 'A']]]);
        $before = $session->fresh()->getAttributes();

        $this->import($plan, $task, [$this->choice(), $this->choice('salt')])
            ->assertSessionHasErrors('questions_json');
        $this->assertSame($before, $session->fresh()->getAttributes());
        $this->assertDatabaseCount('study_practice_sessions', 1);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    public function test_generic_composite_and_legacy_ap_formats_remain_supported(): void
    {
        [$plan, $task] = $this->target('複合設問の学習');
        $this->import($plan, $task, [$this->choice(), $this->choice('salt')])->assertSessionHasNoErrors();
        $this->assertCount(2, StudyPracticeSession::sole()->questions_snapshot[0]['response_fields']);
        $plan->update(['title' => 'AP 科目A']);
        $this->post(route('plans.tasks.study_practice.import', [$plan, $task]), [
            'questions_json' => json_encode($this->envelope($plan, $task, [
                'id' => 'q3', 'prompt' => '電子署名', 'type' => 'single_choice',
                'choices' => $this->choice()['choices'],
            ])),
        ])->assertSessionHasNoErrors();
    }

    public function test_saved_mixed_snapshot_is_blocked_on_display_draft_and_submit_without_data_loss(): void
    {
        [$plan, $task] = $this->target();
        $this->import($plan, $task, [$this->choice()])->assertSessionHasNoErrors();
        $session = StudyPracticeSession::sole();
        $questions = $session->questions_snapshot;
        $questions[0]['response_fields'][] = $this->choice('salt');
        $session->update(['questions_snapshot' => $questions, 'draft_answers' => ['q3' => ['answer' => 'A']]]);
        $before = $session->fresh()->getAttributes();
        $this->app['session']->forget("study_practice.{$plan->id}.{$task->id}");
        $this->followingRedirects()->get(route('plans.tasks.study_practice.resume', [$plan, $task]))
            ->assertOk()->assertSee('複数の独立した回答欄')
            ->assertSee('studyPracticeQuestionRepairPrompt', false)
            ->assertDontSee('data-study-practice-draft-form', false);
        $this->postJson(route('plans.tasks.study_practice.draft', [$plan, $task]), [
            'practice_session_id' => $session->id, 'answers_json' => '{"q3":{"answer":"B"}}',
        ])->assertUnprocessable();
        $this->postJson(route('plans.tasks.study_practice.answers', [$plan, $task]), [
            'answers' => ['q3' => ['answer' => 'A', 'salt' => 'A']],
        ])->assertUnprocessable();
        $this->assertSame($before, $session->fresh()->getAttributes());
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    private function target(string $title = 'AP 科目A'): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $this->actingAs($user);
        $plan = Plan::create(['user_id' => $user->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => $title, 'category' => '資格学習',
            'start_date' => today(), 'deadline' => today()->addMonth()]);
        $task = Task::create(['plan_id' => $plan->id, 'title' => 'セキュリティ演習',
            'status' => 'todo', 'priority' => 1, 'sort_order' => 1]);
        return [$plan, $task];
    }

    private function import(Plan $plan, Task $task, array $fields)
    {
        return $this->post(route('plans.tasks.study_practice.import', [$plan, $task]), [
            'questions_json' => json_encode($this->envelope($plan, $task, [
                'id' => 'q3', 'prompt' => '公開鍵暗号方式を用いた電子署名について、最も適切な説明はどれか。',
                'work_input' => 'none', 'response_fields' => $fields,
            ])),
        ]);
    }

    private function envelope(Plan $plan, Task $task, array $question): array
    {
        return ['flow' => 'study_practice', 'target_plan' => ['id' => $plan->id],
            'target_task' => ['id' => $task->id], 'questions' => [$question]];
    }

    private function choice(string $id = 'answer'): array
    {
        return ['id' => $id, 'type' => 'single_choice', 'label' => '回答', 'required' => true,
            'choices' => collect(['A', 'B', 'C', 'D'])->map(fn ($v) => ['id' => $v,
                'label' => ($id === 'salt' ? 'ソルトの別問題 ' : '電子署名 ') . $v])->all()];
    }

    private function reasoning(): array
    {
        return ['id' => 'reasoning', 'type' => 'textarea', 'label' => '判断理由', 'required' => false];
    }
}
