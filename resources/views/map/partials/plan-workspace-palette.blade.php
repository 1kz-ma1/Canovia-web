@php
    $workspace = is_array($planWorkspace ?? null) ? $planWorkspace : [];
    $workspacePlan = $workspace['plan'] ?? null;
    $workspaceProgress = is_array($workspace['progress'] ?? null) ? $workspace['progress'] : [];
    $workspaceRoadmap = is_array($workspace['roadmap'] ?? null) ? $workspace['roadmap'] : ['nodes' => []];
    $workspaceRoadmapSpatial = is_array($workspace['roadmap_spatial'] ?? null) ? $workspace['roadmap_spatial'] : null;
    $workspaceBoard = is_array($workspace['dashboard_board'] ?? null) ? $workspace['dashboard_board'] : [];
    $workspaceNext = is_array($workspaceBoard['next'] ?? null) ? $workspaceBoard['next'] : null;
    $workspaceSignals = is_array($workspaceBoard['signals'] ?? null) ? $workspaceBoard['signals'] : [];
    $workspaceAttention = is_array($workspaceSignals['attention'] ?? null) ? $workspaceSignals['attention'] : [];
    $workspaceReady = collect($workspaceBoard['ready'] ?? []);
    $workspaceBlocked = collect($workspaceBoard['blocked'] ?? []);
    $workspaceActivity = collect($workspaceBoard['activity'] ?? []);
    $workspaceCanEdit = (bool) ($workspace['can_edit'] ?? false);
    $workspaceCanManage = (bool) ($workspace['can_manage'] ?? false);
    $workspaceExecutionUrl = (string) ($workspace['execution_url'] ?? '');
    $workspaceParentUrl = route('map.index', ['level' => 'l1', 'intent' => 'plan']);
@endphp

@if ($workspacePlan)
<div
    class="canovia-plan-workspace-palette"
    data-map-plan-workspace
    data-map-document-viewport
    data-map-document-kind="plan"
    data-dashboard-document
    data-dashboard-document-owner="map"
    data-dashboard-document-kind="plan"
    role="region"
    aria-labelledby="canovia-plan-dashboard-title"
>
    <header class="canovia-plan-workspace-heading canovia-map-document-chrome" data-map-document-chrome>
        <a
            href="{{ $workspaceParentUrl }}"
            class="canovia-map-document-back"
            data-map-semantic-zoom
            data-map-zoom-direction="out"
            data-route-lock-skip
            data-map-document-back
            aria-label="Plan一覧へ戻る"
        ><span aria-hidden="true">←</span><span>Plan一覧</span></a>

        <div class="canovia-map-document-chrome-title">
            <p class="canovia-plan-workspace-kicker">PLAN DASHBOARD</p>
            <div class="canovia-map-document-title-row">
                <h2 id="canovia-plan-dashboard-title">{{ $workspacePlan->title }}</h2>
                <span class="badge badge-slate">{{ $workspacePlan->category ?: '未分類' }}</span>
            </div>
        </div>

        <div class="canovia-plan-workspace-actions">
            @include('map.partials.document-camera-controls')
            @if ($workspaceExecutionUrl !== '')
                <a href="{{ $workspaceExecutionUrl }}" class="btn-primary" data-map-plan-execution>実行へ</a>
            @endif
        </div>
    </header>

    <div
        class="canovia-map-document-scroll"
        data-map-document-scroll
        data-dashboard-document-scroll
        tabindex="0"
        role="region"
        aria-label="{{ $workspacePlan->title }}のDashboard資料"
    >
        <div class="canovia-map-document-stage" data-map-document-stage data-dashboard-document-stage>
            <div class="canovia-plan-workspace-document" data-map-document-canvas data-dashboard-document-canvas>
                <div
                    class="canovia-dashboard-document-grid canovia-plan-dashboard-board"
                    data-dashboard-document-grid
                    data-plan-dashboard-board
                >
                    <x-dashboard-document-region
                        kicker="PLAN"
                        title="概要"
                        :span="4"
                        emphasis="primary"
                        class="canovia-plan-board-overview"
                        data-plan-board-region="overview"
                    >
                        <p class="canovia-plan-board-description">
                            {{ data_get($workspaceBoard, 'overview.description') ?: 'このPlanの概要と現在地を確認します。' }}
                        </p>

                        <div class="canovia-plan-board-inline-meta">
                            <span>{{ data_get($workspaceBoard, 'overview.task_count', 0) }} Task</span>
                            <span>{{ data_get($workspaceBoard, 'overview.completed_count', 0) }} 完了</span>
                            <span>{{ data_get($workspaceBoard, 'overview.active_count', 0) }} Active</span>
                        </div>

                        <div class="canovia-plan-document-secondary-actions">
                            @if ($workspaceCanManage)
                                <a href="{{ route('plans.edit', $workspacePlan) }}" class="btn-secondary">計画を編集</a>
                            @endif
                            <a href="{{ route('plans.show', $workspacePlan) }}" class="btn-secondary">Classic Plan</a>
                        </div>
                    </x-dashboard-document-region>

                    <x-dashboard-document-region
                        kicker="PROGRESS"
                        title="進捗・必要時間"
                        :span="8"
                        class="canovia-plan-board-progress"
                        data-plan-board-region="progress"
                    >
                        @include('plans.partials.summary-metrics', [
                            'plan' => $workspacePlan,
                            'progress' => $workspaceProgress,
                            'summaryClass' => 'canovia-plan-board-metrics',
                        ])
                    </x-dashboard-document-region>

                    <x-dashboard-document-region
                        kicker="NEXT"
                        title="次にやること"
                        :span="4"
                        emphasis="primary"
                        class="canovia-plan-board-next"
                        data-plan-board-region="next"
                    >
                        @if ($workspaceNext)
                            <button
                                type="button"
                                class="canovia-plan-board-task-card is-primary"
                                data-roadmap-task-detail-open
                                data-roadmap-task-template="{{ $workspaceNext['template_id'] }}"
                                aria-label="{{ $workspaceNext['title'] }}の詳細を開く"
                            >
                                <span class="canovia-plan-board-task-state">{{ $workspaceNext['status_label'] }}</span>
                                <strong>{{ $workspaceNext['title'] }}</strong>
                                <small>
                                    進捗 {{ $workspaceNext['progress_percent'] }}%
                                    · 残り {{ $workspaceNext['remaining_minutes'] }}分
                                </small>
                                @if ($workspaceNext['next_action_note'])
                                    <span>{{ $workspaceNext['next_action_note'] }}</span>
                                @endif
                            </button>
                        @else
                            <p class="canovia-plan-board-empty">現在選択できるTaskはありません。</p>
                        @endif

                        @if ($workspaceReady->isNotEmpty())
                            <div class="canovia-plan-board-ready-list">
                                <p>ほかに開始可能 {{ (int) ($workspaceSignals['ready_count'] ?? 0) }}件</p>
                                @foreach ($workspaceReady as $readyTask)
                                    <button
                                        type="button"
                                        data-roadmap-task-detail-open
                                        data-roadmap-task-template="{{ $readyTask['template_id'] }}"
                                    >{{ $readyTask['title'] }}</button>
                                @endforeach
                            </div>
                        @endif
                    </x-dashboard-document-region>

                    <x-dashboard-document-region
                        kicker="ATTENTION"
                        title="判断材料"
                        :span="4"
                        :emphasis="($workspaceAttention['level'] ?? 'normal') === 'alert' ? 'alert' : (($workspaceAttention['level'] ?? 'normal') === 'quiet' ? 'quiet' : 'primary')"
                        class="canovia-plan-board-attention"
                        data-plan-board-region="attention"
                    >
                        <div class="canovia-plan-board-attention-copy">
                            <span>{{ $workspaceAttention['label'] ?? '確認' }}</span>
                            <p>{{ $workspaceAttention['message'] ?? 'Plan全体を確認してください。' }}</p>
                        </div>

                        <div class="canovia-plan-board-signal-strip">
                            <span>開始可能 <strong>{{ (int) ($workspaceSignals['ready_count'] ?? 0) }}</strong></span>
                            <span>前提待ち <strong>{{ (int) ($workspaceSignals['blocked_count'] ?? 0) }}</strong></span>
                            <span>並行群 <strong>{{ (int) ($workspaceSignals['parallel_cluster_count'] ?? 0) }}</strong></span>
                        </div>

                        @if ($workspaceBlocked->isNotEmpty())
                            <div class="canovia-plan-board-blocked-list">
                                @foreach ($workspaceBlocked as $blockedTask)
                                    <button
                                        type="button"
                                        data-roadmap-task-detail-open
                                        data-roadmap-task-template="{{ $blockedTask['template_id'] }}"
                                    >
                                        <span>前提待ち</span>
                                        <strong>{{ $blockedTask['title'] }}</strong>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </x-dashboard-document-region>

                    <x-dashboard-document-region
                        kicker="ACTIVITY"
                        title="最近の実績"
                        :span="4"
                        class="canovia-plan-board-activity"
                        data-plan-board-region="activity"
                    >
                        @if ($workspaceActivity->isNotEmpty())
                            <div class="canovia-plan-board-activity-list">
                                @foreach ($workspaceActivity as $activity)
                                    <article>
                                        <div>
                                            <strong>{{ $activity['task_title'] }}</strong>
                                            <small>{{ $activity['worked_on'] ?? '日付なし' }}</small>
                                        </div>
                                        <p>
                                            {{ $activity['actual_minutes'] }}分
                                            @if ((int) $activity['progress_delta_percent'] !== 0)
                                                · 進捗 {{ (int) $activity['progress_delta_percent'] > 0 ? '+' : '' }}{{ $activity['progress_delta_percent'] }}%
                                            @endif
                                        </p>
                                    </article>
                                @endforeach
                            </div>
                        @else
                            <p class="canovia-plan-board-empty">まだ作業実績はありません。</p>
                        @endif
                    </x-dashboard-document-region>

                    <x-dashboard-document-region
                        kicker="STRUCTURE"
                        title="Spatial Roadmap"
                        :span="12"
                        :rows="2"
                        class="canovia-plan-board-roadmap"
                        data-plan-board-region="roadmap"
                    >
                        <div class="canovia-plan-palette-card-heading">
                            <p>Phase・並行作業・依存関係を俯瞰します。</p>
                            <span class="badge badge-slate">{{ count($workspaceRoadmap['nodes'] ?? []) }} Task</span>
                        </div>

                        <div class="canovia-plan-palette-roadmap-body">
                            @include('plans.partials.roadmap', [
                                'roadmap' => $workspaceRoadmap,
                                'roadmapSpatial' => $workspaceRoadmapSpatial,
                                'roadmapPlan' => $workspacePlan,
                                'roadmapCanEdit' => $workspaceCanEdit,
                                'roadmapCanManage' => $workspaceCanManage,
                                'roadmapMode' => 'plan',
                                'roadmapSurfaceMode' => 'dashboard-overview',
                                'roadmapRecommendedMinutes' => null,
                                'roadmapRecommendationReasons' => [],
                            ])
                        </div>
                    </x-dashboard-document-region>
                </div>
            </div>
            </div>
        </div>
    </div>

    <div class="canovia-map-document-position" data-map-document-position aria-hidden="true">
        <span class="canovia-map-document-position-label">資料位置</span>
        <span class="canovia-map-document-position-track">
            <span class="canovia-map-document-position-thumb" data-map-document-position-thumb></span>
        </span>
    </div>
</div>

@include('map.partials.roadmap-task-surface-templates', [
    'workspaceRoadmapSpatial' => $workspaceRoadmapSpatial,
    'workspacePlan' => $workspacePlan,
    'workspaceCanEdit' => $workspaceCanEdit,
])
@endif
