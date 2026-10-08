<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\StudyPracticeSession;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyStudyWorkspaceResumeV5887Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function planAndTask(User $user): array
    {
        $plan = Plan::query()->create([
            'user_id' => $user->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'AP対策',
            'description' => '試験に向けた学習', 'category' => '資格学習',
            'priority' => 1, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'DNS演習',
            'description' => '', 'estimated_minutes' => 20,
            'remaining_minutes' => 20, 'progress_percent' => 0,
            'status' => 'todo', 'priority' => 1, 'activation_cost' => 1,
            'sort_order' => 1,
        ]);
        return [$plan, $task];
    }

    private function createPracticeSession(User $user, Plan $plan, Task $task, array $overrides = []): StudyPracticeSession
    {
        return StudyPracticeSession::create(array_replace([
            'plan_id' => $plan->id, 'task_id' => $task->id,
            'user_id' => $user->id, 'session_token' => (string) Str::uuid(),
            'prepare_request_id' => (string) Str::uuid(),
            'status' => StudyPracticeSession::STATUS_IN_PROGRESS,
            'exercise_title' => 'DNSの続き', 'strategy' => 'general_practice',
            'selector_type' => 'question_bank',
            'question_provider' => 'question_bank',
            'questions_snapshot' => [[
                'id' => 'q1', 'prompt' => 'DNS?', 'response_fields' => [
                    ['id' => 'answer', 'type' => 'single_choice', 'label' => '回答',
                        'required' => true, 'choices' => [
                            ['id' => 'A', 'label' => '名前解決'],
                            ['id' => 'B', 'label' => '暗号化'],
                        ]],
                ],
            ]],
            'draft_answers' => ['q1' => ['answer' => 'A']],
        ], $overrides));
    }

    public function test_study_workspace_offers_direct_resume_for_own_saved_legacy_practice(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        [$plan, $task] = $this->planAndTask($user);
        $this->createPracticeSession($user, $plan, $task);

        $this->actingAs($user)->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-legacy-practice-resume', false)
            ->assertSee('DNSの続き')
            ->assertSee(route('plans.tasks.study_practice.resume', [$plan, $task]), false);
    }

    public function test_completed_or_different_actors_legacy_sessions_are_not_suggested(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create();
        [$plan, $task] = $this->planAndTask($user);
        $this->createPracticeSession($other, $plan, $task);
        $this->actingAs($user)->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()->assertDontSee('data-legacy-practice-resume', false);

        $this->createPracticeSession($user, $plan, $task, ['status' => StudyPracticeSession::STATUS_COMPLETED]);
        $this->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()->assertDontSee('data-legacy-practice-resume', false);
    }
}
