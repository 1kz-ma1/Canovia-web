<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\User;
use App\Models\UserPersonalizationContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LongUsageWindowPersonalizationV5834Test extends TestCase
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

    public function test_sustained_usage_reaches_one_long_usage_window_refresh(): void
    {
        Carbon::setTestNow('2026-09-01 09:00:00');

        $user = $this->personalizedUser();

        foreach ([
            '2026-09-01 09:00:00',
            '2026-09-05 09:00:00',
            '2026-09-10 09:00:00',
            '2026-09-15 09:00:00',
            '2026-09-20 09:00:00',
            '2026-10-01 09:00:00',
        ] as $at) {
            $this->visit($user, $at);
        }

        $context = $this->context($user);

        $this->assertSame(
            6,
            data_get(
                $context->observed_context,
                'presence.active_day_count',
            ),
        );
        $this->assertSame(
            30,
            data_get(
                $context->observed_context,
                'presence.last_usage_span_days',
            ),
        );
        $this->assertSame(
            30,
            data_get(
                $context->observed_context,
                'presence.long_usage_window_span_days',
            ),
        );
        $this->assertSame(
            6,
            data_get(
                $context->observed_context,
                'presence.long_usage_window_active_day_count',
            ),
        );
        $this->assertNotNull(data_get(
            $context->observed_context,
            'presence.long_usage_window_reached_at',
        ));

        $events = $this->refreshEvents('long_usage_window');

        $this->assertCount(1, $events);
        $this->assertSame(
            '30_59',
            data_get(
                $events->first()->metadata,
                'usage_span_bucket',
            ),
        );
        $this->assertSame(
            '6_9',
            data_get(
                $events->first()->metadata,
                'active_days_bucket',
            ),
        );
        $this->assertNull(data_get(
            $events->first()->metadata,
            'usage_span_days',
        ));
        $this->assertNull(data_get(
            $events->first()->metadata,
            'active_day_count',
        ));

        $this->visit($user, '2026-10-01 18:00:00');
        $this->visit($user, '2026-10-02 09:00:00');

        $this->assertCount(
            1,
            $this->refreshEvents('long_usage_window'),
        );
    }

    public function test_calendar_span_alone_does_not_imply_long_usage(): void
    {
        Carbon::setTestNow('2026-09-01 09:00:00');

        $user = $this->personalizedUser();

        foreach ([
            '2026-09-01 09:00:00',
            '2026-09-10 09:00:00',
            '2026-09-20 09:00:00',
            '2026-10-01 09:00:00',
        ] as $at) {
            $this->visit($user, $at);
        }

        $context = $this->context($user);

        $this->assertSame(
            4,
            data_get(
                $context->observed_context,
                'presence.active_day_count',
            ),
        );
        $this->assertSame(
            30,
            data_get(
                $context->observed_context,
                'presence.last_usage_span_days',
            ),
        );
        $this->assertNull(data_get(
            $context->observed_context,
            'presence.long_usage_window_reached_at',
        ));
        $this->assertCount(
            0,
            $this->refreshEvents('long_usage_window'),
        );
    }

    public function test_long_absence_wins_when_both_milestones_become_eligible_on_same_visit(): void
    {
        Carbon::setTestNow('2026-09-01 09:00:00');

        $user = $this->personalizedUser();

        foreach ([
            '2026-09-01 09:00:00',
            '2026-09-03 09:00:00',
            '2026-09-05 09:00:00',
            '2026-09-07 09:00:00',
            '2026-09-10 09:00:00',
            '2026-10-01 09:00:00',
        ] as $at) {
            $this->visit($user, $at);
        }

        $context = $this->context($user);

        $this->assertSame(
            6,
            data_get(
                $context->observed_context,
                'presence.active_day_count',
            ),
        );
        $this->assertSame(
            30,
            data_get(
                $context->observed_context,
                'presence.last_usage_span_days',
            ),
        );
        $this->assertNull(data_get(
            $context->observed_context,
            'presence.long_usage_window_reached_at',
        ));
        $this->assertCount(
            1,
            $this->refreshEvents('return_after_absence'),
        );
        $this->assertCount(
            0,
            $this->refreshEvents('long_usage_window'),
        );

        $this->visit($user, '2026-10-02 09:00:00');

        $context = $this->context($user);

        $this->assertSame(
            7,
            data_get(
                $context->observed_context,
                'presence.long_usage_window_active_day_count',
            ),
        );
        $this->assertCount(
            1,
            $this->refreshEvents('long_usage_window'),
        );
    }

    public function test_v5831_presence_is_backfilled_conservatively_without_false_milestone(): void
    {
        Carbon::setTestNow('2026-10-07 09:00:00');

        $user = $this->personalizedUser();
        $context = $this->context($user);

        $context->forceFill([
            'observed_context' => [
                ...(array) $context->observed_context,
                'presence' => [
                    'last_seen_at' =>
                        now()->toIso8601String(),
                ],
            ],
        ])->save();

        $this->visit($user, '2026-10-07 12:00:00');

        $context = $this->context($user);

        $this->assertSame(
            '2026-10-07T09:00:00+09:00',
            data_get(
                $context->observed_context,
                'presence.first_seen_at',
            ),
        );
        $this->assertSame(
            1,
            data_get(
                $context->observed_context,
                'presence.active_day_count',
            ),
        );
        $this->assertSame(
            0,
            data_get(
                $context->observed_context,
                'presence.last_usage_span_days',
            ),
        );
        $this->assertCount(
            0,
            $this->refreshEvents('long_usage_window'),
        );
    }

    public function test_same_day_navigation_does_not_increase_active_day_count(): void
    {
        Carbon::setTestNow('2026-10-07 09:00:00');

        $user = $this->personalizedUser();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();

        Carbon::setTestNow('2026-10-07 18:00:00');

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk();

        $context = $this->context($user);

        $this->assertSame(
            1,
            data_get(
                $context->observed_context,
                'presence.active_day_count',
            ),
        );
    }

    public function test_user_without_personalization_context_is_not_silently_enrolled_in_usage_window(): void
    {
        Carbon::setTestNow('2026-10-07 09:00:00');

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
        $this->assertCount(
            0,
            $this->refreshEvents('long_usage_window'),
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

    private function visit(
        User $user,
        string $at,
    ): void {
        Carbon::setTestNow($at);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();
    }

    private function context(
        User $user,
    ): UserPersonalizationContext {
        return UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    private function refreshEvents(string $trigger)
    {
        return BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::ContextRefreshTriggered->value,
            )
            ->get()
            ->filter(
                fn (BehaviorEvent $event) =>
                    data_get($event->metadata, 'trigger') === $trigger,
            )
            ->values();
    }
}
