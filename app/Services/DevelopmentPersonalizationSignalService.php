<?php

namespace App\Services;

use App\Models\DevelopmentActivityObservation;
use App\Models\PlanArtifact;
use App\Models\User;

final class DevelopmentPersonalizationSignalService
{
    public function __construct(
        private readonly PlanCategoryProfileService $profiles,
    ) {}

    /**
     * @return array{
     *   signal_strength:int,
     *   evidence_fingerprint:string,
     *   evidence:array<int,string>,
     *   connected_repository_count:int,
     *   recent_activity_count:int,
     *   recent_pr_or_commit_count:int,
     *   active_day_count_90d:int,
     *   activity_span_days_90d:int
     * }
     */
    public function advancedSupport(User $user): array
    {
        $repositories = PlanArtifact::query()
            ->with('plan')
            ->where('provider', 'github')
            ->where('artifact_type', 'repository')
            ->whereHas(
                'plan',
                fn ($query) =>
                    $query->where('user_id', $user->id),
            )
            ->get()
            ->filter(function (PlanArtifact $artifact) use ($user) {
                $plan = $artifact->plan;

                return $plan
                    && (int) $plan->user_id === (int) $user->id
                    && $this->profiles->forPlan($plan)->key === 'development'
                    && data_get(
                        $artifact->metadata,
                        'github_app_connection.status',
                    ) === 'connected';
            })
            ->values();

        $developmentPlanIds = $repositories
            ->pluck('plan_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $connectedRepositoryCount = $repositories
            ->map(
                fn (PlanArtifact $artifact) =>
                    mb_strtolower(trim((string) $artifact->url)),
            )
            ->filter()
            ->unique()
            ->count();

        $longWindowObservations = $developmentPlanIds->isEmpty()
            ? collect()
            : DevelopmentActivityObservation::query()
                ->whereIn('plan_id', $developmentPlanIds)
                ->where('last_observed_at', '>=', now()->subDays(90))
                ->get([
                    'kind',
                    'repository_artifact_id',
                    'external_key',
                    'occurred_at',
                    'last_observed_at',
                ]);

        $observations = $longWindowObservations
            ->filter(
                fn (DevelopmentActivityObservation $observation) =>
                    $observation->last_observed_at
                    && $observation->last_observed_at->gte(
                        now()->subDays(30),
                    ),
            )
            ->values();

        $recentActivityCount = $observations
            ->pluck('external_key')
            ->filter()
            ->unique()
            ->count();

        $recentPrOrCommitCount = $observations
            ->whereIn('kind', ['pull_request', 'commit'])
            ->pluck('external_key')
            ->filter()
            ->unique()
            ->count();

        $activityDates90d = $longWindowObservations
            ->map(
                fn (DevelopmentActivityObservation $observation) =>
                    optional(
                        $observation->occurred_at
                        ?? $observation->last_observed_at,
                    )->toDateString(),
            )
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $activeDayCount90d = $activityDates90d->count();
        $activitySpanDays90d = $activeDayCount90d >= 2
            ? CarbonCarbonImmutable::parse(
                (string) $activityDates90d->first(),
            )->diffInDays(
                CarbonCarbonImmutable::parse(
                    (string) $activityDates90d->last(),
                ),
            ) + 1
            : $activeDayCount90d;

        $sustainedActivityObserved =
            $activeDayCount90d >= 6
            && $activitySpanDays90d >= 28;

        $signalStrength = 1;

        if (
            $connectedRepositoryCount >= 2
            || $sustainedActivityObserved
            || (
                $recentActivityCount >= 3
                && $recentPrOrCommitCount >= 1
            )
        ) {
            $signalStrength = 2;
        }

        if (
            (
                $connectedRepositoryCount >= 2
                && $recentActivityCount >= 5
                && $recentPrOrCommitCount >= 2
            )
            || (
                $sustainedActivityObserved
                && $recentPrOrCommitCount >= 2
            )
            || $recentPrOrCommitCount >= 3
        ) {
            $signalStrength = 3;
        }

        $evidence = ['github_integration_connected'];

        if ($connectedRepositoryCount >= 2) {
            $evidence[] = 'multiple_connected_repositories';
        }

        if ($recentActivityCount >= 3) {
            $evidence[] = 'recent_development_activity_3_plus';
        }

        if ($recentPrOrCommitCount >= 2) {
            $evidence[] = 'recent_pr_or_commit_activity_2_plus';
        }

        if ($sustainedActivityObserved) {
            $evidence[] = 'sustained_development_activity_28d_6_days';
        }

        $fingerprintPayload = [
            'schema' => 1,
            'connected_repository_bucket' => min(
                3,
                $connectedRepositoryCount,
            ),
            'recent_activity_bucket' => min(
                10,
                $recentActivityCount,
            ),
            'recent_pr_or_commit_bucket' => min(
                5,
                $recentPrOrCommitCount,
            ),
            'active_day_bucket_90d' => min(12, $activeDayCount90d),
            'activity_span_bucket_90d' => match (true) {
                $activitySpanDays90d >= 56 => '56_plus',
                $activitySpanDays90d >= 28 => '28_55',
                $activitySpanDays90d >= 14 => '14_27',
                default => 'under_14',
            },
        ];

        return [
            'signal_strength' => $signalStrength,
            'evidence_fingerprint' => hash(
                'sha256',
                (string) json_encode(
                    $fingerprintPayload,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES,
                ),
            ),
            'evidence' => $evidence,
            'connected_repository_count' =>
                $connectedRepositoryCount,
            'recent_activity_count' => $recentActivityCount,
            'recent_pr_or_commit_count' =>
                $recentPrOrCommitCount,
            'active_day_count_90d' => $activeDayCount90d,
            'activity_span_days_90d' => $activitySpanDays90d,
        ];
    }
}
