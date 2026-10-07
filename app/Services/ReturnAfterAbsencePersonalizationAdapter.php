<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

final class ReturnAfterAbsencePersonalizationAdapter
{
    public const LONG_ABSENCE_DAYS = 14;
    public const LONG_USAGE_SPAN_DAYS = 30;
    public const LONG_USAGE_ACTIVE_DAYS = 6;

    public function __construct(
        private readonly PersonalizationContextService $contexts,
        private readonly PersonalizationLivingProfileService $livingProfile,
    ) {}

    public function observeVisit(Request $request): void
    {
        if (
            ! $request->user()
            || ! $request->isMethod('GET')
            || $request->header('X-Canovia-Instant-Navigation') === 'prefetch'
        ) {
            return;
        }

        $context = $this->contexts->current($request);

        if (! $this->hasPersonalizationContext($context)) {
            return;
        }

        $presence = (array) data_get(
            $context,
            'context_sources.observed.presence',
            [],
        );

        $now = CarbonImmutable::instance(now());
        $lastSeen = $this->parseTimestamp(
            $presence['last_seen_at']
            ?? null,
        );

        if (! $lastSeen) {
            $presence['first_seen_at'] = $now->toIso8601String();
            $presence['last_seen_at'] = $now->toIso8601String();
            $presence['active_day_count'] = 1;
            $presence['last_usage_span_days'] = 0;

            $this->contexts->storeObservedCandidate(
                $request,
                ['presence' => $presence],
            );

            return;
        }

        $changed = false;
        $firstSeen = $this->parseTimestamp(
            $presence['first_seen_at']
            ?? null,
        );

        // V58.31 compatibility: establish a conservative baseline from the
        // first account-scoped Presence timestamp already available.
        if (! $firstSeen) {
            $firstSeen = $lastSeen;
            $presence['first_seen_at'] =
                $lastSeen->toIso8601String();
            $changed = true;
        }

        $activeDayCount = max(
            1,
            (int) ($presence['active_day_count'] ?? 1),
        );

        if (! isset($presence['active_day_count'])) {
            $presence['active_day_count'] = $activeDayCount;
            $changed = true;
        }

        if ($lastSeen->isSameDay($now)) {
            if ($changed) {
                $presence['last_usage_span_days'] = (int) $firstSeen
                    ->startOfDay()
                    ->diffInDays($now->startOfDay());

                $this->contexts->storeObservedCandidate(
                    $request,
                    ['presence' => $presence],
                );
            }

            return;
        }

        $absenceDays = (int) $lastSeen
            ->startOfDay()
            ->diffInDays($now->startOfDay());
        $usageSpanDays = (int) $firstSeen
            ->startOfDay()
            ->diffInDays($now->startOfDay());
        $activeDayCount++;

        $presence['previous_seen_at'] =
            $lastSeen->toIso8601String();
        $presence['last_seen_at'] =
            $now->toIso8601String();
        $presence['active_day_count'] =
            $activeDayCount;
        $presence['last_usage_span_days'] =
            $usageSpanDays;

        $isLongAbsence =
            $absenceDays >= self::LONG_ABSENCE_DAYS;

        if ($isLongAbsence) {
            $presence['last_return_after_absence_at'] =
                $now->toIso8601String();
            $presence['last_absence_days'] = $absenceDays;
            $presence['last_absence_bucket'] =
                $this->absenceBucket($absenceDays);
        }

        $longUsageReached = is_string(
            $presence['long_usage_window_reached_at']
            ?? null,
        ) && trim((string) $presence['long_usage_window_reached_at']) !== '';

        $isLongUsageWindow =
            ! $longUsageReached
            && $usageSpanDays >= self::LONG_USAGE_SPAN_DAYS
            && $activeDayCount >= self::LONG_USAGE_ACTIVE_DAYS;

        // If both conditions become true on the same request, return-after-
        // absence wins. Long-usage remains eligible for a later active day so
        // one real visit does not produce two Living Profile refreshes.
        if (! $isLongAbsence && $isLongUsageWindow) {
            $presence['long_usage_window_reached_at'] =
                $now->toIso8601String();
            $presence['long_usage_window_span_days'] =
                $usageSpanDays;
            $presence['long_usage_window_active_day_count'] =
                $activeDayCount;
        }

        $this->contexts->storeObservedCandidate(
            $request,
            ['presence' => $presence],
        );

        if ($isLongAbsence) {
            $this->livingProfile->refresh(
                $request,
                'return_after_absence',
                triggerMetadata: [
                    'absence_bucket' =>
                        $this->absenceBucket($absenceDays),
                ],
            );

            return;
        }

        if (! $isLongUsageWindow) {
            return;
        }

        $this->livingProfile->refresh(
            $request,
            'long_usage_window',
            triggerMetadata: [
                'usage_span_bucket' =>
                    $this->usageSpanBucket($usageSpanDays),
                'active_days_bucket' =>
                    $this->activeDaysBucket($activeDayCount),
            ],
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    private function hasPersonalizationContext(
        array $context,
    ): bool {
        return
            (array) data_get(
                $context,
                'context_sources.self_reported',
                [],
            ) !== []
            || (array) data_get(
                $context,
                'context_sources.observed',
                [],
            ) !== []
            || (array) data_get(
                $context,
                'context_sources.inferred',
                [],
            ) !== [];
    }

    private function parseTimestamp(
        mixed $value,
    ): ?CarbonImmutable {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function absenceBucket(int $days): string
    {
        return match (true) {
            $days >= 60 => '60_plus',
            $days >= 30 => '30_59',
            default => '14_29',
        };
    }

    private function usageSpanBucket(int $days): string
    {
        return match (true) {
            $days >= 90 => '90_plus',
            $days >= 60 => '60_89',
            default => '30_59',
        };
    }

    private function activeDaysBucket(int $days): string
    {
        return match (true) {
            $days >= 15 => '15_plus',
            $days >= 10 => '10_14',
            default => '6_9',
        };
    }
}
