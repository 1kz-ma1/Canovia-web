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
use App\Services\StudyLearningPlanPriorityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StudyLearningPlanPriorityV618Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array', 'cache.default' => 'array',
            'native_ai.driver' => 'disabled', 'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    private function scenario(): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'AP V618 学習優先',
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(3),
        ]);
        return [$owner, $plan];
    }

    private function task(Plan $plan, string $name, int $priority, string $status = 'doing'): Task
    {
        return Task::create([
            'plan_id' => $plan->id, 'title' => $name, 'estimated_minutes' => 40,
            'remaining_minutes' => 35, 'progress_percent' => $status === 'done' ? 100 : 15,
            'status' => $status, 'priority' => $priority, 'activation_cost' => 1,
            'sort_order' => 1, 'next_action_note' => '本人の既存Action',
        ]);
    }

    private function pack(string $topic, int $total): array
    {
        $pack = QuestionPack::create([
            'slug' => 'v618-'.Str::lower(Str::random(12)), 'title' => $topic.'問題',
            'exam_code' => 'AP', 'subject' => '科目A',
            'version' => '1', 'status' => 'published',
        ]);
        $questions = [];
        for ($i = 1; $i <= $total; $i++) {
            $questions[] = Question::create([
                'question_pack_id' => $pack->id, 'external_key' => (string) Str::uuid(),
                'source_type' => 'canovia_original', 'prompt' => $topic.' 問題 '.$i,
                'response_schema' => [['id' => 'answer', 'type' => 'single_choice',
                    'choices' => [['id' => 'A', 'label' => '正答'], ['id' => 'B', 'label' => '誤答']]]],
                'grading_rule' => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A'],
                'learning_metadata' => ['concepts' => [$topic]],
                'explanation' => '合成専用', 'sort_order' => $i, 'is_active' => true,
            ]);
        }
        return [$pack, $questions];
    }

    private function answer(User $user, Plan $plan, Task $task, QuestionPack $pack,
        Question $question, bool $correct, int $agoHours, string $mode = 'understanding'): void
    {
        $when = now()->subHours($agoHours);
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
            'ordinal' => 1,
            'question_snapshot' => ['prompt' => $question->prompt,
                'learning_metadata' => $question->learning_metadata],
            'grading_rule_snapshot' => $question->grading_rule,
            'explanation_snapshot' => $question->explanation,
        ]);
        LearningAnswerEvent::create([
            'learning_run_item_id' => $item->id,
            'request_id' => (string) Str::uuid(),
            'answer_value' => $correct ? 'A' : 'B', 'was_correct' => $correct,
            'grading_method' => 'question_bank_exact_choice',
            'answered_at' => $when,
        ]);
    }

    private function ranked(User $actor, Plan $plan, bool $canEdit = true): array
    {
        $request = Request::create('/workspace/study', 'GET');
        $request->setUserResolver(fn () => $actor);
        return app(StudyLearningPlanPriorityService::class)
            ->forWorkspace($request, $plan, null, $canEdit);
    }

    public function test_plan_queue_distinguishes_immediate_unseen_revisit_and_due_with_real_bank_availability(): void
    {
        [$user, $plan] = $this->scenario();
        $a = $this->task($plan, 'Network urgent', 5);
        $b = $this->task($plan, 'SQL revisit only', 1);
        $c = $this->task($plan, 'DNS due', 1);
        [$nwPack, $nw] = $this->pack('NW', 4);
        [$sqlPack, $sql] = $this->pack('SQL', 2);
        [$dnsPack, $dns] = $this->pack('DNS', 4);
        foreach ([[$a, $nwPack, $nw], [$b, $sqlPack, $sql], [$c, $dnsPack, $dns]] as $part) {
            [$task, $pack, $q] = $part;
            $this->answer($user, $plan, $task, $pack, $q[0], false, 100);
            $this->answer($user, $plan, $task, $pack, $q[1], false, 99);
        }
        $this->answer($user, $plan, $c, $dnsPack, $dns[0], true, 27);
        $this->answer($user, $plan, $c, $dnsPack, $dns[1], true, 26);

        $list = $this->ranked($user, $plan);
        $this->assertSame(3, $list['deadline_days']);
        $this->assertSame([$a->id, $b->id, $c->id],
            array_column($list['items'], 'task_id'));
        $this->assertSame(['repeat_errors_new_sources', 'repeat_errors_revisit', 'delayed_due'],
            array_column($list['items'], 'state'));
        $this->assertSame([true, true, true],
            array_column($list['items'], 'actionable'));
        $this->assertSame([2, 2, 2],
            array_column($list['items'], 'distinct_missed_questions'));
        $this->assertSame(0, $list['unavailable']);
        $this->assertSame(0, $list['waiting']);
        $this->assertSame(route('plans.tasks.learning.index', [$plan, $c]),
            $list['items'][2]['url']);

        $page = $this->actingAs($user)->get(route('workspace.study.index',
            ['plan_id' => $plan->id, 'surface' => 'work']))
            ->assertOk()->assertSee('data-study-learning-plan-priority', false)
            ->assertSee('data-study-plan-deadline-days="3"', false)
            ->assertSee('次に確認する学習候補');
        foreach ([$a, $b, $c] as $task) {
            $page->assertSee('data-study-plan-priority-task="'.$task->id.'"', false);
            $this->assertSame('本人の既存Action', $task->fresh()->next_action_note);
            $this->assertSame(15, $task->fresh()->progress_percent);
            $this->assertSame(35, $task->fresh()->remaining_minutes);
        }
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    public function test_pending_not_click_to_start_and_unavailable_never_claims_actionable(): void
    {
        [$user, $plan] = $this->scenario();
        $later = $this->task($plan, 'Wait 24h', 2);
        $blocked = $this->task($plan, 'Missing published Bank', 1);
        [$pack, $q] = $this->pack('NW', 4);
        [$other, $q2] = $this->pack('SQL', 2);
        foreach ([[$later, $pack, $q, 3], [$blocked, $other, $q2, 30]] as $entry) {
            [$task, $bank, $questions, $latestHours] = $entry;
            $this->answer($user, $plan, $task, $bank, $questions[0], false, 100);
            $this->answer($user, $plan, $task, $bank, $questions[1], false, 99);
            $this->answer($user, $plan, $task, $bank, $questions[0], true, $latestHours + 1);
            $this->answer($user, $plan, $task, $bank, $questions[1], true, $latestHours);
        }
        $items = $this->ranked($user, $plan)['items'];
        $this->assertSame([$later->id, $blocked->id],
            array_column($items, 'task_id'));
        $this->assertSame(['delayed_waiting', 'unavailable'],
            array_column($items, 'state'));
        $this->assertSame([false, false], array_column($items, 'actionable'));
        $this->assertSame(route('plans.tasks.learning.history', [$plan, $later]),
            $items[0]['url']);
        $this->assertSame(route('plans.tasks.learning.history', [$plan, $blocked]),
            $items[1]['url']);
        $this->assertSame(1, $this->ranked($user, $plan)['unavailable']);
        $this->assertSame(1, $this->ranked($user, $plan)['waiting']);
        $page = $this->actingAs($user)->get(route('workspace.study.index', [
            'plan_id' => $plan->id, 'surface' => 'work']))
            ->assertOk()->assertSee('data-study-plan-priority-actionable="false"', false)
            ->assertSee('未回答の別問題2問がありません');
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_scope_foreign_actor_exam_only_done_and_other_plan_do_not_leak(): void
    {
        [$user, $plan] = $this->scenario();
        $closed = $this->task($plan, 'closed', 1, 'done');
        $task = $this->task($plan, 'active', 1);
        [$bank, $q] = $this->pack('NW', 4);
        $other = User::factory()->create();
        $this->answer($user, $plan, $closed, $bank, $q[0], false, 12);
        $this->answer($user, $plan, $closed, $bank, $q[1], false, 11);
        $this->answer($other, $plan, $task, $bank, $q[0], false, 10);
        $this->answer($other, $plan, $task, $bank, $q[1], false, 9);
        $this->answer($user, $plan, $task, $bank, $q[0], false, 8, 'exam');
        $this->answer($user, $plan, $task, $bank, $q[1], false, 7, 'exam');
        $this->assertSame([], $this->ranked($user, $plan)['items']);
        $this->assertSame([], $this->ranked($user, $plan, false)['items']);
        $this->assertSame([], $this->ranked($other, $plan, false)['items']);
        $this->assertFalse($this->ranked($user, $plan)['has_history']);
        $this->assertSame(0, $this->ranked($user, $plan)['inspected']);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_no_plan_deadline_and_overdue_are_context_only_not_fake_scores(): void
    {
        [$user, $plan] = $this->scenario();
        $task = $this->task($plan, 'NW', 2);
        [$pack, $q] = $this->pack('NW', 4);
        $this->answer($user, $plan, $task, $pack, $q[0], false, 4);
        $this->answer($user, $plan, $task, $pack, $q[1], false, 3);
        $plan->update(['deadline' => null]);
        $this->assertNull($this->ranked($user, $plan->fresh())['deadline_days']);
        $plan->update(['deadline' => today()->subDays(2)]);
        $this->assertSame(-2, $this->ranked($user, $plan->fresh())['deadline_days']);
        $this->assertSame('repeat_errors_new_sources',
            $this->ranked($user, $plan->fresh())['items'][0]['state']);
    }
}
