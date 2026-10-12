<?php

namespace App\Services;

use App\Models\User;
use Throwable;

/**
 * G3 developer-derived read fence. Keep historical DB entries, but do not
 * disclose or reuse conclusions after their GitHub source lineage changes.
 */
final class DevelopmentDerivedContextFreshnessGuard
{
    private const CANDIDATE_KEYS = [
        'development_advanced_support',
        'development_guidance_level',
        'development_plan_direction_review',
    ];

    public function __construct(
        private readonly DevelopmentPersonalizationSignalService $signals,
    ) {}

    /** @param array<string,mixed> $context
     *  @return array<string,mixed>
     */
    public function filter(array $context, User $user): array
    {
        $observed = (array) data_get($context, 'context_sources.observed', []);
        $inferred = (array) data_get($context, 'context_sources.inferred', []);
        $candidates = (array) ($inferred['update_candidates'] ?? []);

        $hasDerived = isset($observed['development_behavior'])
            || data_get($observed, 'github_integration.status') === 'connected'
            || collect(self::CANDIDATE_KEYS)->contains(
                fn (string $key) => isset($candidates[$key]),
            )
            || in_array('development.advanced_support', (array) (
                $inferred['feature_recommendation_priority'] ?? []
            ), true)
            || in_array('development.plan_review', (array) (
                $inferred['feature_recommendation_priority'] ?? []
            ), true);

        if (! $hasDerived) {
            return $context;
        }

        try {
            $live = $this->signals->advancedSupport($user);
            $recorded = data_get($observed, 'development_behavior.source_signature');
            $signatureValid = is_string($recorded)
                && $recorded !== ''
                && (int) ($live['connected_repository_count'] ?? 0) > 0
                && hash_equals($recorded, (string) ($live['source_signature'] ?? ''));
            $connectionLive = (int) ($live['connected_repository_count'] ?? 0) > 0;
        } catch (Throwable) {
            $signatureValid = false;
            $connectionLive = false;
        }

        if ($signatureValid) {
            return $context;
        }

        // An unsigned legacy derivation or an unavailable source is not
        // proof of a current capability. No persisted user response changes.
        unset($observed['development_behavior']);
        if (! $connectionLive) {
            unset($observed['github_integration']);
        }

        foreach (self::CANDIDATE_KEYS as $key) {
            unset($candidates[$key]);
        }
        $inferred['update_candidates'] = $candidates;
        $inferred['feature_recommendation_priority'] = array_values(array_filter(
            (array) ($inferred['feature_recommendation_priority'] ?? []),
            fn ($key) => ! in_array($key, [
                'development.advanced_support', 'development.plan_review',
            ], true),
        ));
        $context['context_sources']['observed'] = $observed;
        $context['context_sources']['inferred'] = $inferred;

        $readiness = (array) ($context['feature_readiness'] ?? []);
        // Only mask readiness derived from the stale Living Profile; never
        // revoke an independently granted paid capability or user preference.
        if (data_get($readiness, 'development.advanced_support.source')
            === 'context_confirmation') {
            $dev = (array) ($readiness['development'] ?? []);
            unset($dev['advanced_support']);
            if ($dev === []) {
                unset($readiness['development']);
            } else {
                $readiness['development'] = $dev;
            }
        }
        $context['feature_readiness'] = $readiness;

        return $context;
    }
}
