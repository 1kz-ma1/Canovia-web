<?php

namespace App\Services;

use App\Enums\BehaviorEventType;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use Illuminate\Http\Request;

final class StudyBehaviorPersonalizationAdapter
{
    private const CANDIDATE_KEY = 'study_practice_focused';
    private const MIN_ASSESSED_ATTEMPTS = 3;

    public function __construct(
        private readonly PersonalizationContextService $contexts,
        private readonly BehaviorIdentityService $identity,
        private readonly BehaviorEventLogger $events,
        private readonly StudyReviewCyclePersonalizationAdapter $reviewCycle,
    ) {}

    /**
     * Bridge durable Study Practice facts into the Living Profile.
     *
     * This adapter never rewrites self-reported Study stage, Guidance Level,
     * or Plan content. It only records observed facts and low-risk derived
     * recommendation state.
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

        $attempts = StudyPracticeAttempt::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->latest('created_at')
            ->latest('id')
            ->take(8)
            ->get();

        $attemptCount = StudyPracticeAttempt::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->count();

        if ($attemptCount < self::MIN_ASSESSED_ATTEMPTS) {
            return;
        }

        $scores = $attempts
            ->pluck('score_percent')
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->values();

        $observed = [
            'study_behavior' => [
                'stage' => 'practice_focused',
                'plan_id' => (int) $plan->id,
                'assessed_attempt_count' => $attemptCount,
                'recent_average_score_percent' => $scores->isNotEmpty()
                    ? (int) round($scores->average())
                    : null,
                'observed_at' => now()->toIso8601String(),
            ],
        ];

        $this->events->recordOnceSafely(
            $this->identity->resolve($request),
            BehaviorEventType::ContextRefreshTriggered,
            $request,
            $plan,
            metadata: [
                'trigger' => 'study_practice_assessed',
            ],
            withinMinutes: 5,
        );

        // Every assessed attempt is a new observed fact. Preserve the latest
        // count/average while keeping inference separate.
        $this->contexts->storeObservedCandidate(
            $request,
            $observed,
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
                'kind' => 'learning_stage_observation',
                'risk' => 'low',
                'confidence' => 'high',
                'status' => 'auto_applied',
                'confirmation_required' => false,
                'trigger' => 'study_practice_assessed',
                'evidence' => [
                    'assessed_study_practice_attempts>=3',
                ],
                'proposal' => [
                    'feature_readiness_key' =>
                        'study.practice_focused',
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
            'study.practice_focused.enabled',
            false,
        );

        if ($candidateCreated || ! $alreadyApplied) {
            if (! $alreadyApplied) {
                data_set(
                $featureReadiness,
                'study.practice_focused',
                [
                    'enabled' => true,
                    'source' => 'observed_study_behavior',
                    'candidate_key' => self::CANDIDATE_KEY,
                    'plan_id' => (int) $plan->id,
                    'activated_at' => now()->toIso8601String(),
                    ],
                );
            }

            $this->contexts->saveLivingProfileState(
                $request,
                $inferred,
                null,
                $featureReadiness,
            );

            if (! $alreadyApplied) {
                $this->events->recordSafely(
                    $this->identity->resolve($request),
                    BehaviorEventType::ContextUpdateAutoApplied,
                    $request,
                    $plan,
                    metadata: [
                        'candidate_key' => self::CANDIDATE_KEY,
                        'domain' => 'study',
                        'change_type' => 'practice_focused_readiness',
                    ],
                );
            }
        }

        $this->reviewCycle->observeAssessedPractice(
            $request,
            $plan,
        );
    }
}
