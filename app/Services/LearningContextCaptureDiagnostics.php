<?php

namespace App\Services;

/**
 * Ephemeral, local/test-only learning source capture cost histogram (v0.19).
 *
 * No persistent storage, external telemetry, logs, tags, identifiers, payloads,
 * source hashes or per-call timings. No route/command exports this collector.
 * Buckets are intentionally fixed to prevent cardinality and privacy leakage.
 */
final class LearningContextCaptureDiagnostics
{
    private const OUTCOMES = ['captured', 'denied', 'task_cap', 'practice_cap', 'unavailable', 'error'];

    /** @var array<string,int> */
    private array $outcomes = [];

    /** @var array<string,int> */
    private array $durationBuckets = [];

    /** @var array<string,int> */
    private array $taskBuckets = [];

    /** @var array<string,int> */
    private array $practiceBuckets = [];

    private int $total = 0;

    public function enabled(): bool
    {
        return config('intelligence.learning_context_watch.capture_diagnostics_enabled') === true
            && in_array(config('app.env'), ['local', 'testing'], true);
    }

    public function record(string $outcome, int $elapsedNanoseconds, ?int $taskRows, ?int $practiceRows): void
    {
        if (! $this->enabled()) {
            return;
        }
        $key = in_array($outcome, self::OUTCOMES, true) ? $outcome : 'error';
        ++$this->total;
        $this->increment($this->outcomes, $key);

        // Integer thresholds avoid floats and retention of individual timings.
        $this->increment($this->durationBuckets, match (true) {
            $elapsedNanoseconds < 5_000_000 => 'lt_5ms',
            $elapsedNanoseconds < 25_000_000 => '5_25ms',
            $elapsedNanoseconds < 100_000_000 => '25_100ms',
            $elapsedNanoseconds < 500_000_000 => '100_500ms',
            default => 'gte_500ms',
        });
        $this->increment($this->taskBuckets, $this->sizeBucket($taskRows, 256));
        $this->increment($this->practiceBuckets, $this->sizeBucket($practiceRows, 512));
    }

    /** Only fixed, low-cardinality counters. No IDs, timestamps or observations. */
    public function snapshot(): array
    {
        return [
            'schema' => 'canovia.learning_capture_diagnostics.local.v0.19',
            'total' => $this->total,
            'outcomes' => $this->outcomes,
            'duration' => $this->durationBuckets,
            'task_rows' => $this->taskBuckets,
            'practice_rows' => $this->practiceBuckets,
            'persistent' => false,
            'authoritative' => false,
        ];
    }

    private function sizeBucket(?int $rows, int $limit): string
    {
        return match (true) {
            $rows === null => 'not_counted',
            $rows === 0 => '0',
            $rows <= 8 => '1_8',
            $rows <= 32 => '9_32',
            $rows <= 128 => '33_128',
            $rows <= $limit => $limit === 256 ? '129_256' : '129_512',
            default => 'over_cap',
        };
    }

    /** @param array<string,int> $histogram */
    private function increment(array &$histogram, string $bucket): void
    {
        $histogram[$bucket] = ($histogram[$bucket] ?? 0) + 1;
    }
}
