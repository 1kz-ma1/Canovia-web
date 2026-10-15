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
use App\Services\AdaptiveLearningHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V6.16: explicit distinct-source recheck starts only when two unseen published questions exist.
 * and returns to an actor-scoped Workspace review recomputation.
 */
final class StudyLearningDistinctFollowupV616Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array',
            'native_ai.driver' => 'disabled',
            'study.adaptive_learning.locked_queue_size' => 2,
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'AP復習再測定',
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addMonth(),
        ]);
        $task = Task::create([
            'plan_id' => $plan->id, 'title' => 'ネットワーク',
            'estimated_minutes' => 60, 'remaining_minutes' => 40,
            'progress_percent' => 15, 'status' => 'doing',
            'priority' => 2, 'activation_cost' => 1, 'sort_order' => 1,
            'next_action_note' => '自分で決めた進め方',
        ]);
        $pack = QuestionPack::create([
            'slug' => 'v613-'.Str::lower(Str::random(10)),
            'title' => '公開済み合成問題',
            'exam_code' => 'AP', 'subject' => '科目A', 'version' => '1',
            'status' => 'published',
        ]);
        return [$owner, $plan, $task, $pack];
    }

    private function question(QuestionPack $pack, string $topic, int $order): Question
    {
        return Question::create([
            'question_pack_id' => $pack->id, 'external_key' => (string) Str::uuid(),
            'source_type' => 'canovia_original',
            'prompt' => '合成問題 '.$topic.' '.$order,
            'response_schema' => [['id' => 'answer', 'type' => 'single_choice',
                'label' => '回答', 'required' => true,
                'choices' => [['id' => 'A', 'label' => '正答'], ['id' => 'B', 'label' => '誤答']]]],
            'grading_rule' => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A'],
            'learning_metadata' => ['concepts' => [$topic]],
            'explanation' => '合成問題の解説',
            'difficulty' => 2, 'sort_order' => $order, 'is_active' => true,
        ]);
    }

    private function historicalMiss(User $owner, Plan $plan, Task $task, QuestionPack $pack, Question $question): void
    {
        $run = LearningRun::create([
            'plan_id' => $plan->id, 'task_id' => $task->id, 'user_id' => $owner->id,
            'start_request_id' => (string) Str::uuid(), 'question_pack_id' => $pack->id,
            'pack_title_snapshot' => $pack->title, 'pack_version_snapshot' => '1',
            'mode' => 'understanding', 'status' => 'completed',
            'current_ordinal' => 1, 'queue_policy_version' => 'bank_locked_v1',
            'started_at' => now(), 'completed_at' => now(),
        ]);
        $item = LearningRunItem::create([
            'learning_run_id' => $run->id, 'question_id' => $question->id,
            'ordinal' => 1, 'question_snapshot' => [
                'prompt' => $question->prompt,
                'learning_metadata' => $question->learning_metadata,
                'response_field' => ['id' => 'answer', 'type' => 'single_choice',
                    'choices' => $question->response_schema[0]['choices']]],
            'grading_rule_snapshot' => $question->grading_rule,
            'explanation_snapshot' => $question->explanation,
        ]);
        LearningAnswerEvent::create([
            'learning_run_item_id' => $item->id, 'request_id' => (string) Str::uuid(),
            'answer_value' => 'B', 'was_correct' => false,
            'grading_method' => 'question_bank_exact_choice', 'answered_at' => now(),
        ]);
    }

    private function preview(User $owner, Plan $plan, Task $task): ?array
    {
        $request = Request::create('/learning', 'GET');
        $request->setUserResolver(fn () => $owner);
        return app(AdaptiveLearningHistoryService::class)
            ->forPlanTask($request, $plan, $task, '')['next_action_preview'];
    }

    private function startReview(Plan $plan, Task $task, QuestionPack $pack, array $preview, string $requestId)
    {
        return $this->post(route('plans.tasks.learning.start', [$plan, $task]), [
            'start_request_id' => $requestId,
            'question_pack_id' => $pack->id,
            'mode' => 'understanding',
            'review_topic' => $preview['topic'],
            'review_source_signature' => $preview['source_signature'],
        ]);
    }


    public function test_distinct_two_unanswered_questions_are_locked_and_graded_as_a_separate_run(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $seenOne = $this->question($pack, 'NW', 1);
        $seenTwo = $this->question($pack, 'NW', 2);
        $freshOne = $this->question($pack, 'NW', 3);
        $freshTwo = $this->question($pack, 'NW', 4);
        $this->historicalMiss($owner, $plan, $task, $pack, $seenOne);
        $this->historicalMiss($owner, $plan, $task, $pack, $seenTwo);
        // An unrelated actor's answer must not disqualify the owner's source.
        $foreign = User::factory()->create();
        $this->historicalMiss($foreign, $plan, $task, $pack, $freshOne);
        $this->actingAs($owner);
        config(['study.adaptive_learning.locked_queue_size' => 1]);

        $index = $this->get(route('plans.tasks.learning.index', [$plan, $task, 'review' => 1]))
            ->assertOk()->assertSee('data-learning-distinct-review-entry', false)
            ->assertSee('data-learning-distinct-review-start', false)
            ->assertSee('別の2問から再確認する')
            ->assertDontSee('data-learning-distinct-review-empty', false);
        preg_match('/data-learning-distinct-review-start[^>]*>[\\s\\S]*?name="start_request_id" value="([^"]+)"/',
            $index->getContent(), $ids);
        $this->assertNotEmpty($ids[1] ?? null);
        $preview = $this->preview($owner, $plan, $task);
        $this->assertNotNull($preview);
        $this->post(route('plans.tasks.learning.start', [$plan, $task]), [
            'start_request_id' => $ids[1], 'mode' => 'understanding',
            'question_pack_id' => $pack->id, 'review_distinct' => '1',
            'review_topic' => $preview['topic'],
            'review_source_signature' => $preview['source_signature'],
        ])->assertRedirect();

        $run = LearningRun::query()->where('queue_policy_version', 'bank_topic_distinct_initial_v616')->sole();
        $this->assertEqualsCanonicalizing([$freshOne->id, $freshTwo->id],
            $run->items()->orderBy('ordinal')->limit(2)->pluck('question_id')->all());
        $this->assertSame(1, $run->current_ordinal);
        $this->get(route('plans.tasks.learning.show', [$plan, $task, $run]))->assertOk();
        for ($n = 1; $n <= 2; $n++) {
            $item = $run->items()->where('ordinal', $n)->firstOrFail();
            $this->post(route('plans.tasks.learning.answer', [$plan, $task, $run]), [
                'learning_run_item_id' => $item->id,
                'request_id' => (string) Str::uuid(), 'choice' => 'A',
            ])->assertRedirect();
            if ($n === 1) {
                $this->post(route('plans.tasks.learning.next', [$plan, $task, $run]))
                    ->assertRedirect();
            }
        }

        $history = app(AdaptiveLearningHistoryService::class);
        $request = Request::create('/learning/history', 'GET');
        $request->setUserResolver(fn () => $owner);
        $comparison = $history->forPlanTask($request, $plan, $task, '')['review_comparisons'][0];
        $this->assertSame($run->id, $comparison['run_id']);
        $this->assertSame(['different_source', 'different_source'],
            array_column($comparison['answers'], 'source_relation'));
        $this->assertSame(2, $comparison['source_spacing']['different_count']);
        $this->assertSame(0, $comparison['comparable_count']);
        $this->get(route('plans.tasks.learning.history', [$plan, $task]))
            ->assertOk()->assertSee('data-learning-review-source-relation', false)
            ->assertSee('別問題での再確認 2問');
        $this->assertSame(15, $task->fresh()->progress_percent);
        $this->assertSame(40, $task->fresh()->remaining_minutes);
        $this->assertSame('自分で決めた進め方', $task->fresh()->next_action_note);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    public function test_missing_two_unanswered_questions_shows_unavailable_and_post_fails_closed(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $q1 = $this->question($pack, 'NW', 1);
        $q2 = $this->question($pack, 'NW', 2);
        $this->historicalMiss($owner, $plan, $task, $pack, $q1);
        $this->historicalMiss($owner, $plan, $task, $pack, $q2);
        // Even when the existing ordinary review has two supported sources,
        // their prior answers mean they are NOT distinct-source review items.
        $this->actingAs($owner);
        $this->get(route('plans.tasks.learning.index', [$plan, $task, 'review'=>1]))
            ->assertOk()->assertSee('data-learning-distinct-review-empty', false)
            ->assertDontSee('data-learning-distinct-review-start', false)
            ->assertSee('data-learning-topic-review-start', false);
        $preview = $this->preview($owner, $plan, $task);
        $this->post(route('plans.tasks.learning.start', [$plan, $task]), [
            'start_request_id'=>(string) Str::uuid(),'mode'=>'understanding',
            'question_pack_id'=>$pack->id,'review_distinct'=>'1',
            'review_topic'=>$preview['topic'],
            'review_source_signature'=>$preview['source_signature'],
        ])->assertStatus(409);
        $this->assertDatabaseMissing('learning_runs', ['queue_policy_version'=>'bank_topic_distinct_initial_v616']);
        $this->assertSame(15,$task->fresh()->progress_percent);
    }

    public function test_distinct_request_requires_fresh_actor_signature_and_independent_uuid(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $q1 = $this->question($pack, 'NW', 1);
        $q2 = $this->question($pack, 'NW', 2);
        $this->question($pack, 'NW', 3);
        $this->question($pack, 'NW', 4);
        $this->historicalMiss($owner, $plan, $task, $pack, $q1);
        $this->historicalMiss($owner, $plan, $task, $pack, $q2);
        $this->actingAs($owner);
        $preview = $this->preview($owner, $plan, $task);
        $post = [
            'start_request_id'=>(string) Str::uuid(),'mode'=>'understanding',
            'question_pack_id'=>$pack->id,'review_distinct'=>'1',
            'review_topic'=>$preview['topic'],
            'review_source_signature'=>$preview['source_signature'],
        ];
        $this->post(route('plans.tasks.learning.start',[$plan,$task]),
            array_merge($post,['mode'=>'practice']))->assertStatus(409);
        $this->post(route('plans.tasks.learning.start',[$plan,$task]),
            array_merge($post,['review_topic'=>'SQL']))->assertStatus(409);
        $this->post(route('plans.tasks.learning.start',[$plan,$task]),
            array_merge($post,['review_source_signature'=>str_repeat('0',64)]))->assertStatus(409);
        // Changing answer history invalidates the signed preview even if
        // there are still two unseen questions available.
        $this->historicalMiss($owner,$plan,$task,$pack,$q1);
        $this->post(route('plans.tasks.learning.start',[$plan,$task]),$post)
            ->assertStatus(409);
        $foreign = User::factory()->create();
        $this->actingAs($foreign)->post(route('plans.tasks.learning.start',[$plan,$task]),$post)
            ->assertForbidden();
        $this->assertDatabaseMissing('learning_runs',['queue_policy_version'=>'bank_topic_distinct_initial_v616']);
        $this->assertDatabaseCount('task_evidences',0);
    }
}
