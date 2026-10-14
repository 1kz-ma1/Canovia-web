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
 * V6.13: user begins a real supported topic Bank Run, answers two questions,
 * and returns to an actor-scoped Workspace review recomputation.
 */
final class StudyLearningTopicRemeasureV613Test extends TestCase
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

    public function test_review_targets_first_two_real_questions_and_two_correct_answers_recompute_workspace(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        // Bank sort order deliberately puts unrelated material first.
        $sql1 = $this->question($pack, 'SQL', 1);
        $sql2 = $this->question($pack, 'SQL', 2);
        $net1 = $this->question($pack, 'ネットワーク', 3);
        $net2 = $this->question($pack, 'ネットワーク', 4);
        $this->historicalMiss($owner, $plan, $task, $pack, $net1);
        $this->historicalMiss($owner, $plan, $task, $pack, $net2);
        $this->actingAs($owner);

        $url = route('plans.tasks.learning.index', [$plan, $task, 'review' => 1]);
        $this->get($url)->assertOk()->assertSee('data-learning-topic-review-entry', false)
            ->assertSee('data-learning-topic-review-start', false)
            ->assertSee('この分野の問題を解く');
        $this->get(route('workspace.study.index', ['plan_id' => $plan->id, 'surface' => 'work']))
            ->assertOk()->assertSee('data-study-learning-review-practice', false)
            ->assertSee($url, false);
        $preview = $this->preview($owner, $plan, $task);
        $this->assertNotNull($preview);
        $this->startReview($plan, $task, $pack, $preview, (string) Str::uuid())
            ->assertRedirect();

        $run = LearningRun::where('plan_id', $plan->id)->where('status', 'active')->sole();
        $locked = $run->items()->orderBy('ordinal')->limit(2)->pluck('question_id')->all();
        $this->assertEqualsCanonicalizing([$net1->id, $net2->id], $locked);
        $this->assertNotContains($sql1->id, $locked);
        $this->assertNotContains($sql2->id, $locked);
        $this->assertSame('bank_topic_review_initial_v613', $run->queue_policy_version);
        $this->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('data-learning-review-return-work', false);

        for ($index = 1; $index <= 2; $index++) {
            $item = $run->items()->where('ordinal', $index)->firstOrFail();
            $this->post(route('plans.tasks.learning.answer', [$plan, $task, $run]), [
                'learning_run_item_id' => $item->id,
                'request_id' => (string) Str::uuid(),
                'choice' => 'A',
            ])->assertRedirect();
            if ($index === 1) {
                $this->assertNotNull($this->preview($owner, $plan, $task));
                $this->post(route('plans.tasks.learning.next', [$plan, $task, $run]))
                    ->assertRedirect();
            }
        }

        $this->assertNull($this->preview($owner, $plan, $task));
        $this->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()->assertSee('data-study-learning-review-cleared', false)
            ->assertDontSee('data-study-learning-review-queue', false);
        $this->assertSame(15, $task->fresh()->progress_percent);
        $this->assertSame(40, $task->fresh()->remaining_minutes);
        $this->assertSame('自分で決めた進め方', $task->fresh()->next_action_note);
        $this->assertDatabaseCount('learning_answer_events', 4);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    public function test_stale_or_forged_focus_and_unrelated_pack_fail_closed(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $first = $this->question($pack, 'NW', 1);
        $second = $this->question($pack, 'NW', 2);
        $this->historicalMiss($owner, $plan, $task, $pack, $first);
        $this->historicalMiss($owner, $plan, $task, $pack, $second);
        $foreignPack = QuestionPack::create([
            'slug' => 'v613-foreign-'.Str::lower(Str::random(10)),
            'title' => '別分野', 'exam_code' => 'AP', 'subject' => '科目A',
            'version' => '1', 'status' => 'published',
        ]);
        $this->question($foreignPack, 'SQL', 1);
        $this->question($foreignPack, 'SQL', 2);
        $this->actingAs($owner);
        $preview = $this->preview($owner, $plan, $task);
        $this->startReview($plan, $task, $foreignPack, $preview, (string) Str::uuid())
            ->assertStatus(409);
        $this->post(route('plans.tasks.learning.start', [$plan, $task]), [
            'start_request_id' => (string) Str::uuid(),
            'question_pack_id' => $pack->id, 'mode' => 'understanding',
            'review_topic' => 'SQL', 'review_source_signature' => $preview['source_signature'],
        ])->assertStatus(409);

        // The next recorded answer invalidates the source-window signature.
        $this->historicalMiss($owner, $plan, $task, $pack, $first);
        $this->startReview($plan, $task, $pack, $preview, (string) Str::uuid())
            ->assertStatus(409);
        $intruder = User::factory()->create(['first_run_completed_at' => now()]);
        $this->actingAs($intruder);
        $this->startReview($plan, $task, $pack, $preview, (string) Str::uuid())
            ->assertForbidden();
        $this->assertDatabaseMissing('learning_runs', [
            'plan_id' => $plan->id, 'status' => 'active',
        ]);
    }

    public function test_missing_two_published_topic_questions_cannot_start_focus_or_forge_progress(): void
    {
        [$owner, $plan, $task, $pack] = $this->fixture();
        $first = $this->question($pack, 'NW', 1);
        $this->historicalMiss($owner, $plan, $task, $pack, $first);
        $this->historicalMiss($owner, $plan, $task, $pack,
            $this->question($pack, 'NW', 2));
        $preview = $this->preview($owner, $plan, $task);
        $this->actingAs($owner);
        // A different published pack with one matching question is not
        // silently padded by AI or by a non-matching question.
        $one = QuestionPack::create([
            'slug' => 'v613-one-'.Str::lower(Str::random(10)),
            'title' => '単問のみ', 'exam_code' => 'AP', 'subject' => '科目A',
            'version' => '1', 'status' => 'published',
        ]);
        $this->question($one, 'NW', 1);
        $this->question($one, 'SQL', 2);
        $this->startReview($plan, $task, $one, $preview, (string) Str::uuid())
            ->assertStatus(409);
        // The normal Bank start is unchanged and need not be topic-focused.
        $this->post(route('plans.tasks.learning.start', [$plan, $task]), [
            'start_request_id' => (string) Str::uuid(),
            'question_pack_id' => $one->id,
            'mode' => 'understanding',
        ])->assertRedirect();
        $normal = LearningRun::where('status', 'active')->sole();
        $this->assertSame('bank_locked_v1', $normal->queue_policy_version);
        $this->assertSame(15, $task->fresh()->progress_percent);
        $this->assertDatabaseCount('task_evidences', 0);
    }
}
