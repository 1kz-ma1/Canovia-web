<?php

namespace App\Services;

use App\Models\LearningContextChangeRecord;
use App\Models\LearningContextWatchState;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Internal, explicitly opted-in persistence. NO scheduler, route, notification
 * sender, external API or delegated permission is registered by this service.
 *
 * The Plan row is always locked before the state row. The source capture is
 * executed within that transaction; legacy writers which ignore Plan locks
 * still require an independent concurrency audit before delivery can exist.
 */
final class LearningContextWatchPersistenceService
{
    private const RETENTION_DAYS = 30;
    private const MAX_RECORDS = 32;

    public function __construct(
        private readonly LearningContextSourceCaptureService $capture,
        private readonly LearningContextChangeDetectionService $detector,
    ) {}

    private function enabled(): bool
    {
        return config('intelligence.learning_context_watch.persistence_enabled') === true
            && config('app.env') !== 'staging';
    }

    /** Explicit owner-session action only; NOT wired to a route or cron. */
    public function optIn(User $actor, int $planId): bool
    {
        if (! $this->enabled()
            || $actor->id === null || $planId <= 0
            || (int) Auth::id() !== (int) $actor->id) {
            return false;
        }

        try {
            return DB::transaction(function () use ($actor, $planId): bool {
                $plan = Plan::query()->whereKey($planId)->lockForUpdate()->first();
                if (! $this->validOwnerPlan($actor, $plan)) {
                    return false;
                }
                $current = $this->capture->capture($actor, $planId);
                if ($current === null) {
                    return false;
                }

                // Opt-in/reconsent always resets previous comparisons. The
                // unique Plan key prevents multiple actor baselines.
                LearningContextChangeRecord::query()->where('plan_id', $planId)->delete();
                $state = LearningContextWatchState::query()
                    ->where('plan_id', $planId)->lockForUpdate()->first();
                if ($state !== null) {
                    $state->forceFill([
                        'user_id' => $actor->id,
                        'status' => 'enabled',
                        'source_revision' => $current['source_revision'],
                        'encrypted_baseline' => $current,
                        'consented_at' => now(),
                    ])->save();
                } else {
                    LearningContextWatchState::query()->create([
                        'user_id' => $actor->id,
                        'plan_id' => $planId,
                        'status' => 'enabled',
                        'source_revision' => $current['source_revision'],
                        'encrypted_baseline' => $current,
                        'consented_at' => now(),
                    ]);
                }
                return true;
            }, 3);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Can be run only by a trusted, explicitly invoked first-party caller.
     * A persisted preference is NOT authorization to deliver anything.
     *
     * @return array{status:string,notification_eligible:bool,mutation_performed:bool}
     */
    public function advance(User $actor, int $planId): array
    {
        if (! $this->enabled() || $planId <= 0 || $actor->id === null) {
            return $this->result('disabled');
        }

        try {
            return DB::transaction(function () use ($actor, $planId): array {
                $plan = Plan::query()->whereKey($planId)->lockForUpdate()->first();
                if (! $this->validOwnerPlan($actor, $plan)) {
                    return $this->result('unauthorized');
                }
                $state = LearningContextWatchState::query()
                    ->where('plan_id', $planId)->lockForUpdate()->first();
                if ($state === null || $state->status !== 'enabled'
                    || (int) $state->user_id !== (int) $actor->id
                    || $state->consented_at === null) {
                    return $this->result('not_opted_in');
                }
                $before = $state->encrypted_baseline;
                if (! is_array($before)
                    || ($before['schema'] ?? null) !== LearningContextSourceCaptureService::CAPTURE_SCHEMA
                    || (int) ($before['actor_id'] ?? 0) !== (int) $actor->id
                    || (int) ($before['plan_id'] ?? 0) !== $planId
                    || ($before['source_revision'] ?? null) !== $state->source_revision
                    || ! is_string($state->source_revision)) {
                    return $this->result('baseline_invalid');
                }

                $current = $this->capture->capture($actor, $planId);
                if ($current === null) {
                    return $this->result('capture_unavailable');
                }
                if (hash_equals((string) $state->source_revision, $current['source_revision'])) {
                    return $this->result('unchanged');
                }

                $change = $this->detector->compare($before, $current);
                if (($change['status'] ?? null) === 'blocked') {
                    // A corrupted/mismatched baseline may not be overwritten
                    // silently. Require a new explicit opt-in.
                    return $this->result('rebaseline_required');
                }

                // Double-check revisions before committing the comparison.
                // A future deliverer must recheck independently again.
                if (! $this->capture->isCurrent($actor, $planId, $current['source_revision'])) {
                    return $this->result('source_changed_during_comparison');
                }

                LearningContextChangeRecord::query()
                    ->where('plan_id', $planId)
                    ->where('status', 'review_only')
                    ->update(['status' => 'superseded', 'updated_at' => now()]);

                LearningContextChangeRecord::query()
                    ->where('plan_id', $planId)->where('expires_at', '<=', now())
                    ->delete();

                $status = 'updated_no_candidate';
                if (($change['status'] ?? null) === 'review_candidate') {
                    $fingerprint = $change['dedupe_fingerprint'] ?? null;
                    if (! is_string($fingerprint)
                        || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
                        return $this->result('comparison_invalid');
                    }
                    $seen = LearningContextChangeRecord::query()
                        ->where('plan_id', $planId)
                        ->where('dedupe_fingerprint', $fingerprint)
                        ->exists();
                    if ($seen) {
                        $status = 'duplicate_suppressed';
                    } else {
                        $kinds = collect($change['events'] ?? [])
                            ->pluck('kind')->filter(fn ($kind) => in_array($kind, [
                                'owner_recorded_deadline_changed',
                                'recorded_task_paused',
                                'recent_practice_sample_changed',
                                'recent_weakness_tag_priority_changed',
                            ], true))->unique()->take(4)->values()->all();
                        if ($kinds === []) {
                            return $this->result('comparison_invalid');
                        }
                        LearningContextChangeRecord::query()->create([
                            'user_id' => $actor->id,
                            'plan_id' => $planId,
                            'dedupe_fingerprint' => $fingerprint,
                            'source_revision' => $current['source_revision'],
                            'event_kinds' => $kinds,
                            'status' => 'review_only',
                            'expires_at' => now()->addDays(self::RETENTION_DAYS),
                        ]);
                        $status = 'recorded_review_candidate';
                    }
                }

                // No snapshot is stored in event rows. The baseline is
                // encrypted-at-rest; revised rows always supersede prior
                // review candidates even when the current delta is minor.
                $state->forceFill([
                    'source_revision' => $current['source_revision'],
                    'encrypted_baseline' => $current,
                ])->save();

                $older = LearningContextChangeRecord::query()
                    ->where('plan_id', $planId)
                    ->orderByDesc('id')
                    ->skip(self::MAX_RECORDS)
                    ->pluck('id')->all();
                if ($older !== []) {
                    LearningContextChangeRecord::query()
                        ->where('plan_id', $planId)->whereIn('id', $older)->delete();
                }

                return $this->result($status);
            }, 3);
        } catch (Throwable $e) {
            // Expose failing fixture details ONLY to disposable CI. Production
            // must never reveal private source/persistence errors.
            if (app()->environment('testing')) {
                throw $e;
            }
            return $this->result('blocked_error');
        }
    }

    /**
     * Opt-out can remove retained data even while the feature flag is OFF.
     * Only the current authenticated owner may revoke, via a future route.
     */
    public function optOut(User $actor, int $planId): bool
    {
        if ($actor->id === null || $planId <= 0
            || (int) Auth::id() !== (int) $actor->id) {
            return false;
        }
        try {
            return DB::transaction(function () use ($actor, $planId): bool {
                $plan = Plan::query()->whereKey($planId)->lockForUpdate()->first();
                // Opt-out must remain possible after public/collaborative or
                // Study category changes; it only needs CURRENT ownership.
                if ($plan === null || $plan->user_id === null
                    || (int) $plan->user_id !== (int) $actor->id) {
                    return false;
                }
                LearningContextChangeRecord::query()->where('plan_id', $planId)->delete();
                LearningContextWatchState::query()->where('plan_id', $planId)->delete();
                return true;
            }, 3);
        } catch (Throwable) {
            return false;
        }
    }

    private function validOwnerPlan(User $actor, ?Plan $plan): bool
    {
        // The capture service will independently check Study classification.
        return $plan !== null && $plan->user_id !== null
            && (int) $plan->user_id === (int) $actor->id
            && ! (bool) $plan->is_collaborative
            && ! (bool) $plan->is_public;
    }

    /** @return array{status:string,notification_eligible:bool,mutation_performed:bool} */
    private function result(string $status): array
    {
        return [
            'status' => $status,
            'notification_eligible' => false,
            'mutation_performed' => false,
        ];
    }
}
