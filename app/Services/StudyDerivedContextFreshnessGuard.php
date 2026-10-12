<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use Illuminate\Support\Arr;
use Throwable;

/**
 * G3/P0 first slice. Fail-closed read fence for *materialized* study behavior.
 *
 * A derived recommendation is not a source of truth. Until its bounded source
 * signature matches the current actor's practice attempts, it must not be
 * disclosed or reused, even if a queued refresh fails.
 */
final class StudyDerivedContextFreshnessGuard
{
    private const SOURCE_WINDOW = 12;

    public function signature(int $userId, int $planId): ?string
    {
        if ($userId <= 0 || $planId <= 0) {
            return null;
        }

        try {
            $allowed = Plan::query()
                ->whereKey($planId)
                ->where(function ($query) use ($userId) {
                    $query->where('user_id', $userId)
                        ->orWhere(function ($memberQuery) use ($userId) {
                            $memberQuery->where('is_collaborative', true)
                                ->whereHas('memberships', fn ($membership) =>
                                    $membership->where('user_id', $userId));
                        });
                })
                ->exists();

            if (! $allowed) {
                return null;
            }

            $query = StudyPracticeAttempt::query()
                ->where('user_id', $userId)
                ->where('plan_id', $planId);

            $count = (clone $query)->count();
            $recent = $query
                ->latest('created_at')
                ->latest('id')
                ->limit(self::SOURCE_WINDOW)
                ->get(['id', 'score_percent', 'created_at', 'updated_at']);

            // Source IDs bind this digest to a particular actor/Plan; updated_at
            // and scores catch corrections, count catches deletion/addition.
            $source = [
                'version' => 1,
                'actor' => $userId,
                'plan' => $planId,
                'total' => $count,
                'recent' => $recent->map(fn (StudyPracticeAttempt $attempt) => [
                    (int) $attempt->id,
                    $attempt->score_percent === null ? null : (int) $attempt->score_percent,
                    $attempt->created_at?->toIso8601String(),
                    $attempt->updated_at?->toIso8601String(),
                ])->all(),
            ];

            return hash('sha256', json_encode($source, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            // A database or lineage-read error must not release stale state.
            return null;
        }
    }

    /** @param array<string,mixed> $context
     *  @return array<string,mixed>
     */
    public function filter(array $context, int $userId): array
    {
        $observed = data_get($context, 'context_sources.observed.study_behavior');
        if (! is_array($observed)) {
            return $context;
        }

        $planId = (int) ($observed['plan_id'] ?? 0);
        $recorded = $observed['source_signature'] ?? null;
        $current = $this->signature($userId, $planId);

        if (is_string($recorded)
            && $recorded !== ''
            && $current !== null
            && hash_equals($recorded, $current)) {
            return $context;
        }

        // Legacy derived values without verifiable provenance are intentionally
        // hidden until regenerated. Never mutate self-reported user answers.
        $sources = (array) ($context['context_sources'] ?? []);
        $observations = (array) ($sources['observed'] ?? []);
        unset($observations['study_behavior']);
        $sources['observed'] = $observations;

        $inferred = (array) ($sources['inferred'] ?? []);
        $candidates = (array) ($inferred['update_candidates'] ?? []);
        unset($candidates['study_practice_focused'], $candidates['study_review_cycle']);
        $inferred['update_candidates'] = $candidates;
        $sources['inferred'] = $inferred;
        $context['context_sources'] = $sources;

        $readiness = (array) ($context['feature_readiness'] ?? []);
        $study = (array) ($readiness['study'] ?? []);
        unset($study['practice_focused'], $study['review_cycle']);
        if ($study === []) {
            unset($readiness['study']);
        } else {
            $readiness['study'] = $study;
        }
        $context['feature_readiness'] = $readiness;

        return $context;
    }
}
