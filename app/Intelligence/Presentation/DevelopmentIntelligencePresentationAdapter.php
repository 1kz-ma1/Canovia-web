<?php

namespace App\Intelligence\Presentation;

use App\Intelligence\Data\DevelopmentAdaptiveActionResult;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Models\Plan;
use App\Models\Task;

final class DevelopmentIntelligencePresentationAdapter
{
    public function adapt(
        Plan $plan,
        DevelopmentAdaptiveActionResult $result,
    ): ?PlanIntelligencePresentation {
        $action = $result->primaryAction();
        if (! $action) {
            return null;
        }

        $readiness = $result->intelligence->readiness;
        $state = $result->intelligence->state;
        $targetTask = $this->targetTask(
            $plan,
            data_get($action->metadata, 'target_task_id'),
        );
        $routeKind = (string) data_get(
            $action->metadata,
            'route_kind',
            'github_workflow',
        );

        return new PlanIntelligencePresentation(
            domain: IntelligenceDomain::Development,
            plan: $plan,
            state: $state,
            readiness: $readiness,
            decision: $result->decision,
            action: $action,
            eyebrow: 'DEVELOPMENT INTELLIGENCE',
            headline: 'Releaseに向けた現在地',
            sourceNote: 'Task進捗ではなく、同じTaskへ結びついたGitHub Evidenceと明示確認からRelease状態を判断しています。',
            readinessLabel: 'Release Readiness',
            stateLabel: $this->stateLabel($readiness->level->value),
            gapLabel: $this->gapLabel($result->decision->reasonCode),
            gapDetail: $this->gapDetail($result, $targetTask),
            detailUrl: route('github_workflow.index', [
                'plan_id' => $plan->id,
            ]).'#development-intelligence',
            actionUrl: $this->actionUrl($plan, $targetTask, $routeKind),
            actionMethod: 'GET',
            actionLabel: $this->actionLabel($action->kind),
            metrics: $this->metrics($result),
            targetTask: $targetTask,
            requiresTaskProjection: false,
        );
    }

    /**
     * @return array<int,array{label:string,value:string,detail:?string}>
     */
    private function metrics(DevelopmentAdaptiveActionResult $result): array
    {
        $components = $result->intelligence->readiness->components;
        $gates = collect((array) data_get($components, 'gates', []));
        $passed = (int) data_get($components, 'passed_gate_count', 0);
        $failed = $gates->where('status', 'failed')->count();
        $pending = $gates->where('status', 'pending')->count();
        $unknown = $gates->where('status', 'unknown')->count();

        return [
            [
                'label' => 'Gate',
                'value' => $passed.'/7',
                'detail' => '確認済み',
            ],
            [
                'label' => '問題',
                'value' => (string) $failed,
                'detail' => 'Failed',
            ],
            [
                'label' => '進行中',
                'value' => (string) $pending,
                'detail' => 'Pending',
            ],
            [
                'label' => '未確認',
                'value' => (string) $unknown,
                'detail' => 'Unknown',
            ],
        ];
    }

    private function gapDetail(
        DevelopmentAdaptiveActionResult $result,
        ?Task $task,
    ): string {
        $gate = trim((string) data_get(
            $result->decision->metadata,
            'target_gate',
            '',
        ));

        if ($task) {
            return $task->title
                .($gate !== '' ? ' · '.$this->gateLabel($gate) : '');
        }

        return $result->decision->summary;
    }

    private function gapLabel(string $reason): string
    {
        return match ($reason) {
            'development_evidence_missing' => 'Release Evidenceがまだない',
            'implementation_missing' => '実装Evidenceが不足',
            'implementation_in_progress' => '実装がまだ観測途中',
            'implementation_failed' => '実装Gateに問題がある',
            'ci_unverified' => 'CI / Testが未確認',
            'ci_pending' => 'CI / Testが進行中',
            'ci_failed' => 'CI / Testが失敗',
            'review_unverified' => 'Reviewが未確認',
            'review_pending' => 'Reviewが進行中',
            'review_changes_requested' => 'Review修正依頼が残っている',
            'merge_unverified' => 'Merge状態が未確認',
            'merge_pending' => 'Merge待ち',
            'merge_failed' => 'Release経路が閉じている',
            'production_deploy_missing' => 'Production Deployが未確認',
            'production_deploy_pending' => 'Production Deployが進行中',
            'production_deploy_failed' => 'Production Deployが失敗',
            'verification_unconfirmed' => '実機・本番確認が未完了',
            'verification_pending' => '現在Releaseの再確認が必要',
            'verification_failed' => '実機・本番確認で問題あり',
            'spec_sync_unconfirmed' => '仕様同期が未確認',
            'spec_sync_pending' => '現在実装の仕様再確認が必要',
            'spec_sync_failed' => '仕様と実装が未同期',
            'all_release_gates_passed' => '大きな不足なし・Release Ready',
            default => '現在の最大Release Gapを確認中',
        };
    }

    private function gateLabel(string $gate): string
    {
        return match ($gate) {
            'implementation' => '実装',
            'ci' => 'CI / Test',
            'review' => 'Review',
            'merge' => 'Merge',
            'deploy' => 'Production Deploy',
            'verification' => '実機・本番確認',
            'spec_sync' => '仕様同期',
            'release' => 'Release',
            default => $gate,
        };
    }

    private function stateLabel(string $level): string
    {
        return match ($level) {
            'ready' => 'Release Ready',
            'blocked' => 'Release Blocked',
            'developing' => 'Release準備中',
            default => '判定準備中',
        };
    }

    private function actionLabel(string $kind): string
    {
        return match ($kind) {
            'development_connect_evidence' => 'GitHub Evidenceを確認',
            'development_fix_ci' => 'CIを確認',
            'development_review_fix' => 'Reviewを確認',
            'development_fix_deploy' => 'Deployを確認',
            'development_fix_verification' => '確認結果を見直す',
            'development_restore_merge_path' => 'PR経路を確認',
            'development_implement' => '実装へ進む',
            'development_run_ci' => 'CIを確認',
            'development_obtain_review' => 'Reviewを確認',
            'development_merge' => 'Merge状態を確認',
            'development_deploy' => 'Deploy状態を確認',
            'development_verify' => '実機・本番確認へ',
            'development_sync_spec' => '仕様同期を確認',
            'development_release_ready' => 'Release状態を見る',
            default => 'このActionを開く',
        };
    }

    private function actionUrl(
        Plan $plan,
        ?Task $task,
        string $routeKind,
    ): string {
        if ($routeKind === 'task_execution' && $task) {
            return route(
                'plans.tasks.execution_orchestration.show',
                [$plan, $task],
            );
        }

        return route('github_workflow.index', [
            'plan_id' => $plan->id,
        ]).'#development-intelligence';
    }

    private function targetTask(Plan $plan, mixed $value): ?Task
    {
        $taskId = filter_var($value, FILTER_VALIDATE_INT);

        if ($taskId === false || (int) $taskId <= 0) {
            return null;
        }

        return $plan->relationLoaded('tasks')
            ? $plan->tasks->firstWhere('id', (int) $taskId)
            : $plan->tasks()->whereKey((int) $taskId)->first();
    }
}
