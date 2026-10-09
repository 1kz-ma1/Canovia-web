<?php

namespace Tests\Feature;

use App\Models\LearningAnswerEvent;
use App\Models\LearningRun;
use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdaptiveLearningSessionDraftL3cTest extends TestCase
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
            'study.adaptive_learning.locked_queue_size' => 2,
        ]);
    }

    private function fixture(string $kind = 'single', string $mode = 'understanding'): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'AP 学習',
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(30),
            'is_public' => false, 'is_collaborative' => false,
        ]);
        $task = Task::create([
            'plan_id' => $plan->id, 'title' => '一問復帰',
            'estimated_minutes' => 40, 'remaining_minutes' => 25,
            'progress_percent' => 35, 'status' => 'doing',
            'priority' => 2, 'activation_cost' => 2, 'sort_order' => 1,
        ]);
        $pack = QuestionPack::create([
            'slug' => 'draft-l3c-'.Str::lower(Str::random(12)),
            'title' => '一問下書き', 'exam_code' => 'AP', 'subject' => '科目A',
            'version' => '1', 'status' => 'published', 'downloadable' => true,
            'metadata' => ['match_terms' => ['AP']],
        ]);
        $type = match ($kind) {
            'multiple' => 'multiple_choice',
            'number' => 'number',
            default => 'single_choice',
        };
        $rule = match ($kind) {
            'multiple' => ['type' => 'exact_multiple', 'field_id' => 'answer', 'answers' => ['A', 'B']],
            'number' => ['type' => 'numeric_tolerance', 'field_id' => 'answer', 'answer' => 10, 'tolerance' => 0.1],
            default => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A'],
        };
        foreach (range(1, 2) as $n) {
            Question::create([
                'question_pack_id' => $pack->id, 'external_key' => $kind.'-'.$n,
                'source_type' => 'canovia_original', 'prompt' => '問題'.$n,
                'response_schema' => [[
                    'id' => 'answer', 'type' => $type, 'label' => '回答',
                    'required' => true,
                    'choices' => $kind === 'number' ? [] : [
                        ['id' => 'A', 'label' => 'Aの説明'],
                        ['id' => 'B', 'label' => 'Bの説明'],
                    ],
                ]],
                'grading_rule' => $rule,
                'learning_metadata' => ['concepts' => ['復帰']],
                'explanation' => '解説', 'difficulty' => 2,
                'sort_order' => $n, 'is_active' => true,
            ]);
        }

        $this->actingAs($owner)->post(route('plans.tasks.learning.start', [$plan, $task]), [
            'start_request_id' => (string) Str::uuid(),
            'question_pack_id' => $pack->id, 'mode' => $mode,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $run = LearningRun::where('question_pack_id', $pack->id)->sole();
        return [$owner, $plan, $task, $pack, $run, $run->items()->where('ordinal', 1)->firstOrFail()];
    }

    private function draftUrl(Plan $plan, Task $task, LearningRun $run): string
    {
        return route('plans.tasks.learning.draft', [$plan, $task, $run]);
    }

    public function test_single_choice_draft_restores_without_grade_and_committed_answer_discards_it(): void
    {
        [$owner, $plan, $task, , $run, $item] = $this->fixture();
        $this->postJson($this->draftUrl($plan, $task, $run), [
            'learning_run_item_id' => $item->id, 'choice' => 'B',
            'reasoning' => '自分の判断',
        ])->assertOk()->assertExactJson(['saved' => true])
            ->assertHeader('Cache-Control', 'no-store');

        $show = route('plans.tasks.learning.show', [$plan, $task, $run]);
        $this->get($show)->assertOk()
            ->assertSee('前回の入力を復元しました。')
            ->assertSee('data-learning-draft-url', false)
            ->assertSee('data-learning-draft-status', false)
            ->assertSee('value="B" checked', false);
        $this->assertDatabaseCount('learning_answer_events', 0);
        $this->assertDatabaseCount('study_practice_attempts', 0);
        $this->assertSame(35, $task->fresh()->progress_percent);

        $this->post(route('plans.tasks.learning.answer', [$plan, $task, $run]), [
            'request_id' => (string) Str::uuid(),
            'learning_run_item_id' => $item->id, 'choice' => 'A',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(LearningAnswerEvent::sole()->was_correct);
        $this->get($show)->assertOk()->assertDontSee('data-learning-draft-status', false);
        $this->postJson($this->draftUrl($plan, $task, $run), [
            'learning_run_item_id' => $item->id, 'choice' => 'B',
        ])->assertStatus(409);
        $this->assertSame(35, $task->fresh()->progress_percent);
    }

    public function test_partial_number_and_multiple_selection_survive_reload_without_being_graded(): void
    {
        foreach (['number', 'multiple'] as $kind) {
            [$owner, $plan, $task, , $run, $item] = $this->fixture($kind);
            $fields = $kind === 'number' ? ['number' => '-'] : ['choices' => ['B', 'A']];
            $this->postJson($this->draftUrl($plan, $task, $run), array_merge(
                ['learning_run_item_id' => $item->id], $fields,
            ))->assertOk();
            $res = $this->get(route('plans.tasks.learning.show', [$plan, $task, $run]));
            $res->assertOk()->assertSee('前回の入力を復元しました。');
            if ($kind === 'number') {
                $res->assertSee('value="-"', false);
            } else {
                $res->assertSee('name="choices[]"', false);
                $res->assertSee('value="A" checked', false);
                $res->assertSee('value="B" checked', false);
            }
        }
        $this->assertDatabaseCount('learning_answer_events', 0);
    }

    public function test_foreign_actor_old_question_and_invalid_choice_cannot_overwrite_draft(): void
    {
        [$owner, $plan, $task, , $run, $item] = $this->fixture();
        $url = $this->draftUrl($plan, $task, $run);
        $this->postJson($url, ['learning_run_item_id' => $item->id, 'choice' => 'A'])->assertOk();
        $this->postJson($url, ['learning_run_item_id' => $item->id, 'choice' => 'Z'])
            ->assertUnprocessable()->assertJsonValidationErrors('choice');
        $this->postJson($url, ['learning_run_item_id' => $item->id + 100000, 'choice' => 'B'])
            ->assertNotFound();

        $intruder = User::factory()->create(['first_run_completed_at' => now()]);
        $this->actingAs($intruder)->postJson($url, [
            'learning_run_item_id' => $item->id, 'choice' => 'B',
        ])->assertForbidden();
        $this->actingAs($owner)->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('value="A" checked', false);
        $this->assertDatabaseCount('learning_answer_events', 0);
    }

    public function test_practice_rejects_reasoning_and_finished_run_does_not_restore_draft(): void
    {
        [, $plan, $task, , $run, $item] = $this->fixture('single', 'practice');
        $url = $this->draftUrl($plan, $task, $run);
        $this->postJson($url, [
            'learning_run_item_id' => $item->id, 'choice' => 'A', 'reasoning' => '不可',
        ])->assertUnprocessable()->assertJsonValidationErrors('reasoning');
        $this->postJson($url, [
            'learning_run_item_id' => $item->id, 'choice' => 'B',
        ])->assertOk();
        $this->post(route('plans.tasks.learning.finish', [$plan, $task, $run]))
            ->assertRedirect();
        $this->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('学習を終了しました')
            ->assertDontSee('data-learning-answer-form', false);
        $this->postJson($url, [
            'learning_run_item_id' => $item->id, 'choice' => 'A',
        ])->assertStatus(409);
        $this->assertDatabaseCount('learning_answer_events', 0);
    }
}
