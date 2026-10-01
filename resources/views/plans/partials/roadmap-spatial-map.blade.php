@php
    $roadmapCanEdit = $roadmapCanEdit ?? false;
    $roadmapCanManage = $roadmapCanManage ?? false;
    $roadmapPlan = $roadmapPlan ?? null;
    $roadmapRecommendedMinutes = $roadmapRecommendedMinutes ?? null;
    $roadmapSurfaceMode = $roadmapSurfaceMode ?? 'standard';
    $roadmapDashboardOverview = $roadmapSurfaceMode === 'dashboard-overview';
    $roadmapSpatial = is_array($roadmapSpatial ?? null) ? $roadmapSpatial : [];
    $spatialNodes = collect($roadmapSpatial['nodes'] ?? []);
    $spatialEdges = collect($roadmapSpatial['edges'] ?? []);
    $spatialPhases = collect($roadmapSpatial['phases'] ?? []);
    $spatialClusters = collect($roadmapSpatial['clusters'] ?? []);
    $stageWidth = max(760, (int) ($roadmapSpatial['width'] ?? 760));
    $stageHeight = max(520, (int) ($roadmapSpatial['height'] ?? 520));
@endphp

<div
    class="canovia-roadmap-spatial-shell {{ $roadmapDashboardOverview ? 'is-dashboard-overview' : '' }}"
    data-roadmap-spatial-scroll
    data-roadmap-spatial-mode="{{ $roadmapSurfaceMode }}"
    data-roadmap-spatial-auto-center="{{ $roadmapDashboardOverview ? '0' : '1' }}"
>
    <div
        class="canovia-roadmap-spatial-stage"
        data-roadmap-map
        data-roadmap-spatial-map
        style="--roadmap-stage-width: {{ $stageWidth }}px; --roadmap-stage-height: {{ $stageHeight }}px;"
    >
        <div class="canovia-roadmap-spatial-stars" aria-hidden="true"></div>

        @foreach ($spatialPhases as $phase)
            <section
                class="canovia-roadmap-phase"
                data-roadmap-phase="{{ $phase['id'] }}"
                style="--phase-x: {{ (int) $phase['x'] }}px;"
                aria-label="{{ $phase['label'] }} · {{ $phase['task_count'] }}Task"
            >
                <div class="canovia-roadmap-phase-heading">
                    <strong>{{ $phase['label'] }}</strong>
                    <span>{{ $phase['task_count'] }} Task</span>
                </div>
            </section>
        @endforeach

        @foreach ($spatialClusters as $cluster)
            <div
                class="canovia-roadmap-cluster {{ ($cluster['is_parallel'] ?? false) ? 'is-parallel' : '' }}"
                data-roadmap-cluster="{{ $cluster['id'] }}"
                style="
                    --cluster-x: {{ (int) $cluster['x'] }}px;
                    --cluster-y: {{ (int) $cluster['y'] }}px;
                    --cluster-width: {{ (int) $cluster['width'] }}px;
                    --cluster-height: {{ (int) $cluster['height'] }}px;
                "
                aria-hidden="true"
            >
                <span>{{ $cluster['label'] }}</span>
            </div>
        @endforeach

        @if ($spatialEdges->isNotEmpty())
            <svg
                class="canovia-roadmap-spatial-edges"
                viewBox="0 0 {{ $stageWidth }} {{ $stageHeight }}"
                preserveAspectRatio="none"
                aria-hidden="true"
            >
                <defs>
                    <marker id="canovia-roadmap-dependency-arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="5" markerHeight="5" orient="auto-start-reverse">
                        <path d="M 0 0 L 10 5 L 0 10 z"></path>
                    </marker>
                </defs>
                @foreach ($spatialEdges as $edge)
                    <line
                        class="canovia-roadmap-spatial-edge is-{{ $edge['relation'] }}"
                        x1="{{ (int) $edge['x1'] }}"
                        y1="{{ (int) $edge['y1'] }}"
                        x2="{{ (int) $edge['x2'] }}"
                        y2="{{ (int) $edge['y2'] }}"
                        data-roadmap-edge-relation="{{ $edge['relation'] }}"
                        @if (($edge['relation'] ?? null) === 'dependency')
                            marker-end="url(#canovia-roadmap-dependency-arrow)"
                        @endif
                    />
                @endforeach
            </svg>
        @endif

        @forelse ($spatialNodes as $node)
            @php
                $isCurrent = (bool) ($node['is_current'] ?? false);
                $isDone = ($node['status'] ?? null) === 'done';
                $isCancelled = ($node['status'] ?? null) === 'cancelled';
                $dependencyCount = collect($node['depends_on_task_ids'] ?? [])->filter()->unique()->count();
                $dependencyState = (string) ($node['dependency_state'] ?? '');
                $dependencyStateClass = in_array($dependencyState, ['ready', 'blocked'], true)
                    ? 'is-'.$dependencyState
                    : '';
                $stateClass = match (true) {
                    $isCurrent => 'is-current',
                    $isDone => 'is-done',
                    $isCancelled => 'is-cancelled',
                    ($node['status'] ?? null) === 'doing' => 'is-doing',
                    default => 'is-todo',
                };
            @endphp
            <details
                class="canovia-roadmap-spatial-node {{ $stateClass }} {{ $dependencyStateClass }}"
                data-map-stop
                data-roadmap-spatial-node
                data-roadmap-task-id="{{ $node['task_id'] }}"
                data-roadmap-current="{{ $isCurrent ? '1' : '0' }}"
                style="--task-x: {{ (int) $node['x'] }}px; --task-y: {{ (int) $node['y'] }}px;"
                @if ($isCurrent && ! $roadmapDashboardOverview) open @endif
            >
                <summary
                    aria-label="{{ $roadmapDashboardOverview ? $node['title'].' · '.$node['status_label'].' · 進捗 '.$node['progress_percent'].'%' : $node['title'].'の詳細' }}"
                    @if ($roadmapDashboardOverview) tabindex="-1" @endif
                >
                    <span class="canovia-roadmap-task-orbit" aria-hidden="true">
                        <span>{{ $isDone ? '✓' : ($isCurrent ? '●' : '○') }}</span>
                    </span>
                    <span class="canovia-roadmap-task-copy">
                        @if ($isCurrent)<small>今ここ</small>@endif
                        <strong>{{ $node['title'] }}</strong>
                        <span>{{ $node['status_label'] }} · {{ $node['progress_percent'] }}%</span>
                    </span>
                </summary>

                <div class="canovia-roadmap-task-detail">
                    <div class="canovia-roadmap-task-detail-meta">
                        <span>{{ $node['status_label'] }}</span>
                        <span>進捗 {{ $node['progress_percent'] }}%</span>
                        @if ($dependencyCount > 0)<span>前提 {{ $dependencyCount }}件</span>@endif
                        @if ($dependencyState === 'ready' && $dependencyCount > 0)<span class="is-ready">開始可能</span>@endif
                        @if ($dependencyState === 'blocked')<span class="is-blocked">前提待ち {{ count($node['blocker_task_ids'] ?? []) }}件</span>@endif
                        @if ($node['is_last_worked'] ?? false)<span>前回の続き</span>@endif
                    </div>

                    <h3>{{ $node['title'] }}</h3>

                    @if (! empty($node['next_action_note']))
                        <p>{{ $node['next_action_note'] }}</p>
                    @elseif (! empty($node['description']))
                        <p>{{ $node['description'] }}</p>
                    @endif

                    <div class="canovia-roadmap-task-detail-stats">
                        @if ($isCurrent && $roadmapRecommendedMinutes)
                            <span>今回 {{ $roadmapRecommendedMinutes }}分</span>
                        @endif
                        <span>残り {{ $node['remaining_minutes'] }}分</span>
                        <span>優先度 P{{ $node['priority'] }}</span>
                    </div>

                    @if (! empty($node['resources']))
                        <div class="canovia-roadmap-task-resources">
                            @foreach ($node['resources'] as $resource)
                                <a href="{{ $resource['url'] }}" target="_blank" rel="noopener noreferrer">
                                    {{ $resource['title'] }}
                                </a>
                            @endforeach
                        </div>
                    @endif

                    <div class="canovia-roadmap-task-actions">
                        @if ($roadmapPlan && ! empty($node['task_id']))
                            <a
                                href="{{ route('plans.tasks.execution_orchestration.show', [$roadmapPlan, $node['task_id']]) }}"
                                class="btn-secondary"
                            >今やることを見る</a>
                        @endif

                        @if ($roadmapCanEdit && ($node['startable'] ?? false) && $dependencyState !== 'blocked' && ! empty($node['task_id']))
                            <form method="POST" action="{{ route('work_sessions.start') }}" data-work-start-form>
                                @csrf
                                <input type="hidden" name="task_id" value="{{ $node['task_id'] }}">
                                <input type="hidden" name="source" value="roadmap">
                                @if ($isCurrent && $roadmapRecommendedMinutes)
                                    <input type="hidden" name="intended_minutes" value="{{ $roadmapRecommendedMinutes }}">
                                @endif
                                <button type="submit" class="{{ $isCurrent ? 'btn-primary' : 'btn-secondary' }}">
                                    {{ ($node['is_last_worked'] ?? false) ? '続きから開始' : 'このTaskを開始' }}
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </details>
        @empty
            <div class="canovia-roadmap-spatial-empty">
                <strong>まだTaskがありません</strong>
                <p>Taskを追加すると、依存関係と並行作業がここへ現れます。</p>
            </div>
        @endforelse
    </div>
</div>

@if (($roadmapSpatial['parallel_cluster_count'] ?? 0) > 0)
    <p class="canovia-roadmap-spatial-caption">
        同じ枠に並ぶTaskは、登録済みDependency上で同じ前提を共有する並行候補です。
    </p>
@endif
