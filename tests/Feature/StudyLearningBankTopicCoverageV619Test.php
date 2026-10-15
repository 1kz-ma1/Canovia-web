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
use App\Services\StudyLearningBankTopicCoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StudyLearningBankTopicCoverageV619Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array', 'cache.default' => 'array',
            'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    private function plan(User $user, string $title = '応用情報技術者 科目A 合格'): array
    {
        $plan = Plan::create([
            'user_id' => $user->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => $title,
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addMonth(),
        ]);
        $task = Task::create([
            'plan_id' => $plan->id, 'title' => 'ネットワーク',
            'estimated_minutes' => 60, 'remaining_minutes' => 50,
            'progress_percent' => 15, 'status' => 'doing',
            'priority' => 2, 'sort_order' => 1, 'activation_cost' => 1,
            'next_action_note' => '本人Action',
        ]);
        return [$plan, $task];
    }

    private function pack(string $topic, int $count, string $examCode = 'AP',
        string $subject = '科目A', array $meta = []): array
    {
        $pack = QuestionPack::create([
            'slug' => 'v619-'.Str::lower(Str::random(12)),
            'title' => $topic.'問題集', 'exam_code' => $examCode,
            'subject' => $subject, 'version' => '1', 'status' => 'published',
            'metadata' => array_merge(['match_terms' => ['AP']], $meta),
        ]);
        $qs = [];
        for ($i = 1; $i <= $count; $i++) {
            $qs[] = Question::create([
                'question_pack_id' => $pack->id, 'external_key' => (string) Str::uuid(),
                'source_type' => 'canovia_original', 'prompt' => $topic.' 問題 '.$i,
                'response_schema' => [['id' => 'answer', 'type' => 'single_choice',
                    'choices' => [['id' => 'A', 'label' => 'A'], ['id' => 'B', 'label' => 'B']]]],
                'grading_rule' => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A'],
                'learning_metadata' => ['concepts' => [$topic]],
                'explanation' => 'テスト専用', 'sort_order' => $i, 'is_active' => true,
            ]);
        }
        return [$pack, $qs];
    }

    private function record(User $actor, Plan $plan, Task $task,
        QuestionPack $pack, Question $question, string $mode = 'understanding'): void
    {
        $run = LearningRun::create([
            'plan_id' => $plan->id, 'task_id' => $task->id, 'user_id' => $actor->id,
            'start_request_id' => (string) Str::uuid(),
            'question_pack_id' => $pack->id, 'pack_title_snapshot' => $pack->title,
            'pack_version_snapshot' => '1', 'mode' => $mode,
            'status' => 'completed', 'current_ordinal' => 1,
            'queue_policy_version' => 'bank_locked_v1',
            'started_at' => now(), 'completed_at' => now(),
        ]);
        $item = LearningRunItem::create([
            'learning_run_id' => $run->id, 'question_id' => $question->id,
            'ordinal' => 1, 'question_snapshot' => [
                'prompt' => $question->prompt,
                'learning_metadata' => $question->learning_metadata,
            ],
            'grading_rule_snapshot' => $question->grading_rule,
            'explanation_snapshot' => '検証専用',
        ]);
        LearningAnswerEvent::create([
            'learning_run_item_id' => $item->id,
            'request_id' => (string) Str::uuid(),
            'answer_value' => 'B', 'was_correct' => false,
            'grading_method' => 'question_bank_exact_choice',
            'answered_at' => now(),
        ]);
    }

    private function project(User $actor, Plan $plan, bool $editor = true): array
    {
        $request = Request::create('/workspace/study', 'GET');
        $request->setUserResolver(fn () => $actor);
        return app(StudyLearningBankTopicCoverageService::class)
            ->forWorkspace($request, $plan, null, $editor);
    }

    public function test_ap_tags_without_answers_are_not_claimed_weakness_and_exam_weight_is_not_inferred(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        [$plan, $task] = $this->plan($owner);
        [$network, $nw] = $this->pack('ネットワーク', 3);
        [$sql, $sq] = $this->pack('DB', 2);
        $this->pack('OTHER-EXAM', 8, 'FE', '科目A');
        $this->pack('AP-SECTION-B', 4, 'AP', '科目B');
        $this->pack('UNREVIEWED', 6, 'AP', '科目A', [
            'review_state' => 'pending_human_content_and_rights_review',
        ]);
        $result = $this->project($owner, $plan);
        $this->assertTrue($result['available']);
        $this->assertTrue($result['scope_confirmed']);
        $this->assertSame(80, $result['exam_question_count']);
        $this->assertSame(5, $result['source_question_total']);
        $this->assertSame(2, $result['eligible_pack_count']);
        $this->assertSame(1, $result['blocked_pack_count']);
        $this->assertSame(2, $result['unattested_pack_count']);
        $this->assertSame(['ネットワーク', 'DB'], array_column($result['rows'], 'topic'));
        $this->assertSame([3, 2], array_column($result['rows'], 'unanswered_source_questions'));
        $this->assertSame(['no_record', 'no_record'], array_column($result['rows'], 'state'));
        $page = $this->actingAs($owner)->get(route('workspace.study.index', [
            'plan_id' => $plan->id, 'surface' => 'analysis',
        ]))->assertOk()
            ->assertSee('data-study-learning-bank-coverage', false)
            ->assertSee('data-study-bank-scope-confirmed', false)
            ->assertSee('公式試験の出題率ではありません')
            ->assertSee('人的監修の承認をこの画面では確認できないBank 2件')
            ->assertDontSee('OTHER-EXAM')
            ->assertDontSee('UNREVIEWED');
        $this->actingAs($owner)->get(route('workspace.study.index', [
            'plan_id' => $plan->id, 'surface' => 'analysis',
        ]))->assertOk()->assertSee('data-study-learning-bank-coverage', false);
        $this->actingAs($owner)->get(route('workspace.study.index', [
            'plan_id' => $plan->id, 'surface' => 'work',
        ]))->assertOk()
            ->assertDontSee('data-study-learning-bank-coverage', false)
            ->assertSee('data-study-work-analysis-link', false);
        $this->assertSame(15, $task->fresh()->progress_percent);
        $this->assertSame('本人Action', $task->fresh()->next_action_note);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_actual_answer_source_ids_are_plan_scoped_even_when_exam_or_other_task(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $alien = User::factory()->create(['first_run_completed_at' => now()]);
        [$plan, $task] = $this->plan($owner);
        [$otherPlan, $otherTask] = $this->plan($owner, '応用情報 科目A その他');
        $secondTask = Task::create([
            'plan_id' => $plan->id, 'title' => '別のTask',
            'estimated_minutes' => 30, 'remaining_minutes' => 30,
            'progress_percent' => 0, 'status' => 'todo',
            'priority' => 2, 'sort_order' => 2, 'activation_cost' => 1,
        ]);
        [$pack, $q] = $this->pack('DNS', 4);
        $this->record($alien, $plan, $task, $pack, $q[0]);
        $this->record($owner, $otherPlan, $otherTask, $pack, $q[1]);
        $this->record($owner, $plan, $secondTask, $pack, $q[2], 'exam');
        $rows = $this->project($owner, $plan)['rows'];
        $this->assertSame(1, $rows[0]['answered_source_questions']);
        $this->assertSame(3, $rows[0]['unanswered_source_questions']);
        $this->assertSame('partial_record', $rows[0]['state']);
        $this->assertSame('no_record', $this->project($alien, $otherPlan)['rows'][0]['state']);
        $this->assertFalse($this->project($alien, $plan, false)['available']);
        $this->assertSame(0, $this->project($alien, $plan, false)['source_question_total']);
        $this->assertSame(15, $task->fresh()->progress_percent);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_ap_generic_is_explicitly_unconfirmed_and_section_b_or_other_exams_hidden(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        [$generic, $task] = $this->plan($owner, '応用情報技術者試験 合格');
        $this->pack('ネットワーク', 2);
        $summary = $this->project($owner, $generic);
        $this->assertTrue($summary['available']);
        $this->assertFalse($summary['scope_confirmed']);
        $this->actingAs($owner)->get(route('workspace.study.index', [
            'plan_id' => $generic->id, 'surface' => 'analysis',
        ]))->assertOk()->assertSee('data-study-bank-scope-unconfirmed', false);
        [$bPlan] = $this->plan($owner, '応用情報 科目B 合格');
        $this->assertFalse($this->project($owner, $bPlan)['available']);
        [$fePlan] = $this->plan($owner, '基本情報技術者 科目A');
        $this->assertFalse($this->project($owner, $fePlan)['available']);
        $this->actingAs($owner)->get(route('workspace.study.index', [
            'plan_id' => $fePlan->id, 'surface' => 'analysis',
        ]))->assertOk()->assertDontSee('data-study-learning-bank-coverage', false);
    }

    public function test_published_but_pending_review_cannot_create_false_sample_availability(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        [$plan] = $this->plan($owner);
        $this->pack('UNREVIEWED', 3, 'AP', '科目A', [
            'review_state' => 'pending_human_content_and_rights_review',
        ]);
        $res = $this->project($owner, $plan);
        $this->assertTrue($res['available']);
        $this->assertSame([], $res['rows']);
        $this->assertSame(1, $res['blocked_pack_count']);
        $this->assertSame(0, $res['source_question_total']);
        $this->actingAs($owner)->get(route('workspace.study.index', [
            'plan_id' => $plan->id, 'surface' => 'analysis',
        ]))->assertOk()->assertSee('data-study-bank-coverage-empty', false);
    }
}
