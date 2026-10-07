<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Enums\ReleaseLevel;
use App\Models\BehaviorEvent;
use App\Models\Feedback;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\EarlyAccessInsightsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EarlyAccessObservabilityV5823Test extends TestCase
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

    public function test_level_two_user_sees_early_access_disclosure_with_feedback_entry(): void
    {
        config([
            'release_levels.public_level' => ReleaseLevel::ProductPreview->value,
        ]);

        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-early-access-disclosure', false)
            ->assertSee('Canoviaは先行公開中です')
            ->assertSee('data-feedback-open', false)
            ->assertSee(route('product.preview.index'), false);
    }

    public function test_internal_default_user_does_not_see_early_access_disclosure(): void
    {
        config([
            'release_levels.public_level' => ReleaseLevel::InternalPreview->value,
        ]);

        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-early-access-disclosure', false);
    }

    public function test_registration_records_early_access_cohort_event(): void
    {
        config([
            'release_levels.public_level' => ReleaseLevel::ProductPreview->value,
        ]);

        $this->post(route('auth.register'), [
            'name' => 'Early User',
            'email' => 'early@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect();

        $event = BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::EarlyAccessRegistered->value,
            )
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(
            ReleaseLevel::ProductPreview->value,
            (int) data_get($event->metadata, 'release_level'),
        );
    }

    public function test_daily_visit_is_recorded_once_per_day(): void
    {
        Carbon::setTestNow('2026-10-07 09:00:00');

        config([
            'release_levels.public_level' => ReleaseLevel::ProductPreview->value,
        ]);

        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk();

        $this->assertSame(
            1,
            BehaviorEvent::query()
                ->where(
                    'event_type',
                    BehaviorEventType::EarlyAccessSessionStarted->value,
                )
                ->count(),
        );

        Carbon::setTestNow('2026-10-08 09:00:00');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();

        $this->assertSame(
            2,
            BehaviorEvent::query()
                ->where(
                    'event_type',
                    BehaviorEventType::EarlyAccessSessionStarted->value,
                )
                ->count(),
        );

        Carbon::setTestNow();
    }

    public function test_feedback_keeps_early_access_release_context(): void
    {
        config([
            'release_levels.public_level' => ReleaseLevel::ProductPreview->value,
        ]);

        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('home'))
            ->post(route('feedback.store'), [
                'type' => 'usability',
                'rating' => 4,
                'message' => '導線を確認したい',
                'page' => '/',
            ])
            ->assertRedirect(route('home'));

        $feedback = Feedback::query()->latest('id')->firstOrFail();

        $this->assertTrue(
            (bool) data_get($feedback->context, 'early_access'),
        );
        $this->assertSame(
            ReleaseLevel::ProductPreview->value,
            (int) data_get($feedback->context, 'release_level'),
        );
    }

    public function test_insights_use_database_facts_for_activation_and_events_for_retention(): void
    {
        Carbon::setTestNow('2026-10-07 12:00:00');

        $user = User::factory()->create([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
            'first_run_completed_at' => now()->subDays(2)->addMinutes(5),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => str_repeat('a', 64),
            'public_slug' => 'early-access-plan',
            'title' => 'Early Access Plan',
            'priority' => 3,
            'priority_mode' => 'auto',
            'start_date' => now()->toDateString(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '最初のTask',
            'estimated_minutes' => 30,
            'remaining_minutes' => 0,
            'progress_percent' => 100,
            'status' => 'done',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        $actor = str_repeat('b', 64);
        BehaviorEvent::query()->create([
            'actor_token' => $actor,
            'event_type' => BehaviorEventType::EarlyAccessRegistered->value,
            'session_id' => 'register-session',
            'occurred_at' => now()->subDays(2),
            'metadata' => ['release_level' => 2],
        ]);
        BehaviorEvent::query()->create([
            'actor_token' => $actor,
            'event_type' => BehaviorEventType::EarlyAccessSessionStarted->value,
            'session_id' => 'return-session',
            'occurred_at' => now()->subDay(),
            'metadata' => ['release_level' => 2],
        ]);
        BehaviorEvent::query()->create([
            'actor_token' => $actor,
            'event_type' => BehaviorEventType::WorkStarted->value,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'session_id' => 'work-session',
            'occurred_at' => now()->subDay(),
            'metadata' => [],
        ]);
        BehaviorEvent::query()->create([
            'actor_token' => $actor,
            'event_type' => BehaviorEventType::WorkCompleted->value,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'session_id' => 'work-session',
            'occurred_at' => now()->subDay(),
            'metadata' => [],
        ]);

        Feedback::query()->create([
            'user_id' => $user->id,
            'actor_token' => $actor,
            'type' => 'positive',
            'rating' => 5,
            'message' => 'よかった',
            'page' => '/',
            'app_version' => 'test',
            'context' => ['early_access' => true],
            'status' => 'new',
        ]);

        $summary = app(EarlyAccessInsightsService::class)->summary(7);
        $stages = $summary['activation']->keyBy('key');

        $this->assertSame(1, $summary['registered_users']);
        $this->assertSame(1, $stages['first_run']['users']);
        $this->assertSame(1, $stages['plan']['users']);
        $this->assertSame(1, $stages['task']['users']);
        $this->assertSame(1, $stages['started']['users']);
        $this->assertSame(1, $stages['completed']['users']);
        $this->assertSame(1, $summary['retention']['d1']['eligible']);
        $this->assertSame(1, $summary['retention']['d1']['returned']);
        $this->assertSame(100.0, $summary['retention']['d1']['rate']);
        $this->assertSame(1, $summary['feedback']['total']);

        Carbon::setTestNow();
    }

    public function test_admin_can_open_early_access_observability_dashboard(): void
    {
        $admin = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);
        config(['canovia.super_admin_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->get(route('admin.early_access.index'))
            ->assertOk()
            ->assertSee('先行公開の行動を観測')
            ->assertSee('ACTIVATION')
            ->assertSee('D1 retention')
            ->assertSee('FEEDBACK MIX');
    }
}
