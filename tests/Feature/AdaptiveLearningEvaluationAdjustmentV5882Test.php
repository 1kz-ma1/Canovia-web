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

final class AdaptiveLearningEvaluationAdjustmentV5882Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['session.driver'=>'array','native_ai.driver'=>'disabled',
            'canovia.super_admin_user_id'=>null,'canovia.admin_email'=>null]);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['first_run_completed_at'=>now()]);
        $plan = Plan::create(['user_id'=>$owner->id,'owner_token'=>Str::random(64),
            'public_slug'=>(string)Str::uuid(),'title'=>'テスト対策','category'=>'資格学習',
            'priority'=>3,'priority_mode'=>'manual','start_date'=>today(),'deadline'=>today()->addDays(30)]);
        $task = Task::create(['plan_id'=>$plan->id,'title'=>'選択問題',
            'estimated_minutes'=>20,'remaining_minutes'=>20,'progress_percent'=>0,
            'status'=>'todo','priority'=>3,'activation_cost'=>3,'sort_order'=>0]);
        $run = LearningRun::create(['plan_id'=>$plan->id,'task_id'=>$task->id,'user_id'=>$owner->id,
            'start_request_id'=>(string)Str::uuid(),'pack_title_snapshot'=>'Bank',
            'pack_version_snapshot'=>'v1','mode'=>'practice','status'=>'completed',
            'current_ordinal'=>1,'queue_policy_version'=>'bank_locked_v1',
            'started_at'=>now(),'completed_at'=>now()]);
        $item=LearningRunItem::create(['learning_run_id'=>$run->id,'ordinal'=>1,
            'question_snapshot'=>['prompt'=>'one'],
            'grading_rule_snapshot'=>['type'=>'exact_choice','field_id'=>'answer','answer'=>'A']]);
        $answer=LearningAnswerEvent::create(['learning_run_item_id'=>$item->id,
            'request_id'=>(string)Str::uuid(),'answer_value'=>'B','was_correct'=>false,
            'answered_at'=>now()]);
        return [$owner,$plan,$task,$run,$answer];
    }

    public function test_adjustment_is_append_only_idempotent_and_leaves_original_score_and_progress(): void
    {
        [$owner,$plan,$task,$run,$answer]=$this->fixture();
        $url=route('plans.tasks.learning.evaluation_adjustments.store',[$plan,$task,$answer]);
        $this->actingAs($owner)->post($url,['reason'=>'accidental_tap'])
            ->assertRedirect(route('plans.tasks.learning.show',[$plan,$task,$run]));
        $this->actingAs($owner)->post($url,['reason'=>'accidental_tap'])->assertRedirect();
        $this->assertDatabaseCount('learning_answer_evaluation_adjustments',1);
        $this->assertDatabaseCount('learning_answer_events',1);
        $this->assertFalse($answer->fresh()->was_correct);
        $this->assertSame('B',$answer->fresh()->answer_value);
        $this->assertSame(0,$task->fresh()->progress_percent);
        $this->assertSame(20,$task->fresh()->remaining_minutes);
        $this->assertDatabaseCount('study_practice_attempts',0);
    }

    public function test_only_authorized_actor_can_adjust_and_invalid_reason_is_rejected(): void
    {
        [$owner,$plan,$task,$run,$answer]=$this->fixture();
        $url=route('plans.tasks.learning.evaluation_adjustments.store',[$plan,$task,$answer]);
        $other=User::factory()->create();
        $this->actingAs($other)->post($url,['reason'=>'accidental_tap'])->assertForbidden();
        $this->actingAs($owner)->post($url,['reason'=>'rewrite_grade'])
            ->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('learning_answer_evaluation_adjustments',0);
        $this->actingAs($owner)->post($url,['reason'=>'accidental_tap'])->assertRedirect();
        $this->assertSame('exclude_from_recommendations',
            $answer->fresh()->evaluationAdjustment->effect);
    }

    public function test_adjusted_answer_does_not_count_as_mode_recommendation_evidence(): void
    {
        [$owner,$plan,$task,$run,$answer]=$this->fixture();
        $this->actingAs($owner)->post(
            route('plans.tasks.learning.evaluation_adjustments.store',[$plan,$task,$answer]),
            ['reason'=>'accidental_tap'])->assertRedirect();
        $ranking=app(AdaptiveLearningModeRecommendationService::class)
            ->forPlanTask(request(),$plan,$task,'unused',false);
        $this->assertStringContainsString('回答履歴がまだありません',$ranking['evidence']);
        $this->assertSame('understanding',$ranking['ranking'][0]['mode']);
        $this->assertDatabaseCount('learning_answer_events',1);
    }
}
