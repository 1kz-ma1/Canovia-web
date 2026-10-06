<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyRecallItem;
use App\Models\Task;
use App\Models\User;
use App\Services\StudyRecallProgressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyRecallTaskProgressionV586Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
        ]);
    }

    public function test_recall_primary_task_is_ready_only_when_all_active_cards_are_mastered_and_not_due(): void
    {
        [, $plan, $task] = $this->scenario();

        $this->item($plan, $task, [
            'prompt' => 'abandon',
            'answer' => '放棄する',
            'repetitions' => 3,
            'interval_days' => 7,
            'due_at' => now()->addDays(7),
            'last_reviewed_at' => now(),
        ]);
        $learning = $this->item($plan, $task, [
            'prompt' => 'maintain',
            'answer' => '維持する',
            'repetitions' => 2,
            'interval_days' => 3,
            'due_at' => now()->addDays(3),
            'last_reviewed_at' => now(),
        ]);

        $service = app(StudyRecallProgressionService::class);

        $state = $service->evaluate($plan, $task);

        $this->assertSame('retaining', $state['kind']);
        $this->assertFalse($state['eligible']);
        $this->assertSame(1, data_get($state, 'metrics.mastered'));

        $learning->update([
            'repetitions' => 3,
            'interval_days' => 7,
            'due_at' => now()->addDays(7),
        ]);

        $ready = $service->evaluate($plan, $task->fresh());

        $this->assertSame('ready', $ready['kind']);
        $this->assertTrue($ready['eligible']);
        $this->assertSame(2, data_get($ready, 'metrics.reviewed'));
        $this->assertSame(2, data_get($ready, 'metrics.mastered'));
        $this->assertSame(0, data_get($ready, 'metrics.due'));
    }

    public function test_due_mastered_card_prevents_completion_candidate(): void
    {
        [, $plan, $task] = $this->scenario();

        $this->item($plan, $task, [
            'repetitions' => 3,
            'interval_days' => 7,
            'due_at' => now()->subMinute(),
            'last_reviewed_at' => now()->subDays(7),
        ]);

        $state = app(StudyRecallProgressionService::class)
            ->evaluate($plan, $task);

        $this->assertSame('review_due', $state['kind']);
        $this->assertFalse($state['eligible']);
        $this->assertSame(1, data_get($state, 'metrics.due'));
    }

    public function test_recall_cannot_complete_a_task_when_recall_is_not_the_primary_activity(): void
    {
        [, $plan] = $this->scenario();
        $practiceTask = $this->task(
            $plan,
            '科目Aの過去問問題演習',
            '本番形式の問題を解いて理解を確認する',
            3,
        );

        $this->item($plan, $practiceTask, [
            'repetitions' => 4,
            'interval_days' => 10,
            'due_at' => now()->addDays(10),
            'last_reviewed_at' => now(),
        ]);

        $state = app(StudyRecallProgressionService::class)
            ->evaluate($plan, $practiceTask);

        $this->assertSame('supplementary', $state['kind']);
        $this->assertFalse($state['eligible']);
        $this->assertSame(
            'question_practice',
            $state['primary_activity'],
        );
    }

    public function test_ready_recall_page_exposes_explicit_task_completion_action(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->masteredDeck($plan, $task);

        $this->actingAs($user)
            ->get(route('plans.tasks.study_recall.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('data-study-recall-progression', false)
            ->assertSee(
                'data-study-recall-progression-kind="ready"',
                false,
            )
            ->assertSee('Task完了候補')
            ->assertSee('Recall定着を確認してTask完了')
            ->assertSee(
                route('plans.tasks.study_recall.complete', [
                    $plan,
                    $task,
                ]),
                false,
            );
    }

    public function test_server_blocks_explicit_completion_when_deck_is_not_ready(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->item($plan, $task, [
            'repetitions' => 1,
            'interval_days' => 1,
            'due_at' => now()->addDay(),
            'last_reviewed_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('plans.tasks.study_recall.show', [$plan, $task]))
            ->post(route('plans.tasks.study_recall.complete', [
                $plan,
                $task,
            ]))
            ->assertRedirect(
                route('plans.tasks.study_recall.show', [$plan, $task]),
            )
            ->assertSessionHasErrors('recall_progression');

        $task->refresh();

        $this->assertSame(0, $task->progress_percent);
        $this->assertSame('todo', $task->status);
        $this->assertDatabaseMissing('task_evidences', [
            'task_id' => $task->id,
            'type' => 'study_recall_mastery_confirmed',
        ]);
    }

    public function test_explicit_recall_completion_finishes_task_records_evidence_and_advances_to_next_task(): void
    {
        $this->withoutExceptionHandling();

        [$user, $plan, $task, $nextTask] = $this->scenario();
        $this->masteredDeck($plan, $task);

        $route = route('plans.tasks.study_recall.complete', [
            $plan,
            $task,
        ]);

        $this->actingAs($user)
            ->post($route)
            ->assertRedirect(
                route('plans.tasks.study_activity.show', [
                    $plan,
                    $nextTask,
                ]),
            )
            ->assertSessionHas(
                'status',
                'Recallの定着を確認してTaskを完了しました。次の学習Taskへ進みます。',
            );

        $task->refresh();

        $this->assertSame(100, $task->progress_percent);
        $this->assertSame(0, $task->remaining_minutes);
        $this->assertSame('done', $task->status);
        $this->assertStringContainsString(
            'Recall定着確認',
            (string) $task->progress_reason,
        );
        $this->assertSame(
            '次のTask「'.$nextTask->title.'」へ進む',
            $task->next_action_note,
        );

        $this->assertDatabaseHas('task_evidences', [
            'task_id' => $task->id,
            'source' => 'native',
            'type' => 'study_recall_mastery_confirmed',
            'external_key' =>
                'study-recall-mastery-confirmed:task:'.$task->id,
        ]);

        $this->actingAs($user)
            ->post($route)
            ->assertRedirect(
                route('plans.tasks.study_activity.show', [
                    $plan,
                    $nextTask,
                ]),
            )
            ->assertSessionHas(
                'status',
                'Taskはすでに完了しています。次の学習Taskへ進みます。',
            );

        $this->assertDatabaseCount('task_evidences', 1);
    }

    private function masteredDeck(Plan $plan, Task $task): void
    {
        $this->item($plan, $task, [
            'prompt' => 'abandon',
            'answer' => '放棄する',
            'repetitions' => 3,
            'interval_days' => 7,
            'due_at' => now()->addDays(7),
            'last_reviewed_at' => now(),
        ]);
        $this->item($plan, $task, [
            'prompt' => 'maintain',
            'answer' => '維持する',
            'repetitions' => 4,
            'interval_days' => 12,
            'due_at' => now()->addDays(12),
            'last_reviewed_at' => now(),
        ]);
    }

    private function item(
        Plan $plan,
        Task $task,
        array $overrides = [],
    ): StudyRecallItem {
        $prompt = $overrides['prompt'] ?? 'accurate';
        $answer = $overrides['answer'] ?? '正確な';

        return StudyRecallItem::query()->create(array_merge([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'prompt' => $prompt,
            'answer' => $answer,
            'tags' => [],
            'fingerprint' => hash(
                'sha256',
                mb_strtolower($prompt).'|'.mb_strtolower($answer),
            ),
            'repetitions' => 0,
            'lapse_count' => 0,
            'interval_days' => 0,
            'ease_factor' => 2.50,
            'due_at' => null,
            'last_reviewed_at' => null,
            'is_active' => true,
        ], $overrides));
    }

    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'TOEIC 800点',
            'description' => 'TOEIC頻出語彙を覚えて定着させる',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonths(2),
            'is_public' => false,
        ]);

        $task = $this->task(
            $plan,
            'TOEIC英単語を暗記する',
            '頻出語彙を単語帳で覚える',
            1,
        );

        $nextTask = $this->task(
            $plan,
            'Part 5問題演習',
            '文法問題を解いて理解を確認する',
            2,
            $task,
        );

        return [$user, $plan, $task, $nextTask];
    }

    private function task(
        Plan $plan,
        string $title,
        string $description,
        int $sortOrder,
        ?Task $dependsOn = null,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'depends_on_task_id' => $dependsOn?->id,
            'title' => $title,
            'description' => $description,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => $sortOrder,
        ]);
    }
}
