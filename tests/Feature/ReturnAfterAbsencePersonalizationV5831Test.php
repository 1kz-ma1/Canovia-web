<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\User;
use App\Models\UserPersonalizationContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReturnAfterAbsencePersonalizationV5831Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_first_tracked_visit_sets_presence_baseline_without_return_refresh(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');

        $user = $this->personalizedUser();

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk();

        $context = $this->context($user);

        $this->assertSame(
            now()->toIso8601String(),
            data_get(
                $context->observed_context,
                'presence.last_seen_at',
            ),
        );
        $this->assertNull(data_get(
            $context->observed_context,
            'presence.last_return_after_absence_at',
        ));
        $this->assertSame(
            0,
            $this->returnRefreshEvents()->count(),
        );
    }

    public function test_short_return_updates_presence_without_living_profile_refresh(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');

        $user = $this->personalizedUser();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();

        Carbon::setTestNow('2026-10-10 18:30:00');

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk();

        $context = $this->context($user);

        $this->assertSame(
            '2026-10-01T09:00:00+09:00',
            data_get(
                $context->observed_context,
                'presence.previous_seen_at',
            ),
        );
        $this->assertSame(
            now()->toIso8601String(),
            data_get(
                $context->observed_context,
                'presence.last_seen_at',
            ),
        );
        $this->assertNull(data_get(
            $context->observed_context,
            'presence.last_absence_days',
        ));
        $this->assertSame(
            0,
            $this->returnRefreshEvents()->count(),
        );
    }

    public function test_fourteen_day_return_triggers_one_refresh_and_preserves_initial_context(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');

        $user = $this->personalizedUser();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();

        Carbon::setTestNow('2026-10-15 10:00:00');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk();

        $context = $this->context($user);

        $this->assertSame(
            14,
            data_get(
                $context->observed_context,
                'presence.last_absence_days',
            ),
        );
        $this->assertSame(
            '14_29',
            data_get(
                $context->observed_context,
                'presence.last_absence_bucket',
            ),
        );
        $this->assertSame(
            now()->toIso8601String(),
            data_get(
                $context->observed_context,
                'presence.last_return_after_absence_at',
            ),
        );
        $this->assertSame(
            'started',
            data_get(
                $context->self_reported_context,
                'domain_context.study.stage',
            ),
        );
        $this->assertSame(
            'standard',
            $context->guidance_level,
        );

        $events = $this->returnRefreshEvents();

        $this->assertCount(1, $events);
        $this->assertSame(
            '14_29',
            data_get(
                $events->first()->metadata,
                'absence_bucket',
            ),
        );
    }

    public function test_longer_return_uses_coarse_absence_bucket(): void
    {
        Carbon::setTestNow('2026-07-01 09:00:00');

        $user = $this->personalizedUser();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();

        Carbon::setTestNow('2026-10-07 09:00:00');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();

        $context = $this->context($user);

        $this->assertSame(
            '60_plus',
            data_get(
                $context->observed_context,
                'presence.last_absence_bucket',
            ),
        );
        $this->assertGreaterThanOrEqual(
            60,
            data_get(
                $context->observed_context,
                'presence.last_absence_days',
            ),
        );

        $this->assertSame(
            '60_plus',
            data_get(
                $this->returnRefreshEvents()->firstOrFail()->metadata,
                'absence_bucket',
            ),
        );
    }

    public function test_prefetch_does_not_update_presence(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');

        $user = $this->personalizedUser();

        $this->actingAs($user)
            ->withHeader(
                'X-Canovia-Instant-Navigation',
                'prefetch',
            )
            ->get(route('home'))
            ->assertOk();

        $context = $this->context($user);

        $this->assertNull(data_get(
            $context->observed_context,
            'presence.last_seen_at',
        ));
        $this->assertSame(
            0,
            $this->returnRefreshEvents()->count(),
        );
    }

    public function test_user_without_personalization_context_is_not_silently_tracked_into_profile(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');

        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();

        $this->assertDatabaseMissing(
            'user_personalization_contexts',
            ['user_id' => $user->id],
        );
        $this->assertSame(
            0,
            $this->returnRefreshEvents()->count(),
        );
    }

    private function personalizedUser(): User
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('personalization.store'), [
                'domains' => ['study'],
                'weekly_capacity' => '5_10',
                'study_goal' => '応用情報技術者試験 合格',
                'study_kind' => 'qualification',
                'study_stage' => 'started',
            ])
            ->assertRedirect(route('personalization.result'));

        return $user;
    }

    private function context(
        User $user,
    ): UserPersonalizationContext {
        return UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    private function returnRefreshEvents()
    {
        return BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::ContextRefreshTriggered->value,
            )
            ->get()
            ->filter(
                fn (BehaviorEvent $event) =>
                    data_get($event->metadata, 'trigger')
                        === 'return_after_absence',
            )
            ->values();
    }
}
