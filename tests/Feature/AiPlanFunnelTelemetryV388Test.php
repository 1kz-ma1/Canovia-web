<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiPlanFunnelTelemetryV388Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_initial_plan_import_failure_is_recorded_without_json_body(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);

        $this->actingAs($user)
            ->from(route('plans.ai_task_assistant.show', $plan))
            ->post(route('plans.ai_task_assistant.import', $plan), [
                'tasks_json' => '{"schema_version":"2.0","flow":"plan_generation","operations":[',
                '_client_surface' => 'pwa',
            ])
            ->assertRedirect(route('plans.ai_task_assistant.show', $plan));

        $this->assertDatabaseHas('behavior_events', [
            'event_type' => BehaviorEventType::PlanGenerationImportAttempted->value,
            'plan_id' => $plan->id,
        ]);
        $this->assertDatabaseHas('behavior_events', [
            'event_type' => BehaviorEventType::PlanGenerationImportFailed->value,
            'plan_id' => $plan->id,
        ]);

        $failed = BehaviorEvent::query()
            ->where('event_type', BehaviorEventType::PlanGenerationImportFailed->value)
            ->firstOrFail();

        $this->assertSame('pwa', $failed->metadata['surface']);
        $this->assertSame('invalid_json', $failed->metadata['failure_code']);
        $this->assertArrayNotHasKey('tasks_json', $failed->metadata);
        $this->assertArrayNotHasKey('json', $failed->metadata);
        $this->assertArrayNotHasKey('prompt', $failed->metadata);
    }

    public function test_initial_plan_import_success_is_recorded(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);

        $payload = [
            'schema_version' => '2.0',
            'flow' => 'plan_generation',
            'target_plan' => [
                'id' => $plan->id,
                'title' => $plan->title,
                'category' => $plan->category,
            ],
            'summary' => 'Telemetry test plan',
            'operations' => [[
                'type' => 'add_task',
                'client_ref' => 'task_1',
                'title' => '最初のタスク',
                'description' => '完了条件',
                'estimated_minutes' => 30,
                'remaining_minutes' => 30,
                'progress_percent' => 0,
                'progress_reason' => '未着手',
                'status' => 'todo',
                'priority' => 1,
                'activation_cost' => 1,
            ]],
        ];

        $this->actingAs($user)
            ->post(route('plans.ai_task_assistant.import', $plan), [
                'tasks_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                '_client_surface' => 'web',
            ])
            ->assertRedirect(route('plans.show', $plan));

        $this->assertDatabaseHas('behavior_events', [
            'event_type' => BehaviorEventType::PlanGenerationImportAttempted->value,
            'plan_id' => $plan->id,
        ]);
        $this->assertDatabaseHas('behavior_events', [
            'event_type' => BehaviorEventType::PlanGenerationImportSucceeded->value,
            'plan_id' => $plan->id,
        ]);

        $success = BehaviorEvent::query()
            ->where('event_type', BehaviorEventType::PlanGenerationImportSucceeded->value)
            ->firstOrFail();

        $this->assertSame('web', $success->metadata['surface']);
    }

    public function test_plan_update_prompt_and_preview_failure_are_recorded(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);

        $this->actingAs($user)
            ->post(route('plans.review_assistant.prompt', $plan), [
                'flow' => 'result_recording',
                'activity_summary' => '進捗を確認',
                '_client_surface' => 'web',
            ])
            ->assertRedirect(route('plans.review_assistant.show', $plan));

        $this->assertDatabaseHas('behavior_events', [
            'event_type' => BehaviorEventType::PlanUpdatePromptGenerated->value,
            'plan_id' => $plan->id,
        ]);

        $this->actingAs($user)
            ->from(route('plans.review_assistant.show', $plan))
            ->post(route('plans.review_assistant.preview', $plan), [
                'operations_json' => '{"schema_version":"2.0","operations":[',
                '_client_surface' => 'pwa',
            ])
            ->assertRedirect(route('plans.review_assistant.show', $plan));

        $this->assertDatabaseHas('behavior_events', [
            'event_type' => BehaviorEventType::PlanUpdatePreviewAttempted->value,
            'plan_id' => $plan->id,
        ]);
        $this->assertDatabaseHas('behavior_events', [
            'event_type' => BehaviorEventType::PlanUpdatePreviewFailed->value,
            'plan_id' => $plan->id,
        ]);

        $failed = BehaviorEvent::query()
            ->where('event_type', BehaviorEventType::PlanUpdatePreviewFailed->value)
            ->firstOrFail();

        $this->assertSame('pwa', $failed->metadata['surface']);
        $this->assertSame('invalid_json', $failed->metadata['failure_code']);
    }

    public function test_prompt_copy_click_can_be_recorded_from_client(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);

        $this->actingAs($user)
            ->postJson(route('behavior_events.store'), [
                'event_type' => BehaviorEventType::PlanGenerationPromptCopyClicked->value,
                'plan_id' => $plan->id,
                'metadata' => [
                    'surface' => 'pwa',
                    'device' => 'mobile',
                ],
            ])
            ->assertNoContent();

        $event = BehaviorEvent::query()
            ->where('event_type', BehaviorEventType::PlanGenerationPromptCopyClicked->value)
            ->firstOrFail();

        $this->assertSame('pwa', $event->metadata['surface']);
        $this->assertSame('mobile', $event->metadata['device']);
    }

    public function test_admin_can_view_aggregated_ai_funnel_diagnostics(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);

        BehaviorEvent::create([
            'actor_token' => Str::random(64),
            'event_type' => BehaviorEventType::PlanGenerationImportSucceeded,
            'plan_id' => $plan->id,
            'session_id' => 'telemetry-test-session',
            'occurred_at' => now(),
            'metadata' => ['surface' => 'web', 'device' => 'desktop'],
        ]);

        $this->actingAs($user)
            ->withSession(['feedback_admin_authenticated' => true])
            ->get(route('admin.telemetry.index'))
            ->assertOk()
            ->assertSee('初期計画ファネル')
            ->assertSee('計画更新ファネル')
            ->assertSee('JSON本文やプロンプト内容は保存せず');
    }

    private function plan(User $user): Plan
    {
        return Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => Str::uuid()->toString(),
            'title' => 'Telemetry plan',
            'category' => '個人開発',
            'description' => '',
            'start_date' => today()->toDateString(),
            'deadline' => today()->addMonth()->toDateString(),
            'is_public' => false,
        ]);
    }
}
