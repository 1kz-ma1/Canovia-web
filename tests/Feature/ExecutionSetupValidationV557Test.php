<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Execution\ExecutionCapability;
use App\Models\Plan;
use App\Models\StudyScopeCapture;
use App\Models\StudyScopeItem;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Services\ExecutionLaunchResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecutionSetupValidationV557Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'session.driver' => 'array',
            'canovia.execution_setup_validation_enabled' => true,
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_setup_is_not_shown_before_study_onboarding_is_complete(): void
    {
        [$user, $plan] = $this->studyPlan();
        $this->task($plan, 'ネットワークの過去問を解く');

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-study-workspace-capture-first', false)
            ->assertDontSee('data-execution-setup', false);

        $this->assertDatabaseCount('plan_execution_preferences', 0);
    }

    public function test_workspace_prompts_once_only_when_multiple_providers_are_available(): void
    {
        [$user, $plan, $task] = $this->readyPracticePlan();

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-execution-setup', false)
            ->assertSee('data-execution-setup-needs-choice', false)
            ->assertSee('Canovia Question Practice')
            ->assertSee('External Practice Partner (Validation)');

        $this->actingAs($user)
            ->post(
                route('plans.tasks.execution_setup.store', [$plan, $task]),
                [
                    'provider_key' =>
                        ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
                ],
            )
            ->assertRedirect(
                route('workspace.study.index', ['plan_id' => $plan->id]),
            );

        $this->assertDatabaseHas('plan_execution_preferences', [
            'plan_id' => $plan->id,
            'capability' => ExecutionCapability::STUDY_PRACTICE,
            'provider_key' =>
                ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
            'user_selected' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('data-execution-setup', false)
            ->assertDontSee('data-execution-setup-needs-choice', false)
            ->assertSee('data-execution-setup-change', false)
            ->assertSee('External Practice Partner (Validation)');
    }

    public function test_saved_external_provider_is_used_by_the_normal_study_start(): void
    {
        [$user, $plan, $task] = $this->readyPracticePlan();

        $this->actingAs($user)->post(
            route('plans.tasks.execution_setup.store', [$plan, $task]),
            [
                'provider_key' =>
                    ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
            ],
        );

        $response = $this->actingAs($user)
            ->post(route('plans.study_action.execute', $plan));

        $launchTask = Task::query()
            ->where('plan_id', $plan->id)
            ->latest('id')
            ->firstOrFail();

        $response->assertRedirect(
            route(
                'execution.validation.study_practice',
                [$plan, $launchTask],
            ),
        );

        $this->actingAs($user)
            ->get(
                route(
                    'execution.validation.study_practice',
                    [$plan, $launchTask],
                ),
            )
            ->assertOk()
            ->assertSee('data-execution-validation-provider', false)
            ->assertSee('外部Practice Providerへの引き継ぎを検証中')
            ->assertSee($launchTask->title);
    }

    public function test_user_can_change_back_to_native_and_keep_one_tap_start(): void
    {
        [$user, $plan, $task] = $this->readyPracticePlan();

        $this->actingAs($user)->post(
            route('plans.tasks.execution_setup.store', [$plan, $task]),
            [
                'provider_key' =>
                    ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
            ],
        );

        $this->actingAs($user)
            ->post(
                route('plans.tasks.execution_setup.store', [$plan, $task]),
                ['provider_key' => 'canovia.study.practice'],
            )
            ->assertRedirect(
                route('workspace.study.index', ['plan_id' => $plan->id]),
            );

        $this->assertDatabaseHas('plan_execution_preferences', [
            'plan_id' => $plan->id,
            'capability' => ExecutionCapability::STUDY_PRACTICE,
            'provider_key' => 'canovia.study.practice',
            'user_selected' => 1,
        ]);

        $response = $this->actingAs($user)
            ->post(route('plans.study_action.execute', $plan));

        $launchTask = Task::query()
            ->where('plan_id', $plan->id)
            ->latest('id')
            ->firstOrFail();

        $response->assertRedirect(
            route('plans.tasks.study_practice.show', [$plan, $launchTask]),
        );
    }

    public function test_validation_provider_is_absent_when_feature_flag_is_disabled(): void
    {
        config(['canovia.execution_setup_validation_enabled' => false]);

        [$user, $plan, $task] = $this->readyPracticePlan();

        $this->actingAs($user)
            ->get(route('workspace.study.index', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertDontSee('data-execution-setup', false)
            ->assertDontSee('External Practice Partner (Validation)');

        $response = $this->actingAs($user)
            ->post(route('plans.study_action.execute', $plan));

        $launchTask = Task::query()
            ->where('plan_id', $plan->id)
            ->latest('id')
            ->firstOrFail();

        $response->assertRedirect(
            route('plans.tasks.study_practice.show', [$plan, $launchTask]),
        );
    }

    private function readyPracticePlan(): array
    {
        [$user, $plan] = $this->studyPlan();

        $capture = StudyScopeCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
            'exam_title' => '応用情報',
            'exam_date' => today()->addMonth(),
            'confidence' => 1,
            'extraction_version' => 'study_scope_v1',
            'confirmed_at' => now(),
        ]);

        StudyScopeItem::query()->create([
            'study_scope_capture_id' => $capture->id,
            'plan_id' => $plan->id,
            'subject' => 'ネットワーク',
            'unit' => 'TCP/IP',
            'range_text' => 'TCP/IP',
            'confidence' => 1,
            'sort_order' => 0,
        ]);

        $task = $this->task($plan, 'ネットワークの過去問を解く');

        TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native->value,
            'type' => 'study_practice_assessed',
            'external_key' => 'practice:'.Str::uuid(),
            'confidence' => 1,
            'occurred_at' => now(),
            'metadata' => [
                'score_percent' => 45,
                'weaknesses' => ['TCP/IP'],
                'weakness_topics' => ['TCP/IP'],
            ],
        ]);

        return [$user, $plan, $task];
    }

    private function studyPlan(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '応用情報技術者試験',
            'description' => 'ネットワークとデータベースを重点学習',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);

        return [$user, $plan];
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }
}
