<?php

namespace App\Services;

use App\Enums\BehaviorEventType;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use Illuminate\Http\Request;

final class StudyReviewCyclePersonalizationAdapter
{
    private const CANDIDATE_KEY = 'study_review_cycle';
    private const MIN_ASSESSED_ATTEMPTS = 5;
    private const MIN_DISTINCT_PRACTICE_DAYS = 2;
    private const MIN_SPAN_DAYS = 3;
    private const RECENT_ATTEMPT_WINDOW = 12;

    public function __construct(
        private readonly PersonalizationContextService $contexts,
        private readonly BehaviorIdentityService $identity,
        private readonly BehaviorEventLogger $events,
    ) {}

    /**
     * Observe a spaced review cycle without claiming mastery or retention.
     */
    public function observeAssessedPractice(
        Request $request,
        Plan $plan,
    ): void {
        $user = $request->user();
        if (! $user) {
            return;
        }

        $context = $this->contexts->current($request);
        if (! in_array('study', (array) ($context['domains'] ?? []), true)) {
            return;
        }

        $attemptCount = StudyPracticeAttempt::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->count();

        if ($attemptCount < self::MIN_ASSESSED_ATTEMPTS) {
            return;
        }

        $attempts = StudyPracticeAttempt::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->latest('created_at')
            ->latest('id')
            ->take(self::RECENT_ATTEMPT_WINDOW)
            ->get();

        $chronological = $attempts
            ->sortBy(
                fn (StudyPracticeAttempt $attempt) =>
                    $attempt->created_at?->getTimestamp() ?? 0,
            )
            ->values();

        $firstAttemptAt = $chronological->first()?->created_at;
        $latestAttemptAt = $chronological->last()?->created_at;

        if (! $firstAttemptAt || ! $latestAttemptAt) {
            return;
        }

        $practiceDays = $chronological
            ->map(
                fn (StudyPracticeAttempt $attempt) =>
                    $attempt->created_at?->toDateString(),
            )
            ->filter()
            ->unique()
            ->values();

        $spanDays = (int) $firstAttemptAt
            ->copy()
            ->startOfDay()
            ->diffInDays(
                $latestAttemptAt->copy()->startOfDay(),
            );

        if (
            $practiceDays->count() < self::MIN_DISTINCT_PRACTICE_DAYS
            || $spanDays < self::MIN_SPAN_DAYS
        ) {
            return;
        }

        $scores = $chronological
            ->pluck('score_percent')
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->values();

        $studyBehavior = (array) data_get(
            $context,
            'context_sources.observed.study_behavior',
            [],
        );

        $studyBehavior['review_cycle'] = [
            'stage' => 'spaced_review_observed',
            'plan_id' => (int) $plan->id,
            'assessed_attempt_count' => $attemptCount,
            'recent_attempt_window_count' => $chronological->count(),
            'distinct_practice_days' => $practiceDays->count(),
            'span_days' => $spanDays,
            'recent_average_score_percent' => $scores->isNotEmpty()
                ? (int) round($scores->average())
                : null,
            'first_attempt_at' => $firstAttemptAt->toIso8601String(),
            'latest_attempt_at' => $latestAttemptAt->toIso8601String(),
            'observed_at' => now()->toIso8601String(),
        ];

        $this->contexts->storeObservedCandidate(
            $request,
            ['study_behavior' => $studyBehavior],
        );

        $fresh = $this->contexts->current($request);
        $inferred = is_array(data_get(
            $fresh,
            'context_sources.inferred',
        ))
            ? data_get($fresh, 'context_sources.inferred')
            : [];
        $candidates = is_array($inferred['update_candidates'] ?? null)
            ? $inferred['update_candidates']
            : [];

        $candidateCreated = false;

        if (! isset($candidates[self::CANDIDATE_KEY])) {
            $candidateCreated = true;
            $candidates[self::CANDIDATE_KEY] = [
                'key' => self::CANDIDATE_KEY,
                'domain' => 'study',
                'kind' => 'review_cycle_observation',
                'risk' => 'low',
                'confidence' => 'high',
                'status' => 'auto_applied',
                'confirmation_required' => false,
                'trigger' => 'study_practice_assessed',
                'evidence' => [
                    'assessed_study_practice_attempts>=5',
                    'distinct_practice_days>=2',
                    'practice_span_days>=3',
                ],
                'proposal' => [
                    'feature_readiness_key' => 'study.review_cycle',
                ],
                'created_at' => now()->toIso8601String(),
                'resolved_at' => now()->toIso8601String(),
            ];

            $this->events->recordSafely(
                $this->identity->resolve($request),
                BehaviorEventType::ContextUpdateCandidateCreated,
                $request,
                $plan,
                metadata: [
                    'candidate_key' => self::CANDIDATE_KEY,
                    'domain' => 'study',
                    'risk' => 'low',
                    'confirmation_required' => false,
                ],
            );
        }

        $inferred['update_candidates'] = $candidates;

        $featureReadiness = is_array($fresh['feature_readiness'] ?? null)
            ? $fresh['feature_readiness']
            : [];
        $alreadyApplied = (bool) data_get(
            $featureReadiness,
            'study.review_cycle.enabled',
            false,
        );

        if (! $alreadyApplied) {
            data_set(
                $featureReadiness,
                'study.review_cycle',
                [
                    'enabled' => true,
                    'source' => 'observed_spaced_study_behavior',
                    'candidate_key' => self::CANDIDATE_KEY,
                    'plan_id' => (int) $plan->id,
                    'activated_at' => now()->toIso8601String(),
                ],
            );
        }

        if ($candidateCreated || ! $alreadyApplied) {
            $this->contexts->saveLivingProfileState(
                $request,
                $inferred,
                null,
                $featureReadiness,
            );
        }

        if (! $alreadyApplied) {
            $this->events->recordSafely(
                $this->identity->resolve($request),
                BehaviorEventType::ContextUpdateAutoApplied,
                $request,
                $plan,
                metadata: [
                    'candidate_key' => self::CANDIDATE_KEY,
                    'domain' => 'study',
                    'change_type' => 'review_cycle_readiness',
                ],
            );
        }
    }
}
