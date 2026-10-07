<?php

namespace App\Services;

use App\Enums\BehaviorEventType;
use App\Models\Plan;
use Illuminate\Http\Request;

final class PersonalizationLivingProfileService
{
    private const CANDIDATES_KEY = 'update_candidates';

    public function __construct(
        private readonly PersonalizationContextService $contexts,
        private readonly BehaviorIdentityService $identity,
        private readonly BehaviorEventLogger $events,
        private readonly DevelopmentPersonalizationSignalService $developmentSignals,
        private readonly PersonalizationConfidenceCalibrationService $confidenceCalibration,
    ) {}

    /**
     * Re-evaluate only deterministic, currently supported signals.
     * This is not an AI inference engine.
     *
     * @return array<string,mixed>
     */
    public function refresh(
        Request $request,
        string $trigger = 'manual',
        ?Plan $plan = null,
        array $triggerMetadata = [],
    ): array {
        if (! $request->user()) {
            return $this->summary($request);
        }

        $context = $this->contexts->current($request);
        $hasContext =
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

        if (! $hasContext) {
            return $this->summary($request);
        }

        $inferred = is_array(data_get(
            $context,
            'context_sources.inferred',
        ))
            ? data_get($context, 'context_sources.inferred')
            : [];
        $candidates = is_array($inferred[self::CANDIDATES_KEY] ?? null)
            ? $inferred[self::CANDIDATES_KEY]
            : [];
        $recommendedSurfaces = array_values(
            (array) ($context['recommended_surfaces'] ?? []),
        );
        $featureReadiness = is_array($context['feature_readiness'] ?? null)
            ? $context['feature_readiness']
            : [];

        $safeTrigger = $this->safeTrigger($trigger);
        $eventMetadata = [
            ...$triggerMetadata,
            'trigger' => $safeTrigger,
        ];

        if (in_array($safeTrigger, ['new_plan', 'plan_completed'], true)) {
            $this->events->recordSafely(
                $this->identity->resolve($request),
                BehaviorEventType::ContextRefreshTriggered,
                $request,
                $plan,
                metadata: $eventMetadata,
            );
        } else {
            $this->events->recordOnceSafely(
                $this->identity->resolve($request),
                BehaviorEventType::ContextRefreshTriggered,
                $request,
                $plan,
                metadata: $eventMetadata,
                withinMinutes: 5,
            );
        }

        $recentDomain = (string) (
            $request->user()?->workspace_mode_preference
            ?? ''
        );

        if (in_array($recentDomain, ['study', 'development'], true)) {
            $surface = 'workspace.'.$recentDomain;
            $previous = $recommendedSurfaces;

            $recommendedSurfaces = array_values(array_unique([
                $surface,
                ...array_values(array_filter(
                    $recommendedSurfaces,
                    fn ($value) => $value !== $surface,
                )),
            ]));

            $candidateKey = 'recent_domain_priority_'.$recentDomain;
            if (! isset($candidates[$candidateKey])) {
                $candidates[$candidateKey] = $this->candidate(
                    key: $candidateKey,
                    domain: $recentDomain,
                    kind: 'recommended_surface_priority',
                    risk: 'low',
                    confidence: 'high',
                    status: 'auto_applied',
                    trigger: $trigger,
                    evidence: ['workspace_mode_preference'],
                    proposal: [
                        'surface' => $surface,
                    ],
                    resolvedAt: now()->toIso8601String(),
                );

                $this->events->recordSafely(
                    $this->identity->resolve($request),
                    BehaviorEventType::ContextUpdateCandidateCreated,
                    $request,
                    $plan,
                    metadata: [
                        'candidate_key' => $candidateKey,
                        'domain' => $recentDomain,
                        'risk' => 'low',
                        'confirmation_required' => false,
                    ],
                );
            }

            if ($recommendedSurfaces !== $previous) {
                $this->events->recordSafely(
                    $this->identity->resolve($request),
                    BehaviorEventType::ContextUpdateAutoApplied,
                    $request,
                    $plan,
                    metadata: [
                        'candidate_key' => $candidateKey,
                        'domain' => $recentDomain,
                        'change_type' => 'recommended_surface_priority',
                    ],
                );
            }

            $observed = (array) data_get(
                $context,
                'context_sources.observed',
                [],
            );
            if (
                data_get($observed, 'workspace.recent_domain')
                !== $recentDomain
            ) {
                $this->contexts->storeObservedCandidate(
                    $request,
                    [
                        'workspace' => [
                            'recent_domain' => $recentDomain,
                            'observed_at' => now()->toIso8601String(),
                        ],
                    ],
                );
            }
        }

        $githubConnected = data_get(
            $context,
            'context_sources.observed.github_integration.status',
        ) === 'connected';
        $selfReportedExperience = (string) data_get(
            $context,
            'domain_context.development.experience',
            '',
        );

        if (
            $githubConnected
            && in_array(
                $selfReportedExperience,
                ['beginner', 'standard'],
                true,
            )
        ) {
            $candidateKey = 'development_advanced_support';
            $existing = is_array($candidates[$candidateKey] ?? null)
                ? $candidates[$candidateKey]
                : [];
            $status = (string) ($existing['status'] ?? '');
            $signal = $this->developmentSignals
                ->advancedSupport($request->user());
            $signalStrength = max(
                1,
                (int) ($signal['signal_strength'] ?? 1),
            );

            $developmentBehavior = [
                'connected_repository_count' => (int) (
                    $signal['connected_repository_count']
                    ?? 0
                ),
                'recent_activity_count' => (int) (
                    $signal['recent_activity_count']
                    ?? 0
                ),
                'recent_pr_or_commit_count' => (int) (
                    $signal['recent_pr_or_commit_count']
                    ?? 0
                ),
                'active_day_count_90d' => (int) (
                    $signal['active_day_count_90d']
                    ?? 0
                ),
                'activity_span_days_90d' => (int) (
                    $signal['activity_span_days_90d']
                    ?? 0
                ),
            ];
            $observedDevelopmentBehavior = (array) data_get(
                $context,
                'context_sources.observed.development_behavior',
                [],
            );

            if (
                array_intersect_key(
                    $observedDevelopmentBehavior,
                    $developmentBehavior,
                ) !== $developmentBehavior
            ) {
                $this->contexts->storeObservedCandidate(
                    $request,
                    [
                        'development_behavior' => [
                            ...$developmentBehavior,
                            'observed_at' =>
                                now()->toIso8601String(),
                        ],
                    ],
                );
            }

            $fingerprint = (string) (
                $signal['evidence_fingerprint']
                ?? ''
            );
            $evidence = array_values(
                (array) ($signal['evidence'] ?? [
                    'github_integration_connected',
                ]),
            );
            $calibration = $this->confidenceCalibration->calibrate(
                risk: 'high',
                signalStrength: $signalStrength,
                evidence: $evidence,
            );

            if ($status === '') {
                $candidates[$candidateKey] = $this->candidate(
                    key: $candidateKey,
                    domain: 'development',
                    kind: 'advanced_support_offer',
                    risk: 'high',
                    confidence: $calibration['confidence'],
                    status: 'pending',
                    trigger: $trigger,
                    evidence: $evidence,
                    proposal: [
                        'feature_readiness_key' =>
                            'development.advanced_support',
                    ],
                    signalStrength: $signalStrength,
                    evidenceFingerprint: $fingerprint,
                    evidenceRevision: 1,
                );
                $candidates[$candidateKey]['confidence_calibration'] =
                    $calibration;

                $this->recordCandidateEvent(
                    $request,
                    $plan,
                    $candidateKey,
                    signalStrength: $signalStrength,
                    evidenceRevision: 1,
                    reopened: false,
                );
            } elseif (
                $status === 'dismissed'
                && $this->shouldReopenDismissedCandidate(
                    $existing,
                    $signalStrength,
                    $fingerprint,
                )
            ) {
                $evidenceRevision = max(
                    1,
                    (int) ($existing['evidence_revision'] ?? 1),
                ) + 1;

                $existing['status'] = 'pending';
                $existing['confidence'] = $calibration['confidence'];
                $existing['confidence_calibration'] = $calibration;
                $existing['trigger'] = $safeTrigger;
                $existing['evidence'] = $evidence;
                $existing['signal_strength'] = $signalStrength;
                $existing['evidence_fingerprint'] = $fingerprint;
                $existing['evidence_revision'] = $evidenceRevision;
                $existing['resolved_at'] = null;
                $existing['reopened_at'] = now()->toIso8601String();
                $existing['reopen_reason'] = 'stronger_evidence';
                $candidates[$candidateKey] = $existing;

                $this->recordCandidateEvent(
                    $request,
                    $plan,
                    $candidateKey,
                    signalStrength: $signalStrength,
                    evidenceRevision: $evidenceRevision,
                    reopened: true,
                );
            } elseif ($status === 'pending') {
                $previousFingerprint = (string) (
                    $existing['evidence_fingerprint']
                    ?? ''
                );
                $fingerprintChanged =
                    $fingerprint !== ''
                    && (
                        $previousFingerprint === ''
                        || ! hash_equals(
                            $previousFingerprint,
                            $fingerprint,
                        )
                    );

                if (
                    $fingerprintChanged
                    || ! isset($existing['signal_strength'])
                    || ! isset($existing['evidence_revision'])
                ) {
                    $existing['signal_strength'] = $signalStrength;
                    $existing['evidence_fingerprint'] = $fingerprint;
                    $existing['evidence'] = $evidence;
                    $existing['confidence'] = $calibration['confidence'];
                    $existing['confidence_calibration'] = $calibration;
                    $existing['evidence_revision'] =
                        $previousFingerprint === ''
                            ? max(
                                1,
                                (int) (
                                    $existing['evidence_revision']
                                    ?? 1
                                ),
                            )
                            : max(
                                1,
                                (int) (
                                    $existing['evidence_revision']
                                    ?? 1
                                ),
                            ) + 1;
                    $candidates[$candidateKey] = $existing;
                }
            } elseif (
                $status === 'confirmed'
                && (
                    ! isset($existing['signal_strength'])
                    || ! isset($existing['evidence_fingerprint'])
                    || ! isset($existing['evidence_revision'])
                )
            ) {
                $existing['signal_strength'] = $signalStrength;
                $existing['evidence_fingerprint'] = $fingerprint;
                $existing['evidence_revision'] = max(
                    1,
                    (int) ($existing['evidence_revision'] ?? 1),
                );
                $existing['confidence'] = $calibration['confidence'];
                $existing['confidence_calibration'] = $calibration;
                $candidates[$candidateKey] = $existing;
            }
        }

        $inferred[self::CANDIDATES_KEY] = $candidates;

        $this->contexts->saveLivingProfileState(
            $request,
            $inferred,
            $recommendedSurfaces,
            $featureReadiness,
        );

        return $this->summary($request);
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(Request $request): array
    {
        $context = $this->contexts->current($request);
        $candidates = (array) data_get(
            $context,
            'context_sources.inferred.'.self::CANDIDATES_KEY,
            [],
        );

        $pending = [];
        $resolved = [];

        foreach ($candidates as $key => $candidate) {
            if (! is_string($key) || ! is_array($candidate)) {
                continue;
            }

            if (($candidate['status'] ?? null) === 'pending') {
                $pending[$key] = $candidate;
            } else {
                $resolved[$key] = $candidate;
            }
        }

        return [
            'pending' => $pending,
            'resolved' => $resolved,
            'pending_count' => count($pending),
            'context_revision' => (int) (
                $context['context_revision']
                ?? 1
            ),
            'last_evaluated_at' => $context['last_evaluated_at'] ?? null,
        ];
    }

    public function confirm(
        Request $request,
        string $candidateKey,
    ): bool {
        return $this->resolve(
            $request,
            $candidateKey,
            confirmed: true,
        );
    }

    public function dismiss(
        Request $request,
        string $candidateKey,
    ): bool {
        return $this->resolve(
            $request,
            $candidateKey,
            confirmed: false,
        );
    }

    private function resolve(
        Request $request,
        string $candidateKey,
        bool $confirmed,
    ): bool {
        $context = $this->contexts->current($request);
        $inferred = is_array(data_get(
            $context,
            'context_sources.inferred',
        ))
            ? data_get($context, 'context_sources.inferred')
            : [];
        $candidates = is_array($inferred[self::CANDIDATES_KEY] ?? null)
            ? $inferred[self::CANDIDATES_KEY]
            : [];

        $candidate = is_array($candidates[$candidateKey] ?? null)
            ? $candidates[$candidateKey]
            : null;

        if (
            ! $candidate
            || ($candidate['status'] ?? null) !== 'pending'
            || ($candidate['risk'] ?? null) !== 'high'
        ) {
            return false;
        }

        $candidate['status'] = $confirmed
            ? 'confirmed'
            : 'dismissed';
        $candidate['resolved_at'] = now()->toIso8601String();

        if (! $confirmed) {
            $candidate['last_dismissed_at'] =
                $candidate['resolved_at'];
            $candidate['dismissed_signal_strength'] = max(
                1,
                (int) ($candidate['signal_strength'] ?? 1),
            );
            $candidate['dismissed_evidence_fingerprint'] =
                (string) (
                    $candidate['evidence_fingerprint']
                    ?? ''
                );
            $candidate['dismissed_evidence_revision'] = max(
                1,
                (int) ($candidate['evidence_revision'] ?? 1),
            );
        }
        $candidates[$candidateKey] = $candidate;
        $inferred[self::CANDIDATES_KEY] = $candidates;

        $featureReadiness = is_array($context['feature_readiness'] ?? null)
            ? $context['feature_readiness']
            : [];

        if (
            $confirmed
            && $candidateKey === 'development_advanced_support'
        ) {
            data_set(
                $featureReadiness,
                'development.advanced_support',
                [
                    'enabled' => true,
                    'source' => 'context_confirmation',
                    'candidate_key' => $candidateKey,
                    'confirmed_at' => now()->toIso8601String(),
                ],
            );
        }

        $this->contexts->saveLivingProfileState(
            $request,
            $inferred,
            null,
            $featureReadiness,
        );

        $this->events->recordSafely(
            $this->identity->resolve($request),
            $confirmed
                ? BehaviorEventType::ContextUpdateConfirmed
                : BehaviorEventType::ContextUpdateDismissed,
            $request,
            metadata: [
                'candidate_key' => $candidateKey,
                'domain' => (string) (
                    $candidate['domain']
                    ?? 'unknown'
                ),
                'risk' => (string) (
                    $candidate['risk']
                    ?? 'unknown'
                ),
                'signal_strength' => max(
                    1,
                    (int) (
                        $candidate['signal_strength']
                        ?? 1
                    ),
                ),
                'evidence_revision' => max(
                    1,
                    (int) (
                        $candidate['evidence_revision']
                        ?? 1
                    ),
                ),
            ],
        );

        return true;
    }

    /**
     * @param array<int,string> $evidence
     * @param array<string,mixed> $proposal
     * @return array<string,mixed>
     */
    private function candidate(
        string $key,
        string $domain,
        string $kind,
        string $risk,
        string $confidence,
        string $status,
        string $trigger,
        array $evidence,
        array $proposal,
        ?string $resolvedAt = null,
        int $signalStrength = 1,
        string $evidenceFingerprint = '',
        int $evidenceRevision = 1,
    ): array {
        return [
            'key' => $key,
            'domain' => $domain,
            'kind' => $kind,
            'risk' => $risk,
            'confidence' => $confidence,
            'status' => $status,
            'confirmation_required' => $risk === 'high',
            'trigger' => $this->safeTrigger($trigger),
            'evidence' => array_values($evidence),
            'signal_strength' => max(1, $signalStrength),
            'evidence_fingerprint' => $evidenceFingerprint,
            'evidence_revision' => max(1, $evidenceRevision),
            'proposal' => $proposal,
            'created_at' => now()->toIso8601String(),
            'resolved_at' => $resolvedAt,
        ];
    }

    /**
     * A dismissal is respected until evidence becomes materially stronger.
     *
     * Merely changing the fingerprint at the same signal strength is not
     * enough to reopen a high-impact candidate.
     *
     * @param array<string,mixed> $candidate
     */
    private function shouldReopenDismissedCandidate(
        array $candidate,
        int $signalStrength,
        string $fingerprint,
    ): bool {
        $dismissedStrength = max(
            1,
            (int) (
                $candidate['dismissed_signal_strength']
                ?? $candidate['signal_strength']
                ?? 1
            ),
        );
        $dismissedFingerprint = (string) (
            $candidate['dismissed_evidence_fingerprint']
            ?? $candidate['evidence_fingerprint']
            ?? ''
        );

        return $signalStrength > $dismissedStrength
            && $fingerprint !== ''
            && (
                $dismissedFingerprint === ''
                || ! hash_equals(
                    $dismissedFingerprint,
                    $fingerprint,
                )
            );
    }

    private function recordCandidateEvent(
        Request $request,
        ?Plan $plan,
        string $candidateKey,
        int $signalStrength,
        int $evidenceRevision,
        bool $reopened,
    ): void {
        $this->events->recordSafely(
            $this->identity->resolve($request),
            BehaviorEventType::ContextUpdateCandidateCreated,
            $request,
            $plan,
            metadata: [
                'candidate_key' => $candidateKey,
                'domain' => 'development',
                'risk' => 'high',
                'confirmation_required' => true,
                'signal_strength' => $signalStrength,
                'evidence_revision' => $evidenceRevision,
                'reopened' => $reopened,
                'reason' => $reopened
                    ? 'stronger_evidence'
                    : 'initial_evidence',
            ],
        );
    }

    private function safeTrigger(string $trigger): string
    {
        return in_array(
            $trigger,
            [
                'manual',
                'capability_readiness',
                'new_plan',
                'plan_completed',
                'workspace_change',
                'return',
                'return_after_absence',
                'long_usage_window',
            ],
            true,
        )
            ? $trigger
            : 'manual';
    }
}
