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
use App\Services\StudyLearningNextActionQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StudyLearningReviewQueueV612Test extends TestCase
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

    private function scenario(): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'AP計画V612',
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addMonth(),
        ]);
        $pack = QuestionPack::create([
            'slug' => 'v612-'.Str::lower(Str::random(10)), 'title' => '合成問題',
            'exam_code' => 'AP', 'subject' => '科目A', 'version' => '1',
            'status' => 'published',
        ]);
        return [$owner, $plan, $pack];
    }

    private function task(Plan $plan, string $title, int $priority = 3, string $status = 'doing'): Task
    {
        return Task::create([
            'plan_id' => $plan->id, 'title' => $title,
            'estimated_minutes' => 50, 'remaining_minutes' => 35,
            'progress_percent' => $status === 'done' ? 100 : 20,
            'status' => $status, 'priority' => $priority,
            'activation_cost' => 2, 'sort_order' => 1,
            'next_action_note' => '既存の予定',
        ]);
    }

    private function record(
        User $user,
        Plan $plan,
        Task $task,
        QuestionPack $pack,
        string $topic,
        bool $correct = false,
        ?Question $question = null,
        string $mode = 'understanding',
    ): Question {
        $question ??= Question::create([
            'question_pack_id' => $pack->id, 'external_key' => (string) Str::uuid(),
            'source_type' => 'canovia_original', 'prompt' => 'V612独立した問題',
            'response_schema' => [['id' => 'answer', 'type' => 'single_choice',
                'choices' => [['id' => 'A', 'label' => '正答'], ['id' => 'B', 'label' => '誤答']]]],
            'grading_rule' => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A'],
            'learning_metadata' => ['concepts' => [$topic]],
            'explanation' => '検証専用', 'sort_order' => 1, 'is_active' => true,
        ]);
        $run = LearningRun::create([
            'plan_id' => $plan->id, 'task_id' => $task->id,
            'user_id' => $user->id, 'start_request_id' => (string) Str::uuid(),
            'question_pack_id' => $pack->id, 'pack_title_snapshot' => $pack->title,
            'pack_version_snapshot' => '1', 'mode' => $mode,
            'status' => 'completed', 'current_ordinal' => 1,
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
        LearningAnswerEvent::create([
            'learning_run_item_id' => $item->id, 'request_id' => (string) Str::uuid(),
            'answer_value' => $correct ? 'A' : 'B', 'was_correct' => $correct,
            'grading_method' => 'question_bank_exact_choice', 'answered_at' => now(),
        ]);
        return $question;
    }

    private function queue(User $user, Plan $plan, bool $canEdit = true): array
    {
        $request = Request::create('/workspace/study', 'GET');
        $request->setUserResolver(fn () => $user);
        return app(StudyLearningNextActionQueueService::class)->forWorkspace(
            $request, $plan, null, $canEdit,
        );
    }

    public function test_distinct_misses_rank_multiple_tasks_then_owner_confirms_from_history(): void
    {
        [$owner, $plan, $pack] = $this->scenario();
        $a = $this->task($plan, 'ネットワーク', 1);
        $b = $this->task($plan, 'データベース', 5);
        $c = $this->task($plan, 'アルゴリズム', 1);
        $done = $this->task($plan, '完了済み', 1, 'done');
        foreach ([[$a, 'NW', 2], [$b, 'SQL', 3], [$c, 'SORT', 2], [$done, '完了', 3]] as [$task, $topic, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $this->record($owner, $plan, $task, $pack, $topic);
            }
        }

        $this->assertSame([$b->id, $a->id, $c->id],
            array_column($this->queue($owner, $plan)['items'], 'task_id'));
        $this->assertSame([3, 2, 2],
            array_column($this->queue($owner, $plan)['items'], 'distinct_missed_questions'));

        $page = $this->actingAs($owner)->get(route('workspace.study.index', [
            'plan_id' => $plan->id, 'surface' => 'work',
        ]))->assertOk()->assertSee('data-study-learning-review-queue', false);
        $page->assertSee('data-study-review-task-id="'.$b->id.'"', false)
            ->assertSee(route('plans.tasks.learning.history', [$plan, $b]), false)
            ->assertDontSee('data-study-review-task-id="'.$done->id.'"', false)
            ->assertSee('復習候補を確認する');
        $this->assertSame('既存の予定', $b->fresh()->next_action_note);
        $this->assertSame(20, $b->fresh()->progress_percent);

        // V6.11 existing history approval is the only write path.
        $this->actingAs($owner)->get(route('plans.tasks.learning.history', [$plan, $b]))
            ->assertOk()->assertSee('data-learning-next-action-preview', false);
        $request = Request::create('/learning/history', 'GET');
        $request->setUserResolver(fn () => $owner);
        $preview = app(\App\Services\AdaptiveLearningHistoryService::class)
            ->forPlanTask($request, $plan, $b, '')['next_action_preview'];
        $this->post(route('plans.tasks.learning.next_action.confirm', [$plan, $b]), [
            'source_signature' => $preview['source_signature'],
            'expected_next_action_hash' => $preview['expected_next_action_hash'],
        ])->assertRedirect();

        $after = $this->queue($owner, $plan)['items'];
        $this->assertSame([$a->id, $c->id, $b->id], array_column($after, 'task_id'));
        $this->assertTrue($after[2]['already_adopted']);
        $this->actingAs($owner)->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()->assertSee('data-study-review-adopted="true"', false)
            ->assertSee('data-study-review-applied', false);

        $this->assertSame(20, $b->fresh()->progress_percent);
        $this->assertSame(35, $b->fresh()->remaining_minutes);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    public function test_latest_two_correct_reopen_breadth_and_same_question_is_not_independent(): void
    {
        [$owner, $plan, $pack] = $this->scenario();
        $a = $this->task($plan, 'ネットワーク');
        $question = $this->record($owner, $plan, $a, $pack, 'DNS');
        $this->record($owner, $plan, $a, $pack, 'DNS', false, $question);
        $this->assertSame([], $this->queue($owner, $plan)['items']);
        $this->record($owner, $plan, $a, $pack, 'DNS');
        $this->assertSame([$a->id], array_column($this->queue($owner, $plan)['items'], 'task_id'));
        $this->record($owner, $plan, $a, $pack, 'DNS', true);
        $this->record($owner, $plan, $a, $pack, 'DNS', true);
        $this->assertSame([], $this->queue($owner, $plan)['items']);
    }

    public function test_actor_plan_view_and_exam_isolation_with_no_unapproved_action_writes(): void
    {
        [$owner, $plan, $pack] = $this->scenario();
        $task = $this->task($plan, 'ネットワーク');
        $intruder = User::factory()->create(['first_run_completed_at' => now()]);
        $otherPlan = Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => '別の資格学習',
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addMonth(),
        ]);
        $otherTask = $this->task($otherPlan, '別Plan');
        foreach ([1, 2, 3] as $_) {
            $this->record($intruder, $plan, $task, $pack, '他人だけのテーマ');
            $this->record($owner, $otherPlan, $otherTask, $pack, '他Planのテーマ');
            $this->record($owner, $plan, $task, $pack, '模擬試験のみ', false, null, 'exam');
        }
        $this->assertSame([], $this->queue($owner, $plan)['items']);
        $this->assertSame([], $this->queue($owner, $plan, false)['items']);
        $this->actingAs($owner)->get(route('workspace.study.index', [
            'plan_id' => $plan->id, 'surface' => 'work',
        ]))->assertOk()->assertDontSee('data-study-learning-review-queue', false)
          ->assertDontSee('他人だけのテーマ')->assertDontSee('他Planのテーマ');

        $this->actingAs($intruder)->get(route('workspace.study.index', [
            'plan_id' => $plan->id,
        ]))->assertNotFound();
        $this->actingAs($owner)->get(route('workspace.study.index', [
            'plan_id' => $otherPlan->id, 'surface' => 'analysis',
        ]))->assertOk()->assertDontSee('data-study-learning-review-queue', false);
        $this->assertSame('既存の予定', $task->fresh()->next_action_note);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_only_work_surface_displays_valid_queue_without_mutation(): void
    {
        [$owner, $plan, $pack] = $this->scenario();
        $task = $this->task($plan, 'DNS');
        $this->record($owner, $plan, $task, $pack, '<script>alert(1)</script>');
        $this->record($owner, $plan, $task, $pack, '<script>alert(1)</script>');
        $work = $this->actingAs($owner)->get(route('workspace.study.index', [
            'plan_id' => $plan->id, 'surface' => 'work',
        ]))->assertOk()->assertSee('data-study-learning-review-queue', false)
          ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
          ->assertDontSee('<script>alert(1)</script>', false);
        foreach (['analysis', 'preparation', 'history'] as $surface) {
            $this->get(route('workspace.study.index', [
                'plan_id' => $plan->id, 'surface' => $surface,
            ]))->assertOk()->assertDontSee('data-study-learning-review-queue', false);
        }
        $this->assertSame('既存の予定', $task->fresh()->next_action_note);
    }
}
