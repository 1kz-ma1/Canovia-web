<?php

namespace App\Services;

use App\Data\ProviderExecutionContextData;
use App\Execution\ExecutionCapability;
use App\Intelligence\Study\StudyAdaptiveActionService;
use App\Models\ExecutionActivity;
use App\Models\ProviderConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class ProviderActivityIntakeService
{
    public function __construct(
        private readonly ExecutionActivityService $activities,
        private readonly StudyAdaptiveActionService $studyActions,
    ) {}

    /**
     * @param array<string,mixed> $payload
     */
    public function accept(
        ProviderConnection $connection,
        ProviderExecutionContextData $context,
        array $payload,
    ): ExecutionActivity {
        $externalKey = trim((string) ($payload['external_key'] ?? ''));
        if (
            $externalKey === ''
            || mb_strlen($externalKey) > 191
            || preg_match('/^[A-Za-z0-9_.:-]+$/', $externalKey) !== 1
        ) {
            throw new InvalidArgumentException(
                'Activity external_key is invalid.',
            );
        }

        $type = mb_strtolower(trim((string) ($payload['type'] ?? '')));
        if (
            $type === ''
            || mb_strlen($type) > 64
            || preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $type) !== 1
        ) {
            throw new InvalidArgumentException(
                'Activity type is invalid.',
            );
        }

        $status = mb_strtolower(trim((string) ($payload['status'] ?? '')));
        if (! in_array($status, ExecutionActivity::STATUSES, true)) {
            throw new InvalidArgumentException(
                'Activity status is invalid.',
            );
        }

        $title = Str::limit(
            trim((string) ($payload['title'] ?? '')),
            255,
            '',
        );
        if ($title === '') {
            throw new InvalidArgumentException(
                'Activity title is required.',
            );
        }

        $durationSeconds = $this->nullableInt(
            $payload['duration_seconds'] ?? null,
        );
        if (
            $durationSeconds !== null
            && ($durationSeconds < 0 || $durationSeconds > 604800)
        ) {
            throw new InvalidArgumentException(
                'Activity duration is invalid.',
            );
        }

        $startedAt = $this->nullableDate(
            $payload['started_at'] ?? null,
        );
        $completedAt = $this->nullableDate(
            $payload['completed_at'] ?? null,
        );

        if (
            $startedAt !== null
            && $completedAt !== null
            && $completedAt->lessThan($startedAt)
        ) {
            throw new InvalidArgumentException(
                'Activity completed_at cannot precede started_at.',
            );
        }

        $metrics = $this->normalizedMetrics(
            $context->capability,
            $type,
            is_array($payload['metrics'] ?? null)
                ? $payload['metrics']
                : [],
        );

        $activity = $this->activities->record(
            providerKey: (string) $connection->provider_key,
            capability: $context->capability,
            type: $type,
            title: $title,
            status: $status,
            metrics: $metrics,
            metadata: [
                'intake_schema_version' => '1.0',
                'provider_connection_id' => (int) $connection->id,
            ],
            externalKey: $externalKey,
            userId: (int) $connection->user_id,
            startedAt: $startedAt,
            completedAt: $completedAt,
            durationSeconds: $durationSeconds,
        );

        $linked = $this->activities->linkToTask(
            $activity,
            $context->task,
        );

        if (
            $context->capability === ExecutionCapability::STUDY_PRACTICE
        ) {
            $this->studyActions->tryRefresh(
                $context->plan,
                now(),
            );
        }

        return $linked;
    }

    /**
     * @param array<string,mixed> $metrics
     * @return array<string,mixed>
     */
    private function normalizedMetrics(
        string $capability,
        string $type,
        array $metrics,
    ): array {
        if (
            $capability === ExecutionCapability::STUDY_PRACTICE
            && $type === 'study_practice_completed'
        ) {
            $score = $this->nullableInt(
                $metrics['score_percent'] ?? null,
            );

            if ($score === null || $score < 0 || $score > 100) {
                throw new InvalidArgumentException(
                    'Study Practice completion requires score_percent.',
                );
            }

            return [
                'score_percent' => $score,
                'strengths' => $this->stringList(
                    $metrics['strengths'] ?? [],
                ),
                'weaknesses' => $this->stringList(
                    $metrics['weaknesses'] ?? [],
                ),
                'weakness_topics' => $this->stringList(
                    $metrics['weakness_topics']
                        ?? $metrics['weaknesses']
                        ?? [],
                ),
            ];
        }

        return [];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        return $validated === false ? null : (int) $validated;
    }

    private function nullableDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            throw new InvalidArgumentException(
                'Activity timestamp is invalid.',
            );
        }
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(
                fn ($item) =>
                    is_scalar($item)
                    && trim((string) $item) !== '',
            )
            ->map(
                fn ($item) =>
                    mb_substr(trim((string) $item), 0, 191),
            )
            ->unique()
            ->take(24)
            ->values()
            ->all();
    }
}
