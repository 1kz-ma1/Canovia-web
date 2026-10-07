<?php

namespace App\Services;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

final class EarlyAccessTelemetryService
{
    private const SESSION_DAILY_KEY = 'canovia.early_access.daily_visit';

    public function __construct(
        private readonly EarlyAccessService $earlyAccess,
        private readonly ReleaseLevelService $levels,
        private readonly BehaviorIdentityService $identity,
        private readonly BehaviorEventLogger $events,
    ) {}

    public function recordRegistration(
        Request $request,
        User $user,
    ): void {
        if (! $this->earlyAccess->activeFor($user, $request)) {
            return;
        }

        $this->events->recordOnceSafely(
            $this->identity->resolve($request),
            BehaviorEventType::EarlyAccessRegistered,
            $request,
            metadata: [
                'release_level' => $this->levels
                    ->levelFor($user, $request)
                    ->value,
                'claimed_guest_data' => $user->plans()->exists(),
            ],
            withinMinutes: 1440,
        );
    }

    public function recordDailyVisit(Request $request): void
    {
        $user = $request->user();

        if (! $user || ! $this->earlyAccess->activeFor($user, $request)) {
            return;
        }

        if (
            $request->header('X-Canovia-Instant-Navigation') === 'prefetch'
            || ! $request->isMethod('GET')
        ) {
            return;
        }

        $today = now()->toDateString();
        if ($request->session()->get(self::SESSION_DAILY_KEY) === $today) {
            return;
        }

        $actorToken = $this->identity->resolve($request);

        $alreadyRecorded = false;

        try {
            $alreadyRecorded = BehaviorEvent::query()
                ->where('actor_token', $actorToken)
                ->where(
                    'event_type',
                    BehaviorEventType::EarlyAccessSessionStarted->value,
                )
                ->where('occurred_at', '>=', now()->startOfDay())
                ->exists();
        } catch (Throwable) {
            // Observability must never become a product outage.
        }

        if (! $alreadyRecorded) {
            $this->events->recordSafely(
                $actorToken,
                BehaviorEventType::EarlyAccessSessionStarted,
                $request,
                metadata: [
                    'release_level' => $this->levels
                        ->levelFor($user, $request)
                        ->value,
                    'account_age_days' => max(
                        0,
                        $user->created_at?->diffInDays(now()) ?? 0,
                    ),
                ],
            );
        }

        $request->session()->put(self::SESSION_DAILY_KEY, $today);
    }
}
