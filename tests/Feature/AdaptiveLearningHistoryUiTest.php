<?php

namespace Tests\Feature;

use App\Models\LearningAnswerEvaluationAdjustment;
use App\Models\LearningAnswerEvent;
use App\Models\LearningRun;
use App\Models\LearningRunItem;
use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdaptiveLearningHistoryUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array', 'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null, 'canovia.admin_email' => null,
        ]);
    }

    private function fixture(string $mode = 'understanding'): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'APテスト計画', 'category' => '資格学習',
            'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(30),
        ]);
        $task = Task::create([
            'plan_id' => $plan->id, 'title' => 'ネットワーク',
            'estimated_minutes' => 25, 'remaining_minutes' => 25,
            'progress_percent' => 35, 'status' => 'doing',
            'priority' => 2, 'activation_cost' => 2, 'sort_order' => 1,
        ]);
        $pack = QuestionPack::create([
            'slug' => 'history-'.Str::lower(Str::random(12)),
            'title' => '履歴テスト問題集', 'exam_code' => 'AP',
            'subject' => '科目A', 'version' => '1', 'status' => 'draft',
        ]);
        $run = LearningRun::create([
            'plan_id' => $plan->id, 'task_id' => $task->id,
            'user_id' => $owner->id, 'start_request_id' => (string) Str::uuid(),
            'question_pack_id' => $pack->id,
            'pack_title_snapshot' => '履歴テスト問題集',
            'pack_version_snapshot' => '1',
            'mode' => $mode, 'status' => 'completed',
            'current_ordinal' => 1, 'queue_policy_version' => 'bank_locked_v1',
            'started_at' => now(), 'completed_at' => now(),
        ]);
        return [$owner, $plan, $task, $run, $pack];
    }

    private function answer(
        LearningRun $run, QuestionPack $pack, bool $correct,
        string $topic = 'ネットワーク', ?Question $question = null,
        ?string $reasoning = null,
    ): LearningAnswerEvent {
        $ordinal = 1 + (int) $run->items()->max('ordinal');
        $question ??= Question::create([
            'question_pack_id' => $pack->id,
            'external_key' => (string) Str::uuid(),
            'source_type' => 'canovia_original',
            'prompt' => '理解を確認する問題 '.$ordinal,
            'response_schema' => [[
                'id' => 'answer', 'type' => 'single_choice',
                'choices' => [['id' => 'A', 'label' => '正答'], ['id' => 'B', 'label' => '誤答']],
            ]],
            'grading_rule' => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A'],
            'explanation' => 'ネットワークの解説',
            'learning_metadata' => ['concepts' => [$topic]],
            'sort_order' => $ordinal, 'is_active' => true,
        ]);
        $item = LearningRunItem::create([
            'learning_run_id' => $run->id, 'question_id' => $question->id,
            'ordinal' => $ordinal,
            'question_snapshot' => [
                'prompt' => '問題'.$ordinal.' - '.$topic,
                'response_field' => ['type' => 'single_choice'],
                'learning_metadata' => ['concepts' => [$topic]],
            ],
            'grading_rule_snapshot' => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A'],
            'explanation_snapshot' => 'ネットワークの解説',
        ]);
        $payload = ['type' => 'single_choice', 'value' => $correct ? 'A' : 'B'];
        if ($reasoning !== null) $payload['reasoning'] = $reasoning;
        return LearningAnswerEvent::create([
            'learning_run_item_id' => $item->id,
            'request_id' => (string) Str::uuid(),
            'answer_value' => $correct ? 'A' : 'B',
            'answer_payload' => $payload,
            'was_correct' => $correct,
            'grading_method' => 'question_bank_exact_choice',
            'answered_at' => now(),
        ]);
    }

    public function test_empty_history_has_clear_entry_and_does_not_change_task_state(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $url = route('plans.tasks.learning.history', [$plan, $task]);
        $this->actingAs($owner)->get($url)->assertOk()
            ->assertSee('data-learning-history', false)
            ->assertSee('まだ新方式の回答履歴がありません')
            ->assertSee('繰り返しの誤答に基づく復習候補はありません')
            ->assertDontSee('合格確率');
        $this->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertSee('data-learning-history-link', false)
            ->assertSee('data-learning-history-overview', false)
            ->assertSee('data-learning-mode-ranking', false)
            ->assertSee('単一選択・複数選択・数値問題');
        $this->assertSame(35, $task->fresh()->progress_percent);
        $this->assertDatabaseCount('learning_answer_events', 0);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    public function test_repeated_misses_on_distinct_questions_yield_a_review_hint_not_a_mastery_claim(): void
    {
        [$owner, $plan, $task, $run, $pack] = $this->fixture();
        $this->answer($run, $pack, false);
        $url = route('plans.tasks.learning.history', [$plan, $task]);
        $this->actingAs($owner)->get($url)->assertOk()
            ->assertDontSee('data-learning-review-topic>', false);
        $this->answer($run, $pack, false);
        $this->get($url)->assertOk()
            ->assertSee('data-learning-review-topic', false)
            ->assertSee('ネットワーク')
            ->assertSee('2件 / 不正解 2件')
            ->assertSee('苦手')
            ->assertDontSee('合格可能性が高い');
        $this->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertSee('ネットワーク：復習候補');

        // New correct answers remove the tentative review hint, not past failures.
        $this->answer($run, $pack, true);
        $this->answer($run, $pack, true);
        $this->get($url)->assertOk()
            ->assertDontSee('data-learning-review-topic>', false)
            ->assertSee('4件');
        $this->assertDatabaseCount('learning_answer_events', 4);
        $this->assertSame(35, $task->fresh()->progress_percent);
    }

    public function test_duplicate_question_and_adjusted_answers_cannot_become_repeated_weakness(): void
    {
        [$owner, $plan, $task, $run, $pack] = $this->fixture();
        $first = $this->answer($run, $pack, false);
        $this->answer($run, $pack, false);
        LearningAnswerEvaluationAdjustment::create([
            'learning_answer_event_id' => $first->id,
            'user_id' => $owner->id,
            'reason' => 'accidental_tap',
            'effect' => 'exclude_from_recommendations',
            'created_at' => now(),
        ]);
        $url = route('plans.tasks.learning.history', [$plan, $task]);
        $this->actingAs($owner)->get($url)->assertOk()
            ->assertSee('評価から除外した回答')
            ->assertSee('復習集計から除外')
            ->assertDontSee('data-learning-review-topic>', false);

        [$other, $otherPlan, $otherTask, $otherRun, $otherPack] = $this->fixture();
        $this->answer($otherRun, $otherPack, false, '非公開の別人の分野');
        $this->actingAs($owner)->get($url)->assertOk()
            ->assertDontSee('非公開の別人の分野');
        $this->actingAs($other)->get(route('plans.tasks.learning.history', [$plan, $task]))
            ->assertForbidden();
        $this->actingAs($owner)->get(route('plans.tasks.learning.history', [$otherPlan, $otherTask]))
            ->assertForbidden();
    }

    public function test_same_question_missed_twice_does_not_fake_independent_evidence(): void
    {
        [$owner, $plan, $task, $run, $pack] = $this->fixture();
        $first = $this->answer($run, $pack, false);
        [$secondOwner, , , $secondRun] = $this->fixture();
        // Different Run of the same owner/Plan/Task, same source question.
        $secondRun->update(['user_id' => $owner->id, 'plan_id' => $plan->id, 'task_id' => $task->id]);
        $source = Question::findOrFail($first->item->question_id);
        $this->answer($secondRun, $pack, false, 'ネットワーク', $source);
        $this->actingAs($owner)->get(route('plans.tasks.learning.history', [$plan, $task]))
            ->assertOk()->assertDontSee('data-learning-review-topic>', false);
    }

    public function test_note_is_escaped_and_exam_answer_is_never_exposed_in_study_history(): void
    {
        [$owner, $plan, $task, $run, $pack] = $this->fixture();
        $this->answer($run, $pack, false, '<img src=x onerror=alert(1)>', null,
            '<script>alert(1)</script>');
        $exam = LearningRun::create([
            'plan_id' => $plan->id, 'task_id' => $task->id,
            'user_id' => $owner->id, 'start_request_id' => (string) Str::uuid(),
            'question_pack_id' => $pack->id,
            'pack_title_snapshot' => '秘匿模試', 'pack_version_snapshot' => '1',
            'mode' => 'exam', 'status' => 'active',
            'current_ordinal' => 1, 'started_at' => now(),
        ]);
        $this->answer($exam, $pack, false, '模試秘密', null, '秘密の回答');
        $this->actingAs($owner)->get(route('plans.tasks.learning.history', [$plan, $task]))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('秘密の回答')
            ->assertDontSee('模試秘密')
            ->assertDontSee('秘匿模試')
            ->assertSee('data-learning-history-detail', false);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }
}
