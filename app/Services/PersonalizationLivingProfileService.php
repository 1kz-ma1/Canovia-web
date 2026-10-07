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

            if ($status === '') {
                $candidates[$candidateKey] = $this->candidate(
                    key: $candidateKey,
                    domain: 'development',
                    kind: 'advanced_support_offer',
                    risk: 'high',
                    confidence: 'medium',
                    status: 'pending',
                    trigger: $trigger,
                    evidence: ['github_integration_connected'],
                    proposal: [
                        'feature_readiness_key' =>
                            'development.advanced_support',
                    ],
                );

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
                    ],
                );
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
            'proposal' => $proposal,
            'created_at' => now()->toIso8601String(),
            'resolved_at' => $resolvedAt,
        ];
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
            ],
            true,
        )
            ? $trigger
            : 'manual';
    }
}
