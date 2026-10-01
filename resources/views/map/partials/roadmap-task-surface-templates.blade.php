@php
    $roadmapTaskNodes = collect(data_get($workspaceRoadmapSpatial ?? [], 'nodes', []));
    $roadmapTaskClusters = collect(data_get($workspaceRoadmapSpatial ?? [], 'clusters', []))->keyBy('id');
@endphp

<div hidden data-roadmap-task-surface-templates>
    @foreach ($roadmapTaskNodes as $roadmapTaskNode)
        @php
            $taskId = (int) ($roadmapTaskNode['task_id'] ?? 0);
            $dependencyState = (string) ($roadmapTaskNode['dependency_state'] ?? '');
            $dependencyCount = collect($roadmapTaskNode['depends_on_task_ids'] ?? [])->filter()->unique()->count();
            $blockerCount = collect($roadmapTaskNode['blocker_task_ids'] ?? [])->filter()->unique()->count();
            $dependencyDepth = max(0, (int) ($roadmapTaskNode['dependency_depth'] ?? 0));
            $phaseLabel = $dependencyDepth === 0 ? '起点' : '段階 '.($dependencyDepth + 1);
            $clusterLabel = (string) data_get(
                $roadmapTaskClusters->get($roadmapTaskNode['cluster_id'] ?? ''),
                'label',
                'Task',
            );
        @endphp
        @if ($taskId > 0)
            <template
                data-map-surface-template="roadmap-task:{{ $taskId }}"
                data-map-presentation-kind="leaf"
                data-roadmap-task-template
            >
                <section class="canovia-map-classic-content" data-map-classic-content="roadmap-task:{{ $taskId }}">
                    <div class="canovia-map-dashboard-summary">
                        <p class="canovia-map-classic-kind">TASK</p>
                        <h2 class="canovia-map-classic-title" data-map-document-title-source>{{ $roadmapTaskNode['title'] }}</h2>
                        <p class="canovia-map-classic-summary">
                            {{ filled($roadmapTaskNode['next_action_note'] ?? null)
                                ? $roadmapTaskNode['next_action_note']
                                : ($roadmapTaskNode['description'] ?? 'このTaskの状態と次のActionを確認します。') }}
                        </p>
                    </div>

                    <div class="canovia-map-dashboard-context">
                        <div class="canovia-map-node-context">
                            <p class="canovia-map-detail-label">ROADMAP CONTEXT</p>
                            <p class="canovia-map-classic-summary">
                                {{ $phaseLabel }} · {{ $clusterLabel }}
                                @if ($dependencyCount > 0)
                                    · 前提 {{ $dependencyCount }}件
                                @endif
                            </p>
                        </div>
                    </div>

                    <section class="canovia-map-dashboard-meta">
                        <h3 class="canovia-map-detail-label">状態・関連情報</h3>
                        <div class="canovia-map-classic-meta">
                            <span>{{ $roadmapTaskNode['status_label'] ?? 'Task' }}</span>
                            <span>進捗 {{ (int) ($roadmapTaskNode['progress_percent'] ?? 0) }}%</span>
                            <span>残り {{ (int) ($roadmapTaskNode['remaining_minutes'] ?? 0) }}分</span>
                            <span>優先度 P{{ (int) ($roadmapTaskNode['priority'] ?? 0) }}</span>
                            @if ($dependencyCount > 0)
                                <span>前提 {{ $dependencyCount }}件</span>
                            @endif
                            @if ($dependencyState === 'ready')
                                <span>開始可能</span>
                            @elseif ($dependencyState === 'blocked')
                                <span>前提待ち {{ $blockerCount }}件</span>
                            @endif
                        </div>
                    </section>

                    <section class="canovia-map-dashboard-actions">
                        <h3 class="canovia-map-detail-label">次にできること</h3>
                        <div class="canovia-map-classic-actions">
                            <a
                                href="{{ route('plans.tasks.execution_orchestration.show', [$workspacePlan, $taskId]) }}"
                                class="btn-primary w-full justify-center"
                                data-map-classic-action
                                data-map-action-role="primary"
                            >今やることを見る</a>
                            @if ($workspaceCanEdit)
                                <a
                                    href="{{ route('tasks.edit', $taskId) }}"
                                    class="btn-secondary w-full justify-center"
                                    data-map-classic-action
                                    data-map-action-role="secondary"
                                >Taskを編集</a>
                            @endif
                            <a
                                href="{{ route('plans.show', $workspacePlan) }}"
                                class="btn-secondary w-full justify-center"
                                data-map-classic-action
                                data-map-action-role="secondary"
                            >Classic Planを開く</a>
                        </div>
                    </section>
                </section>
            </template>
        @endif
    @endforeach
</div>
