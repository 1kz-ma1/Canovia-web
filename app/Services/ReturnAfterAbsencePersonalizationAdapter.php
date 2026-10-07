<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

final class ReturnAfterAbsencePersonalizationAdapter
{
    public const LONG_ABSENCE_DAYS = 14;

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
            $presence['last_seen_at'] = $now->toIso8601String();

            $this->contexts->storeObservedCandidate(
                $request,
                ['presence' => $presence],
            );

            return;
        }

        if ($lastSeen->isSameDay($now)) {
            return;
        }

        $absenceDays = (int) $lastSeen
            ->startOfDay()
            ->diffInDays($now->startOfDay());

        $presence['previous_seen_at'] =
            $lastSeen->toIso8601String();
        $presence['last_seen_at'] =
            $now->toIso8601String();

        $isLongAbsence =
            $absenceDays >= self::LONG_ABSENCE_DAYS;

        if ($isLongAbsence) {
            $presence['last_return_after_absence_at'] =
                $now->toIso8601String();
            $presence['last_absence_days'] = $absenceDays;
            $presence['last_absence_bucket'] =
                $this->absenceBucket($absenceDays);
        }

        $this->contexts->storeObservedCandidate(
            $request,
            ['presence' => $presence],
        );

        if (! $isLongAbsence) {
            return;
        }

        $this->livingProfile->refresh(
            $request,
            'return_after_absence',
            triggerMetadata: [
                'absence_bucket' =>
                    $this->absenceBucket($absenceDays),
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
}
