<?php
namespace Tests\Feature;

use App\Models\LearningAnswerEvent;
use App\Models\LearningRun;
use App\Models\LearningRunItem;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\AdaptiveLearningModeRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdaptiveLearningModeRecommendationV5881Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array', 'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null, 'canovia.admin_email' => null,
            'study.adaptive_learning.ranking_evidence_min' => 6,
        ]);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => '資格試験の準備',
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(40),
            'is_public' => false,
        ]);
        $task = Task::create([
            'plan_id' => $plan->id, 'title' => '練習',
            'estimated_minutes' => 20, 'remaining_minutes' => 20,
            'progress_percent' => 0, 'status' => 'todo', 'priority' => 3,
            'activation_cost' => 2, 'sort_order' => 0,
        ]);
        return [$owner, $plan, $task];
    }

    private function seedEvents(User $owner, Plan $plan, Task $task, array $scores, int $sessions = 2): void
    {
        $runs = [];
        for ($i = 0; $i < $sessions; $i++) {
            $runs[] = LearningRun::create([
                'plan_id' => $plan->id, 'task_id' => $task->id,
                'user_id' => $owner->id, 'start_request_id' => (string) Str::uuid(),
                'pack_title_snapshot' => 'Test Pack', 'pack_version_snapshot' => 'v1',
                'mode' => 'practice', 'status' => 'completed',
                'current_ordinal' => 1, 'queue_policy_version' => 'bank_locked_v1',
                'started_at' => now(), 'completed_at' => now(),
            ]);
        }
        foreach ($scores as $i => $correct) {
            $run = $runs[$i % count($runs)];
            $item = LearningRunItem::create([
                'learning_run_id' => $run->id, 'ordinal' => $i + 1,
                'question_snapshot' => ['prompt' => 'Question '.$i],
                'grading_rule_snapshot' => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A'],
            ]);
            LearningAnswerEvent::create([
                'learning_run_item_id' => $item->id,
                'request_id' => (string) Str::uuid(),
                'answer_value' => $correct ? 'A' : 'B',
                'was_correct' => $correct, 'answered_at' => now(),
            ]);
        }
    }

    public function test_no_history_shows_explainable_optional_ranking_without_fake_confidence(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $response = $this->actingAs($owner)->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertSee('data-learning-mode-ranking', false)
            ->assertSee('1位：理解モード')
            ->assertSee('2位：演習モード')
            ->assertSee('3位：模擬試験モード')
            ->assertSee('現在は未対応')
            ->assertSee('診断は必須ではありません')
            ->assertDontSee('おすすめ度');
        $this->assertDatabaseCount('learning_runs', 0);
        $this->assertSame(0, $task->fresh()->progress_percent);
    }

    public function test_single_success_or_one_session_cannot_automatically_imply_mastery(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $this->seedEvents($owner, $plan, $task, [true,true,true,true,true,true], 1);
        // Even six correct answers from one Run are not independent-session evidence.
        $this->actingAs($owner)->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertSee('1位：理解モード');
        $this->assertSame(0, $task->fresh()->progress_percent);
    }

    public function test_repeated_answers_across_sessions_can_recommend_practice_without_forcing_it(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $this->seedEvents($owner, $plan, $task, [true,true,true,true,false,true], 2);
        $this->actingAs($owner)->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertSee('1位：演習モード')
            ->assertSee('2位：理解モード')
            ->assertSee('習熟を保証するものではありません')
            ->assertSee('mode', false);
        $ranked = app(AdaptiveLearningModeRecommendationService::class)
            ->forPlanTask(request(), $plan, $task, 'not-used-logged-in', false);
        $this->assertSame(['practice', 'understanding', 'exam'],
            array_column($ranked['ranking'], 'mode'));
        $this->assertFalse($ranked['ranking'][2]['available']);
        $this->assertSame(0, $task->fresh()->progress_percent);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    public function test_bad_performance_keeps_explanation_first_and_other_plan_is_not_read(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $this->seedEvents($owner, $plan, $task, [false,false,false,true,false,false], 2);
        $this->actingAs($owner)->get(route('plans.tasks.learning.index', [$plan, $task]))
            ->assertOk()->assertSee('1位：理解モード')
            ->assertSee('直近の複数回答を見直し');
        $other = $this->fixture();
        $this->actingAs($other[0])->get(route('plans.tasks.learning.index', [$other[1], $other[2]]))
            ->assertOk()->assertSee('回答履歴がまだありません')
            ->assertSee('1位：理解モード');
    }

    public function test_profile_availability_is_explained_but_mode_order_remains_voluntary(): void
    {
        [$owner, $plan, $task] = $this->fixture();
        $this->actingAs($owner);
        $r = app(AdaptiveLearningModeRecommendationService::class)
            ->forPlanTask(request(), $plan, $task, 'unused', true);
        $this->assertTrue($r['ranking'][2]['available']);
        $this->assertStringContainsString('検証済みの試験仕様', $r['ranking'][2]['reason']);
    }
}
