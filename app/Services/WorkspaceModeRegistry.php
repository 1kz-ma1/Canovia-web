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
     * Career / Creative / General remain available in Canovia but currently
     * resolve to Overview until a dedicated Workspace Mode is explicitly
     * designed and registered.
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
                emptyStateTitle: '試験範囲から始める',
                emptyStateDescription: '試験範囲を取り込み、学習状態と次のActionを育てます。',
                emptyStateActionKey: 'capture_study_scope',
            ),
            new WorkspaceModeDefinitionData(
                mode: WorkspaceMode::Development,
                label: '開発',
                iconKey: 'development',
                description: 'GitHub EvidenceからRelease Readinessと次のQuality Gateを判断する。',
                accentTone: 'development',
                homeStrategy: 'development',
                supportedProfileKeys: ['development'],
                navigationKeys: [
                    'current_action',
                    'release_readiness',
                    'github',
                    'evidence',
                    'history',
                ],
                emptyStateTitle: 'GitHubから開発状態をつなぐ',
                emptyStateDescription: 'RepositoryやPRを接続し、現実の開発EvidenceからRelease状態を追います。',
                emptyStateActionKey: 'connect_github',
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
