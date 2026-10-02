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
                @include('plans.partials.dashboard-information-board')
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
