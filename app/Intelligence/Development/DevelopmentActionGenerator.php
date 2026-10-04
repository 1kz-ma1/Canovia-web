<?php

namespace App\Intelligence\Development;

use App\Intelligence\Contracts\ActionGenerator;
use App\Intelligence\Data\ActionProposal;
use App\Intelligence\Data\Decision;
use App\Intelligence\Data\ReadinessAssessment;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use InvalidArgumentException;

final class DevelopmentActionGenerator implements ActionGenerator
{
    /**
     * @return array<ActionProposal>
     */
    public function generate(
        StateSnapshot $state,
        ReadinessAssessment $readiness,
        Decision $decision,
    ): array {
        if ($state->domain !== IntelligenceDomain::Development) {
            throw new InvalidArgumentException(
                'DevelopmentActionGenerator only supports the development domain.',
            );
        }

        $definition = $this->definition($decision->type);
        $targetTaskId = $decision->metadata['target_task_id'] ?? null;
        $targetGate = (string) ($decision->metadata['target_gate'] ?? '');

        return [new ActionProposal(
            kind: $definition['kind'],
            title: $definition['title'],
            intent: $definition['intent'],
            confidence: $decision->confidence,
            estimatedMinutes: null,
            successSignals: $definition['success_signals'],
            metadata: [
                'policy_version' => 'development_release_action_v1',
                'reason_code' => $decision->reasonCode,
                'target_task_id' => $targetTaskId,
                'target_gate' => $targetGate !== '' ? $targetGate : null,
                'gate_status' => $decision->metadata['gate_status'] ?? null,
                'readiness_score' => $readiness->score,
                'route_kind' => $definition['route_kind'],
                'pull_request_number' => $decision->metadata['pull_request_number'] ?? null,
                'deployment_environment' => $decision->metadata['deployment_environment'] ?? null,
                'projection_policy' => 'no_task_creation_in_v53_8',
            ],
        )];
    }

    /**
     * @return array{
     *   kind:string,
     *   title:string,
     *   intent:string,
     *   route_kind:string,
     *   success_signals:array<int,string>
     * }
     */
    private function definition(string $type): array
    {
        return match ($type) {
            'connect_development_evidence' => [
                'kind' => 'development_connect_evidence',
                'title' => 'GitHub項目をTaskへ結ぶ',
                'intent' => 'Release判断に必要なEvidenceがまだありません。Repository / PR / Issue / Branchを対象Taskへ明示リンクして現在状態を観測します。',
                'route_kind' => 'github_workflow',
                'success_signals' => ['Taskに紐づくDevelopment Evidenceが1件以上ある'],
            ],
            'fix_ci' => [
                'kind' => 'development_fix_ci',
                'title' => '失敗しているCIを直す',
                'intent' => '自動テストが失敗しているため、mergeやdeployより先に失敗原因を解消します。',
                'route_kind' => 'github_workflow',
                'success_signals' => ['CI state = success'],
            ],
            'address_review_feedback' => [
                'kind' => 'development_review_fix',
                'title' => 'レビュー指摘を解消する',
                'intent' => 'Reviewでchanges requestedが観測されています。指摘を反映し、再確認できる状態へ戻します。',
                'route_kind' => 'github_workflow',
                'success_signals' => ['Review state = approved'],
            ],
            'fix_deployment' => [
                'kind' => 'development_fix_deploy',
                'title' => '失敗しているDeployを直す',
                'intent' => 'Production経路のDeploy failureが観測されています。Release確認より先にDeployを正常化します。',
                'route_kind' => 'github_workflow',
                'success_signals' => ['Production deployment status = success'],
            ],
            'fix_verification' => [
                'kind' => 'development_fix_verification',
                'title' => '確認で見つかった問題を直す',
                'intent' => '実機・本番確認がfailedとして明示されています。問題を修正してから再確認します。',
                'route_kind' => 'task_execution',
                'success_signals' => ['Verification gate = passed'],
            ],
            'replace_closed_pr' => [
                'kind' => 'development_restore_merge_path',
                'title' => 'Release経路を作り直す',
                'intent' => 'PRがmergeされずclosedになっています。現在の変更を残すか、新しいPRへ切り直すか確認します。',
                'route_kind' => 'github_workflow',
                'success_signals' => ['Merge gate returns to pending or passed'],
            ],
            'establish_implementation' => [
                'kind' => 'development_implement',
                'title' => '実装Evidenceを作る',
                'intent' => 'Branchだけ、または実装Evidenceが未確認です。対象Taskの変更をcommit/PRとして観測できる状態にします。',
                'route_kind' => 'task_execution',
                'success_signals' => ['Commit or Pull Request Evidence exists'],
            ],
            'establish_ci' => [
                'kind' => 'development_run_ci',
                'title' => 'CIを通す',
                'intent' => '実装は観測できていますが、テスト結果がRelease判断に足りません。CIを実行してsuccessを確認します。',
                'route_kind' => 'github_workflow',
                'success_signals' => ['CI state = success'],
            ],
            'obtain_review' => [
                'kind' => 'development_obtain_review',
                'title' => 'Reviewを確認する',
                'intent' => 'CI後の変更がReview済みか確認できていません。Approvalまたは修正依頼をEvidenceとして取得します。',
                'route_kind' => 'github_workflow',
                'success_signals' => ['Review state = approved'],
            ],
            'merge_change' => [
                'kind' => 'development_merge',
                'title' => 'PRをmergeできる状態にする',
                'intent' => '実装・CI・Reviewの先にあるmerge Gateが未完了です。GitHub上でmerge可能な状態を確認します。',
                'route_kind' => 'github_workflow',
                'success_signals' => ['Pull Request merged'],
            ],
            'deploy_release' => [
                'kind' => 'development_deploy',
                'title' => 'ProductionへDeployする',
                'intent' => 'merge後のProduction Deployがまだ確認できません。実際に配信された状態まで進めます。',
                'route_kind' => 'github_workflow',
                'success_signals' => ['Production deployment status = success'],
            ],
            'verify_release' => [
                'kind' => 'development_verify',
                'title' => '実機・本番で動作確認する',
                'intent' => 'Deploy後も実際の利用環境で正しく動くとは限りません。明示的にVerificationを行い結果を確定します。',
                'route_kind' => 'development_gate_confirmation',
                'success_signals' => ['Verification gate = passed'],
            ],
            'sync_spec' => [
                'kind' => 'development_sync_spec',
                'title' => '仕様と実装を同期する',
                'intent' => 'Release候補の実装結果が仕様・引き継ぎへ反映済みか確認します。不要な場合も明示的にnot requiredと判断します。',
                'route_kind' => 'development_gate_confirmation',
                'success_signals' => ['Spec sync gate = passed or explicitly not required'],
            ],
            'release_ready' => [
                'kind' => 'development_release_ready',
                'title' => 'Release Readyを確認する',
                'intent' => '実装・CI・Review・Merge・Production Deploy・Verification・Spec Syncの全Gateが確認済みです。次の変更へ進める状態です。',
                'route_kind' => 'github_workflow',
                'success_signals' => ['All release gates remain passed'],
            ],
            default => [
                'kind' => 'development_continue',
                'title' => '次のRelease Gateを確認する',
                'intent' => '現在Evidenceから最も不足しているRelease Gateを確認します。',
                'route_kind' => 'github_workflow',
                'success_signals' => ['Release Readiness advances'],
            ],
        };
    }
}
