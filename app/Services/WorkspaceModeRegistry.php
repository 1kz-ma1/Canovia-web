<?php

namespace App\Services;

use App\Data\WorkspaceModeDefinitionData;
use App\Enums\WorkspaceMode;
use Illuminate\Support\Collection;

final class WorkspaceModeRegistry
{
    /**
     * Public Workspace Modes.
     *
     * Plan category profiles are intentionally broader than this list.
     * Creative / General remain available in Canovia but currently resolve
     * to Overview until a dedicated Workspace Mode is explicitly designed
     * and registered.
     *
     * @return Collection<int,WorkspaceModeDefinitionData>
     */
    public function all(): Collection
    {
        return collect([
            new WorkspaceModeDefinitionData(
                mode: WorkspaceMode::Overview,
                label: 'Overview',
                iconKey: 'overview',
                description: 'Modeをまたいで、全体の現在地と最優先Actionを見る。',
                accentTone: 'overview',
                homeStrategy: 'overview',
                supportedProfileKeys: [],
                navigationKeys: [
                    'current_action',
                    'mode_summary',
                    'inbox',
                    'timeline',
                ],
                emptyStateTitle: 'Canoviaで何を進めますか？',
                emptyStateDescription: '専門Workspaceを選ぶと、その目的に合った入口から始められます。',
                emptyStateActionKey: 'choose_workspace',
                suggestedPlanCategory: null,
                onboardingSteps: [],
            ),
            new WorkspaceModeDefinitionData(
                mode: WorkspaceMode::Study,
                label: '学習',
                iconKey: 'study',
                description: '試験・学習のReadiness、弱点、演習、復習を一つの流れで進める。',
                accentTone: 'study',
                homeStrategy: 'study',
                supportedProfileKeys: ['study'],
                navigationKeys: [
                    'current_action',
                    'readiness',
                    'study_scope',
                    'practice',
                    'recall',
                    'history',
                ],
                emptyStateTitle: '学習Planから現在地を作る',
                emptyStateDescription: '目標を作ると、Canoviaが学習タイプと現在Stateから必要な入口を選びます。',
                emptyStateActionKey: 'create_plan',
                suggestedPlanCategory: '資格学習',
                onboardingSteps: [
                    [
                        'key' => 'create_plan',
                        'title' => '学習Planを作る',
                        'description' => '試験・スコア目標・学校テスト・スキル学習など、達成したい学習目標をPlanにします。',
                        'action_key' => 'create_plan',
                        'action_label' => '学習Planを作る',
                    ],
                ],
            ),
            new WorkspaceModeDefinitionData(
                mode: WorkspaceMode::Development,
                label: '開発',
                iconKey: 'development',
                description: '次のAction、GitHubの現実、進行中Task、Release Readinessを一つの流れで見る。',
                accentTone: 'development',
                homeStrategy: 'development',
                supportedProfileKeys: ['development'],
                navigationKeys: [
                    'current_action',
                    'github_activity',
                    'active_development',
                    'release_readiness',
                    'history',
                ],
                emptyStateTitle: '開発Planから現在地を作る',
                emptyStateDescription: 'Planを作ると、次のActionと現在の開発状態をDeveloper Homeで整理できます。',
                emptyStateActionKey: 'create_plan',
                suggestedPlanCategory: '個人開発',
                onboardingSteps: [
                    [
                        'key' => 'create_plan',
                        'title' => '開発Planを作る',
                        'description' => '作りたいもの・直したいもの・Releaseしたいものを開発Planにします。',
                        'action_key' => 'create_plan',
                        'action_label' => '開発Planを作る',
                    ],
                ],
            ),
            new WorkspaceModeDefinitionData(
                mode: WorkspaceMode::Career,
                label: 'Career',
                iconKey: 'career',
                description: '求人・応募・面接の現実Stateから、次に処理すべきCareer Actionを判断する。',
                accentTone: 'career',
                homeStrategy: 'career',
                supportedProfileKeys: ['career'],
                navigationKeys: [
                    'current_action',
                    'process_readiness',
                    'career_inbox',
                    'pipeline',
                    'interviews',
                    'history',
                ],
                emptyStateTitle: '現実のCareer情報を1つ残す',
                emptyStateDescription: '求人・応募・選考の事実を1件だけ追加し、Career Stateと次のActionを立ち上げます。',
                emptyStateActionKey: 'capture_career_signal',
                suggestedPlanCategory: '就活・キャリア',
                onboardingSteps: [
                    [
                        'key' => 'create_plan',
                        'title' => 'Career Planを作る',
                        'description' => '応募・選考・面接のStateとEvidenceをまとめるCareer Planを一つ作ります。',
                        'action_key' => 'create_plan',
                        'action_label' => 'Career Planを作る',
                    ],
                    [
                        'key' => 'capture_career_signal',
                        'title' => '現実のCareer情報を1つ追加する',
                        'description' => '求人URL・応募先・選考予定など、今ある事実を1件だけ追加するとCareer Intelligenceが動き始めます。',
                        'action_key' => 'capture_career_signal',
                        'action_label' => 'Career情報を追加',
                    ],
                ],
            ),
        ]);
    }

    public function default(): WorkspaceModeDefinitionData
    {
        return $this->definition(WorkspaceMode::Overview);
    }

    public function definition(
        WorkspaceMode $mode,
    ): WorkspaceModeDefinitionData {
        $definition = $this->all()
            ->first(fn (WorkspaceModeDefinitionData $item) =>
                $item->mode === $mode
            );

        if (! $definition instanceof WorkspaceModeDefinitionData) {
            return $this->all()->firstOrFail();
        }

        return $definition;
    }

    public function forProfile(
        string $profileKey,
    ): ?WorkspaceModeDefinitionData {
        $profileKey = mb_strtolower(trim($profileKey));

        if ($profileKey === '') {
            return null;
        }

        return $this->all()
            ->reject(fn (WorkspaceModeDefinitionData $item) =>
                $item->mode === WorkspaceMode::Overview
            )
            ->first(fn (WorkspaceModeDefinitionData $item) =>
                $item->supportsProfile($profileKey)
            );
    }

    public function supportsProfile(string $profileKey): bool
    {
        return $this->forProfile($profileKey)
            instanceof WorkspaceModeDefinitionData;
    }

    /**
     * @return array<int,string>
     */
    public function publicKeys(): array
    {
        return $this->all()
            ->map(fn (WorkspaceModeDefinitionData $item) =>
                $item->mode->value
            )
            ->values()
            ->all();
    }
}
