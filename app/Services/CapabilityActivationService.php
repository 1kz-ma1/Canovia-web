<?php

namespace App\Services;

use App\Enums\BehaviorEventType;
use App\Models\Plan;
use App\Models\PlanArtifact;
use Illuminate\Http\Request;

final class CapabilityActivationService
{
    public const GITHUB = 'github_integration';

    public function __construct(
        private readonly PersonalizationContextService $contexts,
        private readonly GitHubIntegrationReadinessService $githubReadiness,
        private readonly BehaviorIdentityService $identity,
        private readonly BehaviorEventLogger $events,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function github(
        Request $request,
        ?Plan $plan = null,
        ?PlanArtifact $repository = null,
        ?array $readiness = null,
        ?array $connection = null,
    ): array {
        $context = $this->contexts->current($request);
        $featureReadiness = is_array($context['feature_readiness'] ?? null)
            ? $context['feature_readiness']
            : [];

        $interest = data_get(
            $context,
            'context_sources.self_reported.capability_interest.github_integration',
            data_get($featureReadiness, 'github.interest'),
        );
        $interest = in_array($interest, ['yes', 'no'], true)
            ? $interest
            : null;

        $eligible = (bool) data_get(
            $featureReadiness,
            'github.eligible',
            false,
        );
        $activation = is_array(data_get(
            $featureReadiness,
            'github.activation',
        ))
            ? data_get($featureReadiness, 'github.activation')
            : [];

        $repositories = $plan
            ? $plan->artifacts()
                ->where('provider', 'github')
                ->where('artifact_type', 'repository')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get()
            : collect();

        $repositoryCount = $repositories->count();
        $autoCandidate = $repositoryCount === 1
            ? $repositories->first()
            : null;
        $storedRepositoryId = (int) (
            $activation['repository_artifact_id']
            ?? 0
        );

        if (! $repository instanceof PlanArtifact) {
            $storedRepository = $storedRepositoryId > 0
                ? $repositories->firstWhere('id', $storedRepositoryId)
                : null;

            $repository = $storedRepository instanceof PlanArtifact
                ? $storedRepository
                : (
                    $autoCandidate instanceof PlanArtifact
                        ? $autoCandidate
                        : null
                );
        }

        $readiness ??= $this->githubReadiness->forActor(
            $request->user(),
        );

        if ($repository && $connection === null) {
            $connection = $this->githubReadiness->connectionStatus(
                $readiness,
                is_array(data_get(
                    $repository->metadata,
                    'github_app_connection',
                ))
                    ? data_get(
                        $repository->metadata,
                        'github_app_connection',
                    )
                    : [],
            );
        }

        $connection = is_array($connection) ? $connection : [];
        $connectionState = (string) data_get(
            $connection,
            'state',
            $repository ? 'repository_install' : 'repository_required',
        );

        $storedStage = (string) ($activation['stage'] ?? '');
        $visible =
            $connectionState === 'ready'
            || $storedStage === 'completed'
            || (
                $interest !== 'no'
                && $storedStage !== 'abandoned'
                && (
                    $interest === 'yes'
                    || ($eligible && $interest === null)
                    || $storedStage === 'setup_started'
                )
            );

        if (! $visible) {
            return [
                'key' => self::GITHUB,
                'visible' => false,
            ];
        }

        $stage = $this->stage(
            $interest,
            $storedStage,
            $connectionState,
        );

        $runtimeReady = (bool) data_get(
            $readiness,
            'runtime.interactive_connect_configured',
            false,
        );
        $accessAllowed = (bool) data_get(
            $readiness,
            'evidence.allowed',
            false,
        );

        $nextUrl = $stage === 'preview'
            ? route('personalization.result')
            : (
                $plan
                    ? route('github_workflow.index', ['plan_id' => $plan->id])
                    : route('workspace.development.top')
            );

        $owner = (string) data_get(
            $connection,
            'owner',
            $runtimeReady
                ? 'YOU'
                : 'CANOVIA OPERATOR',
        );
        $detail = trim((string) data_get(
            $connection,
            'detail',
            '',
        ));

        if ($stage === 'preview') {
            $owner = 'YOU';
            $detail = 'まず、GitHub連携で何が変わるかを確認して、使うかどうか選べます。';
        } elseif (! $accessAllowed) {
            $owner = 'PLAN / ENTITLEMENT';
            $detail = (string) data_get(
                $readiness,
                'evidence.message',
                '現在の利用権ではGitHub連携を開始できません。',
            );
        } elseif (! $runtimeReady) {
            $owner = 'CANOVIA OPERATOR';
            $detail = 'GitHub Appの接続環境が整うまで、ユーザー側の設定は不要です。';
        } elseif (! $repository) {
            $owner = 'YOU';
            $detail = $repositoryCount > 1
                ? '複数Repositoryがあります。GitHub画面で今回使う対象を選んでください。'
                : 'まず対象RepositoryをDevelopment Planへ登録してください。';
        }

        return [
            'key' => self::GITHUB,
            'visible' => true,
            'stage' => $stage,
            'eligible' => $eligible,
            'interest' => $interest,
            'owner' => $owner,
            'detail' => $detail,
            'can_start' =>
                $interest === 'yes'
                && $plan instanceof Plan
                && $accessAllowed
                && $runtimeReady
                && $stage !== 'completed',
            'can_abandon' => in_array(
                $stage,
                ['readiness', 'setup_started'],
                true,
            ),
            'next_url' => $nextUrl,
            'repository' => $repository
                ? [
                    'artifact_id' => (int) $repository->id,
                    'title' => (string) $repository->title,
                    'url' => (string) $repository->url,
                    'auto_candidate' => $repositoryCount === 1,
                ]
                : null,
            'repository_count' => $repositoryCount,
            'connection_state' => $connectionState,
            'steps' => [
                [
                    'key' => 'need',
                    'label' => 'Need',
                    'done' => $eligible || $interest === 'yes',
                ],
                [
                    'key' => 'preview',
                    'label' => 'Preview',
                    'done' => $interest !== null,
                ],
                [
                    'key' => 'interest',
                    'label' => 'Interest',
                    'done' => $interest === 'yes',
                ],
                [
                    'key' => 'readiness',
                    'label' => 'Readiness',
                    'done' => $repository instanceof PlanArtifact
                        && $accessAllowed
                        && $runtimeReady,
                ],
                [
                    'key' => 'setup',
                    'label' => 'Setup',
                    'done' => $stage === 'completed',
                ],
            ],
            'activation' => $activation,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function startGithub(
        Request $request,
        Plan $plan,
    ): array {
        $context = $this->contexts->current($request);
        $featureReadiness = is_array($context['feature_readiness'] ?? null)
            ? $context['feature_readiness']
            : [];

        $state = $this->github($request, $plan);

        data_set(
            $context,
            'context_sources.self_reported.capability_interest.github_integration',
            'yes',
        );
        data_set($featureReadiness, 'github.interest', 'yes');
        data_set($featureReadiness, 'github.activation', [
            'stage' => 'setup_started',
            'plan_id' => (int) $plan->id,
            'repository_artifact_id' => data_get(
                $state,
                'repository.artifact_id',
            ),
            'repository_auto_candidate' => (bool) data_get(
                $state,
                'repository.auto_candidate',
                false,
            ),
            'setup_started_at' => now()->toIso8601String(),
            'setup_completed_at' => null,
            'abandoned_at' => null,
            'updated_at' => now()->toIso8601String(),
        ]);
        $context['feature_readiness'] = $featureReadiness;

        $this->contexts->saveSelfReportedPreference(
            $request,
            $context,
        );

        $this->events->recordOnceSafely(
            $this->identity->resolve($request),
            BehaviorEventType::CapabilitySetupStarted,
            $request,
            $plan,
            metadata: [
                'capability' => self::GITHUB,
                'repository_auto_candidate' => (bool) data_get(
                    $state,
                    'repository.auto_candidate',
                    false,
                ),
                'repository_count' => (int) (
                    $state['repository_count']
                    ?? 0
                ),
            ],
            withinMinutes: 30,
        );

        return $this->github($request, $plan);
    }

    public function abandonGithub(
        Request $request,
        Plan $plan,
    ): void {
        $context = $this->contexts->current($request);
        $featureReadiness = is_array($context['feature_readiness'] ?? null)
            ? $context['feature_readiness']
            : [];
        $activation = is_array(data_get(
            $featureReadiness,
            'github.activation',
        ))
            ? data_get($featureReadiness, 'github.activation')
            : [];

        data_set($featureReadiness, 'github.activation', [
            ...$activation,
            'stage' => 'abandoned',
            'plan_id' => (int) $plan->id,
            'abandoned_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->contexts->saveFeatureReadinessState(
            $request,
            $featureReadiness,
        );

        $this->events->recordSafely(
            $this->identity->resolve($request),
            BehaviorEventType::CapabilitySetupAbandoned,
            $request,
            $plan,
            metadata: [
                'capability' => self::GITHUB,
            ],
        );
    }

    public function syncGithubCompletion(
        Request $request,
        Plan $plan,
        ?PlanArtifact $repository,
        array $connection,
    ): bool {
        if (
            ! $repository
            || (string) data_get($connection, 'state') !== 'ready'
        ) {
            return false;
        }

        $context = $this->contexts->current($request);
        $featureReadiness = is_array($context['feature_readiness'] ?? null)
            ? $context['feature_readiness']
            : [];
        $activation = is_array(data_get(
            $featureReadiness,
            'github.activation',
        ))
            ? data_get($featureReadiness, 'github.activation')
            : [];

        if (($activation['stage'] ?? null) === 'completed') {
            return false;
        }

        data_set($featureReadiness, 'github.activation', [
            ...$activation,
            'stage' => 'completed',
            'plan_id' => (int) $plan->id,
            'repository_artifact_id' => (int) $repository->id,
            'setup_completed_at' => now()->toIso8601String(),
            'abandoned_at' => null,
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->contexts->saveFeatureReadinessState(
            $request,
            $featureReadiness,
        );
        $this->contexts->storeObservedCandidate(
            $request,
            [
                'github_integration' => [
                    'status' => 'connected',
                    'plan_id' => (int) $plan->id,
                    'repository_artifact_id' => (int) $repository->id,
                    'observed_at' => now()->toIso8601String(),
                ],
            ],
        );

        $this->events->recordSafely(
            $this->identity->resolve($request),
            BehaviorEventType::CapabilitySetupCompleted,
            $request,
            $plan,
            metadata: [
                'capability' => self::GITHUB,
                'repository_artifact_id' => (int) $repository->id,
            ],
        );

        return true;
    }

    private function stage(
        ?string $interest,
        string $storedStage,
        string $connectionState,
    ): string {
        if ($connectionState === 'ready') {
            return 'completed';
        }

        if ($interest === 'no') {
            return 'dismissed';
        }

        if ($storedStage === 'abandoned') {
            return 'abandoned';
        }

        if ($storedStage === 'setup_started') {
            return 'setup_started';
        }

        if ($storedStage === 'completed') {
            return 'completed';
        }

        if ($interest === 'yes') {
            return 'readiness';
        }

        return 'preview';
    }
}
