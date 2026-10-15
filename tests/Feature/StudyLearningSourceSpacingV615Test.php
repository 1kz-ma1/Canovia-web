<?php

namespace Tests\Feature;

use App\Models\LearningAnswerEvaluationAdjustment;
use App\Models\LearningAnswerEvent;
use App\Models\LearningRun;
use App\Models\LearningRunItem;
use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\Task;
use App\Models\User;
use App\Services\AdaptiveLearningHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StudyLearningSourceSpacingV615Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['session.driver'=>'array','native_ai.driver'=>'disabled',
            'canovia.super_admin_user_id'=>null,'canovia.admin_email'=>null]);
    }

    private function fixtures(): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id'=>$owner->id,'owner_token'=>Str::random(64),
            'public_slug'=>(string) Str::uuid(), 'title'=>'V615 学習履歴',
            'category'=>'資格学習','priority'=>3,'priority_mode'=>'manual',
            'start_date'=>today(),'deadline'=>today()->addMonth(),
        ]);
        $task = Task::create([
            'plan_id'=>$plan->id,'title'=>'NW','estimated_minutes'=>60,
            'remaining_minutes'=>50,'progress_percent'=>12,'status'=>'doing',
            'priority'=>2,'activation_cost'=>1,'sort_order'=>1,
            'next_action_note'=>'既存の本人Action',
        ]);
        $pack = QuestionPack::create([
            'slug'=>'v615-'.Str::lower(Str::random(12)),'title'=>'V615 公開問題',
            'exam_code'=>'AP','subject'=>'科目A','version'=>'1','status'=>'published',
        ]);
        $questions = [];
        for($i=1;$i<=4;$i++){
            $questions[] = Question::create([
                'question_pack_id'=>$pack->id,'external_key'=>(string) Str::uuid(),
                'source_type'=>'canovia_original','prompt'=>"ネットワーク問題 {$i}",
                'response_schema'=>[['id'=>'answer','type'=>'single_choice',
                    'choices'=>[['id'=>'A','label'=>'正答'],['id'=>'B','label'=>'誤答']]]],
                'grading_rule'=>['type'=>'exact_choice','field_id'=>'answer','answer'=>'A'],
                'learning_metadata'=>['concepts'=>['ネットワーク']],
                'explanation'=>'テスト','sort_order'=>$i,'is_active'=>true,
            ]);
        }
        return [$owner,$plan,$task,$pack,$questions];
    }

    private function makeRun(User $owner, Plan $plan, Task $task, QuestionPack $pack,
        bool $focused = false, string $mode = 'understanding'): LearningRun
    {
        return LearningRun::create([
            'plan_id'=>$plan->id,'task_id'=>$task->id,'user_id'=>$owner->id,
            'start_request_id'=>(string) Str::uuid(), 'question_pack_id'=>$pack->id,
            'pack_title_snapshot'=>$pack->title,'pack_version_snapshot'=>'1',
            'mode'=>$mode,'status'=>'completed','current_ordinal'=>2,
            'queue_policy_version'=>$focused ? 'bank_topic_review_initial_v613' : 'bank_locked_v1',
            'started_at'=>now(),'completed_at'=>now(),
        ]);
    }

    private function record(LearningRun $run, Question $q, int $ordinal,
        bool $correct, $at, bool $exclude = false): LearningAnswerEvent
    {
        $item = LearningRunItem::create([
            'learning_run_id'=>$run->id,'question_id'=>$q->id,'ordinal'=>$ordinal,
            'question_snapshot'=>[
                'prompt'=>$q->prompt,'learning_metadata'=>$q->learning_metadata,
            ],
            'grading_rule_snapshot'=>$q->grading_rule,
            'explanation_snapshot'=>'synthetic',
        ]);
        $answer = LearningAnswerEvent::create([
            'learning_run_item_id'=>$item->id,'request_id'=>(string) Str::uuid(),
            'answer_value'=>$correct ? 'A' : 'B', 'was_correct'=>$correct,
            'grading_method'=>'question_bank_exact_choice',
            'answered_at'=>$at,
        ]);
        if($exclude){
            LearningAnswerEvaluationAdjustment::create([
                'learning_answer_event_id'=>$answer->id,'user_id'=>$run->user_id,
                'reason'=>'accidental_tap','effect'=>'exclude_from_recommendations',
                'created_at'=>now(),
            ]);
        }
        return $answer;
    }

    private function history(User $owner, Plan $plan, Task $task): array
    {
        $req = Request::create('/learning/history','GET');
        $req->setUserResolver(fn () => $owner);
        return app(AdaptiveLearningHistoryService::class)
            ->forPlanTask($req, $plan, $task, '');
    }

    public function test_two_different_source_questions_after_over_24_hours_are_factual_not_mastery(): void
    {
        [$owner,$plan,$task,$pack,$q] = $this->fixtures();
        $prior = $this->makeRun($owner,$plan,$task,$pack);
        $this->record($prior,$q[0],1,false,now()->subDays(3));
        $this->record($prior,$q[1],2,false,now()->subHours(49));
        $focused = $this->makeRun($owner,$plan,$task,$pack,true);
        $this->record($focused,$q[2],1,true,now());
        $this->record($focused,$q[3],2,true,now());
        $s = $this->history($owner,$plan,$task)['review_comparisons'][0];
        $facts = $s['source_spacing'];
        $this->assertSame('ネットワーク',$s['topic']);
        $this->assertSame(['different_source','different_source'],
            array_column($s['answers'],'source_relation'));
        $this->assertSame(2,$facts['different_count']);
        $this->assertSame(0,$facts['same_count']);
        $this->assertSame('spaced_24h',$facts['spacing']);
        $this->assertSame(49,$facts['hours_since_previous']);
        $this->actingAs($owner)->get(route('plans.tasks.learning.history',[$plan,$task]))
            ->assertOk()->assertSee('data-learning-review-source-spacing',false)
            ->assertSee('data-learning-review-spaced',false)
            ->assertSee('別問題での再確認 2問')
            ->assertSee('同じ問題の解き直し 0問')
            ->assertSee('約49時間');
        $this->assertSame(12,$task->fresh()->progress_percent);
        $this->assertSame(50,$task->fresh()->remaining_minutes);
        $this->assertSame('既存の本人Action',$task->fresh()->next_action_note);
        $this->assertDatabaseCount('task_evidences',0);
        $this->assertDatabaseCount('study_practice_attempts',0);
    }

    public function test_repeated_same_questions_within_a_day_are_not_distinct(): void
    {
        [$owner,$plan,$task,$pack,$q] = $this->fixtures();
        $prior = $this->makeRun($owner,$plan,$task,$pack);
        $this->record($prior,$q[0],1,false,now()->subHours(3));
        $this->record($prior,$q[1],2,false,now()->subHours(2));
        $focus = $this->makeRun($owner,$plan,$task,$pack,true);
        $this->record($focus,$q[0],1,true,now());
        $this->record($focus,$q[1],2,true,now());
        $s = $this->history($owner,$plan,$task)['review_comparisons'][0];
        $this->assertSame(['same_source','same_source'],
            array_column($s['answers'],'source_relation'));
        $this->assertSame(2,$s['source_spacing']['same_count']);
        $this->assertSame(0,$s['source_spacing']['different_count']);
        $this->assertSame('within_24h',$s['source_spacing']['spacing']);
        $this->assertSame(2,$s['source_spacing']['hours_since_previous']);
        $this->actingAs($owner)->get(route('plans.tasks.learning.history',[$plan,$task]))
            ->assertOk()->assertSee('data-learning-review-within-day',false)
            ->assertSee('同じ問題の解き直し 2問');
    }

    public function test_excluded_old_exposure_prevents_false_new_source_and_excluded_current_is_unknown(): void
    {
        [$owner,$plan,$task,$pack,$q] = $this->fixtures();
        $prior = $this->makeRun($owner,$plan,$task,$pack);
        $this->record($prior,$q[0],1,false,now()->subDays(2),true);
        $this->record($prior,$q[1],2,false,now()->subHours(50));
        $focus = $this->makeRun($owner,$plan,$task,$pack,true);
        $this->record($focus,$q[0],1,true,now());
        $this->record($focus,$q[2],2,true,now(),true);
        $facts = $this->history($owner,$plan,$task)['review_comparisons'][0];
        $this->assertSame(['same_source','unknown'],
            array_column($facts['answers'],'source_relation'));
        $this->assertSame(1,$facts['source_spacing']['same_count']);
        $this->assertSame(0,$facts['source_spacing']['different_count']);
        $this->assertSame(1,$facts['source_spacing']['unknown_count']);
        $this->assertSame('spaced_24h',$facts['source_spacing']['spacing']);
    }

    public function test_other_actors_tasks_exam_and_out_of_order_times_do_not_establish_spacing(): void
    {
        [$owner,$plan,$task,$pack,$q] = $this->fixtures();
        $alien = User::factory()->create();
        $this->record($this->makeRun($alien,$plan,$task,$pack),$q[0],1,false,now()->subDays(4));
        $other = Task::create([
            'plan_id'=>$plan->id,'title'=>'他Task','estimated_minutes'=>30,
            'remaining_minutes'=>30,'progress_percent'=>0,'status'=>'todo',
            'priority'=>2,'activation_cost'=>1,'sort_order'=>2,
        ]);
        $this->record($this->makeRun($owner,$plan,$other,$pack),$q[1],1,false,now()->subDays(3));
        $this->record($this->makeRun($owner,$plan,$task,$pack,false,'exam'),
            $q[1],1,false,now()->subDays(2));
        $focus = $this->makeRun($owner,$plan,$task,$pack,true);
        $this->record($focus,$q[2],1,true,now());
        $this->record($focus,$q[3],2,true,now());
        $s = $this->history($owner,$plan,$task)['review_comparisons'][0];
        $this->assertSame(['unknown','unknown'],array_column($s['answers'],'source_relation'));
        $this->assertSame('unknown',$s['source_spacing']['spacing']);
        $this->assertNull($s['source_spacing']['hours_since_previous']);
        $this->actingAs($alien)
            ->get(route('plans.tasks.learning.history',[$plan,$task]))->assertForbidden();

        // Even a prior Event ID does not prove earlier *time* if timestamps
        // are reversed (import, clock skew or stale client write).
        $old = $this->makeRun($owner,$plan,$task,$pack);
        $this->record($old,$q[0],1,false,now()->addDays(4));
        $newFocus = $this->makeRun($owner,$plan,$task,$pack,true);
        $this->record($newFocus,$q[2],1,true,now());
        $this->record($newFocus,$q[3],2,true,now());
        $latest = $this->history($owner,$plan,$task)['review_comparisons'][0];
        $this->assertSame('unknown',$latest['source_spacing']['spacing']);
    }

    public function test_single_answer_and_no_unique_topic_fail_closed(): void
    {
        [$owner,$plan,$task,$pack,$q] = $this->fixtures();
        $this->record($this->makeRun($owner,$plan,$task,$pack),$q[0],1,false,now()->subDays(3));
        $focus = $this->makeRun($owner,$plan,$task,$pack,true);
        $this->record($focus,$q[2],1,true,now());
        $one = $this->history($owner,$plan,$task)['review_comparisons'][0];
        $this->assertNull($one['topic']);
        $this->assertSame('unknown',$one['source_spacing']['spacing']);
        $this->assertSame('unknown',$one['answers'][0]['source_relation']);
    }
}
