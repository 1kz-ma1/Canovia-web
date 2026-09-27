<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;

class PlanToolService
{
    public function __construct(
        private readonly FeatureAccessService $featureAccess,
        private readonly StudyActivityPolicyService $studyActivities,
        private readonly GuidedExecutionPolicyService $guidedExecutions,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forTask(Plan $plan, Task $task, bool $canEdit = true, ?User $actor = null): array
    {
        if (! $canEdit || in_array($task->status, ['done', 'cancelled'], true) || (int) $task->progress_percent >= 100) {
            return [];
        }

        $tools = [
            [
                'id' => 'timer',
                'name' => '集中タイマー',
                'description' => '時間を区切って集中したいときだけ使います。時間は進捗の証拠ではなく目安として扱います。',
                'icon' => '◷',
                'recommended' => false,
                'badge' => '任意',
            ],
        ];

        if ($this->isCareerPlan($plan)) {
            $tools[] = [
                'id' => 'career_workspace',
                'name' => 'Career',
                'description' => '応募先・選考予定・面接振り返りを、入力を増やしすぎず一か所で扱います。',
                'icon' => '◆',
                'recommended' => true,
                'badge' => '就活',
            ];
        }

        if ($this->isStudyPlan($plan)) {
            $studyActivity = $this->studyActivities->forPlanTask($plan, $task);
            $primaryActivity = (array) ($studyActivity['primary'] ?? []);
            $primaryActivityKey = (string) ($primaryActivity['key'] ?? StudyActivityPolicyService::QUESTION_PRACTICE);

            if ($primaryActivityKey !== StudyActivityPolicyService::QUESTION_PRACTICE) {
                $tools[] = [
                    'id' => 'study_activity',
                    'name' => (string) ($primaryActivity['short_label'] ?? '学習方法'),
                    'description' => (string) ($primaryActivity['reason'] ?? $primaryActivity['description'] ?? 'このTaskに合う学習方法で進めます。'),
                    'icon' => (string) ($primaryActivity['icon'] ?? '◉'),
                    'recommended' => true,
                    'badge' => '適合度 '.(int) ($primaryActivity['fit_score'] ?? 0),
                    'activity' => $primaryActivity,
                ];
            }

            if ($this->featureAccess->canUse($actor, FeatureKey::AiPractice, [
                'plan_id' => (int) $plan->id,
                'task_id' => (int) $task->id,
            ])) {
                $questionPractice = collect($studyActivity['all'] ?? [])
                    ->firstWhere('key', StudyActivityPolicyService::QUESTION_PRACTICE);

                $tools[] = [
                    'id' => 'ai_practice',
                    'name' => 'AI演習',
                    'description' => $primaryActivityKey === StudyActivityPolicyService::QUESTION_PRACTICE
                        ? 'Taskの内容から問題を作り、Canovia上で解いて理解度を確認します。'
                        : 'このTaskでは別の学習方法を優先しますが、理解確認用の問題演習として利用できます。',
                    'icon' => '✦',
                    'recommended' => $primaryActivityKey === StudyActivityPolicyService::QUESTION_PRACTICE,
                    'badge' => $primaryActivityKey === StudyActivityPolicyService::QUESTION_PRACTICE
                        ? 'おすすめ'
                        : '適合度 '.(int) data_get($questionPractice, 'fit_score', 0),
                    'activity' => $questionPractice,
                ];
            }
        }

        if ($plan->relationLoaded('resources') || $task->relationLoaded('resources')) {
            $taskResourceCount = $task->relationLoaded('resources') ? $task->resources->count() : 0;
            $planResourceCount = $plan->relationLoaded('resources') ? $plan->resources->count() : 0;
            $resourceCount = max($taskResourceCount, $planResourceCount);
            $resourceDescription = match (true) {
                $taskResourceCount > 0 && $planResourceCount > 0
                    => "このTaskに関連 {$taskResourceCount} 件 / Plan全体 {$planResourceCount} 件の資料があります。",
                $taskResourceCount > 0
                    => "このTaskに関連する資料 {$taskResourceCount} 件を開きます。",
                $planResourceCount > 0
                    => "Plan全体の資料 {$planResourceCount} 件から必要な情報を開きます。",
                default => '参考資料やURLをこのPlanへまとめます。',
            };

            $tools[] = [
                'id' => 'resources',
                'name' => '関連資料',
                'description' => $resourceDescription,
                'icon' => '⌘',
                'recommended' => $taskResourceCount > 0 || ($resourceCount > 0 && $this->resourceFriendly($task)),
                'badge' => $taskResourceCount > 0 ? "Task {$taskResourceCount}件" : ($resourceCount > 0 ? "{$resourceCount}件" : '資料'),
            ];
        }

        if (
            $this->isProjectPlan($plan)
            && $this->featureAccess->canUse($actor, FeatureKey::ProjectArtifact, [
                'plan_id' => (int) $plan->id,
                'task_id' => (int) $task->id,
            ])
        ) {
            $artifactCount = $task->relationLoaded('artifacts') ? $task->artifacts->count() : 0;
            $tools[] = [
                'id' => 'artifacts',
                'name' => '制作ファイル',
                'description' => $artifactCount > 0
                    ? "このTaskに紐づく制作ファイル {$artifactCount} 件を確認します。"
                    : 'GitHub・Driveなどの制作物をこのTaskと結びつけます。',
                'icon' => '◇',
                'recommended' => $artifactCount > 0 || $this->projectWorkFriendly($task),
                'badge' => $artifactCount > 0 ? "{$artifactCount}件" : '制作',
            ];
        }

        $hasSpecializedRecommended = collect($tools)->contains(
            fn (array $tool) => ($tool['id'] ?? null) !== 'timer' && (bool) ($tool['recommended'] ?? false)
        );

        if (! $hasSpecializedRecommended) {
            $guided = $this->guidedExecutions->assess($plan, $task);
            $tools[] = [
                'id' => 'guided_execution',
                'name' => '実行前後を一緒に整理',
                'description' => $guided['reason'],
                'icon' => '◎',
                'recommended' => (bool) $guided['recommended'],
                'badge' => $guided['recommended'] ? 'おすすめ' : '振り返り',
                'fit_score' => (int) $guided['score'],
            ];
        }

        return collect($tools)
            ->sortByDesc(fn (array $tool) => $tool['recommended'] ? 1 : 0)
            ->values()
            ->all();
    }

    public function looksLikeStudyPlan(Plan $plan): bool
    {
        if ($this->isStudyPlan($plan)) {
            return true;
        }

        $text = mb_strtolower(trim($plan->title.' '.($plan->description ?? '')));

        return preg_match(
            '/応用情報|基本情報|情報処理|itパスポート|資格|試験|検定|toeic|簿記|学習|勉強|ap対策|ap試験|ap学習/u',
            $text
        ) === 1;
    }

    private function isStudyPlan(Plan $plan): bool
    {
        return trim((string) $plan->category) === '資格学習';
    }

    private function isCareerPlan(Plan $plan): bool
    {
        $category = mb_strtolower(trim((string) $plan->category));

        return preg_match('/就活|就職|転職|キャリア|career|job/u', $category) === 1;
    }

    private function isProjectPlan(Plan $plan): bool
    {
        return in_array(trim((string) $plan->category), ['個人開発', 'ゲーム開発', '制作活動'], true);
    }

    private function practiceFriendly(Task $task): bool
    {
        $text = mb_strtolower(trim($task->title.' '.($task->description ?? '')));

        return preg_match('/演習|問題|過去問|復習|理解|確認|計算|暗記|対策|学習|sql|ネットワーク|データベース/u', $text) === 1;
    }

    private function resourceFriendly(Task $task): bool
    {
        $text = mb_strtolower(trim($task->title.' '.($task->description ?? '')));

        return preg_match('/資料|読む|読解|調査|確認|参照|リサーチ|research|document|ドキュメント/u', $text) === 1;
    }

    private function projectWorkFriendly(Task $task): bool
    {
        $text = mb_strtolower(trim($task->title.' '.($task->description ?? '')));

        return preg_match('/実装|修正|設計|開発|制作|コード|デプロイ|テスト|ui|api/u', $text) === 1;
    }
}
