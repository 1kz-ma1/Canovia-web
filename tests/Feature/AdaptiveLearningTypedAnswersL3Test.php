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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdaptiveLearningTypedAnswersL3Test extends TestCase
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

    private function fixture(string $kind): array
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'AP科目Aの学習',
            'category' => '資格学習', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addDays(30),
            'is_public' => false, 'is_collaborative' => false,
        ]);
        $task = Task::create([
            'plan_id' => $plan->id, 'title' => '形式別の演習',
            'estimated_minutes' => 40, 'remaining_minutes' => 25,
            'progress_percent' => 35, 'status' => 'doing',
            'priority' => 2, 'activation_cost' => 2, 'sort_order' => 1,
        ]);
        $pack = QuestionPack::create([
            'slug' => 'typed-l3-'.Str::lower(Str::random(10)),
            'title' => '型付き単問演習', 'exam_code' => 'AP',
            'subject' => '科目A', 'version' => '1',
            'status' => 'published', 'downloadable' => true,
            'metadata' => ['match_terms' => ['AP']],
        ]);
        $type = match ($kind) {
            'multiple' => 'multiple_choice',
            'number' => 'number',
            default => 'single_choice',
        };
        $rule = match ($kind) {
            'multiple' => ['type' => 'exact_multiple', 'field_id' => 'answer', 'answers' => ['A', 'C']],
            'number' => ['type' => 'numeric_tolerance', 'field_id' => 'answer',
                'answer' => 10.5, 'tolerance' => 0.1],
            default => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A'],
        };
        Question::create([
            'question_pack_id' => $pack->id, 'external_key' => 'typed-'.$kind,
            'source_type' => 'canovia_original', 'prompt' => '回答形式のテスト問題',
            'response_schema' => [[
                'id' => 'answer', 'type' => $type,
                'label' => '回答', 'required' => true,
                'choices' => $kind === 'number' ? [] : [
                    ['id' => 'A', 'label' => '選択肢A'],
                    ['id' => 'B', 'label' => '選択肢B'],
                    ['id' => 'C', 'label' => '選択肢C'],
                ],
            ]],
            'grading_rule' => $rule,
            'learning_metadata' => ['concepts' => ['型付き回答']],
            'explanation' => '正答解説', 'difficulty' => 2,
            'sort_order' => 1, 'is_active' => true,
        ]);
        $this->actingAs($owner)
            ->post(route('plans.tasks.learning.start', [$plan, $task]), [
                'start_request_id' => (string) Str::uuid(),
                'question_pack_id' => $pack->id,
                'mode' => 'understanding',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $run = LearningRun::where('question_pack_id', $pack->id)->sole();
        $item = $run->items()->firstOrFail();

        return [$owner, $plan, $task, $pack, $run, $item];
    }

    private function submit(Plan $plan, Task $task, LearningRun $run, int $itemId,
        array $value, ?string $requestId = null)
    {
        return $this->post(route('plans.tasks.learning.answer', [$plan, $task, $run]),
            array_merge([
                'request_id' => $requestId ?? (string) Str::uuid(),
                'learning_run_item_id' => $itemId,
            ], $value));
    }

    public function test_multiple_choice_is_sorted_saved_as_typed_json_and_retry_is_idempotent(): void
    {
        $this->assertTrue(Schema::hasColumn('learning_answer_events', 'answer_payload'));
        [, $plan, $task, , $run, $item] = $this->fixture('multiple');
        $this->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('name="choices[]"', false);

        $this->submit($plan, $task, $run, $item->id, ['choices' => ['C', 'A']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $answer = LearningAnswerEvent::sole();
        $this->assertSame('question_bank_exact_multiple', $answer->grading_method);
        $this->assertSame(['type' => 'multiple_choice', 'value' => ['A', 'C']], $answer->answer_payload);
        $this->assertSame('["A","C"]', $answer->answer_value);
        $this->assertTrue($answer->was_correct);

        $this->submit($plan, $task, $run, $item->id, ['choices' => ['A', 'C']])
            ->assertRedirect();
        $this->submit($plan, $task, $run, $item->id, ['choices' => ['B']])
            ->assertStatus(409);
        $this->assertDatabaseCount('learning_answer_events', 1);

        $this->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('あなたの回答：A / C')
            ->assertSee('正答：A / C');
        $this->assertSame(35, $task->fresh()->progress_percent);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    public function test_invalid_multiple_choices_are_rejected_without_fabricating_answers(): void
    {
        [, $plan, $task, , $run, $item] = $this->fixture('multiple');
        foreach ([[], ['A', 'A'], ['A', 'Z']] as $choices) {
            $this->submit($plan, $task, $run, $item->id, ['choices' => $choices])
                ->assertSessionHasErrors('choices');
        }
        $this->submit($plan, $task, $run, $item->id, ['choice' => 'A'])
            ->assertSessionHasErrors('choices');
        $this->assertDatabaseCount('learning_answer_events', 0);
    }

    public function test_numeric_tolerance_is_graded_and_equivalent_retry_preserves_one_event(): void
    {
        [, $plan, $task, , $run, $item] = $this->fixture('number');
        $this->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('data-learning-number-answer', false);

        $this->submit($plan, $task, $run, $item->id, ['number' => '10.55'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $answer = LearningAnswerEvent::sole();
        $this->assertTrue($answer->was_correct);
        $this->assertSame('question_bank_numeric_tolerance', $answer->grading_method);
        $this->assertSame(['type' => 'number', 'value' => '10.55'], $answer->answer_payload);

        $this->submit($plan, $task, $run, $item->id, ['number' => '10.550'])
            ->assertRedirect();
        $this->submit($plan, $task, $run, $item->id, ['number' => '10.0'])
            ->assertStatus(409);
        $this->assertDatabaseCount('learning_answer_events', 1);
        $this->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('正答：10.5')
            ->assertSee('許容誤差 ±0.1');
        $this->assertSame(35, $task->fresh()->progress_percent);
    }

    public function test_numeric_input_rejects_nonfinite_and_non_numeric_before_grade(): void
    {
        [, $plan, $task, , $run, $item] = $this->fixture('number');
        foreach (['NaN', 'INF', '1e400', '--1', '10,5'] as $invalid) {
            $this->submit($plan, $task, $run, $item->id, ['number' => $invalid])
                ->assertSessionHasErrors('number');
        }
        $this->assertDatabaseCount('learning_answer_events', 0);
    }

    public function test_old_single_choice_answer_without_payload_remains_valid_and_idempotent(): void
    {
        [, $plan, $task, , $run, $item] = $this->fixture('single');
        $old = LearningAnswerEvent::create([
            'learning_run_item_id' => $item->id,
            'request_id' => (string) Str::uuid(),
            'answer_value' => 'A', 'was_correct' => true,
            'grading_method' => 'question_bank_exact_choice',
            'answered_at' => now(),
        ]);
        $this->assertNull($old->answer_payload);
        $this->submit($plan, $task, $run, $item->id, ['choice' => 'A'])->assertRedirect();
        $this->submit($plan, $task, $run, $item->id, ['choice' => 'B'])->assertStatus(409);
        $this->assertDatabaseCount('learning_answer_events', 1);
        $this->assertNull($old->fresh()->answer_payload);
        $this->get(route('plans.tasks.learning.show', [$plan, $task, $run]))
            ->assertOk()->assertSee('あなたの回答：A')
            ->assertSee('正答：A');
    }
}
