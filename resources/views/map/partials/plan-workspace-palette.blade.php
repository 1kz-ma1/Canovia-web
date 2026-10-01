@php
    $workspace = is_array($planWorkspace ?? null) ? $planWorkspace : [];
    $workspacePlan = $workspace['plan'] ?? null;
    $workspaceProgress = is_array($workspace['progress'] ?? null) ? $workspace['progress'] : [];
    $workspaceRoadmap = is_array($workspace['roadmap'] ?? null) ? $workspace['roadmap'] : ['nodes' => []];
    $workspaceRoadmapSpatial = is_array($workspace['roadmap_spatial'] ?? null) ? $workspace['roadmap_spatial'] : null;
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
            @if ($workspaceExecutionUrl !== '')
                <a href="{{ $workspaceExecutionUrl }}" class="btn-primary" data-map-plan-execution>実行へ</a>
            @endif
        </div>
    </header>

    <div
        class="canovia-map-document-scroll"
        data-map-document-scroll
        tabindex="0"
        role="region"
        aria-label="{{ $workspacePlan->title }}のDashboard資料"
    >
        <div class="canovia-plan-workspace-document" data-map-document-canvas>
            <section class="canovia-plan-document-overview">
                <div>
                    <p class="canovia-plan-workspace-kicker">OVERVIEW</p>
                    <p>進捗・Roadmap・Plan情報を、一枚のDashboardとしてMap上で確認します。</p>
                </div>

                <div class="canovia-plan-document-secondary-actions">
                    @if ($workspaceCanManage)
                        <a href="{{ route('plans.edit', $workspacePlan) }}" class="btn-secondary">計画を編集</a>
                    @endif
                    <a href="{{ route('plans.show', $workspacePlan) }}" class="btn-secondary">Classic Plan</a>
                </div>
            </section>

            <div class="canovia-plan-workspace-grid">
                <div class="canovia-plan-palette-summary">
                    @include('plans.partials.summary-metrics', [
                        'plan' => $workspacePlan,
                        'progress' => $workspaceProgress,
                        'summaryClass' => 'canovia-plan-workspace-metrics',
                    ])
                </div>

                <section class="page-card canovia-plan-palette-roadmap">
                    <div class="canovia-plan-palette-card-heading">
                        <div>
                            <p class="canovia-plan-workspace-kicker">ROADMAP</p>
                            <h3>Roadmap</h3>
                        </div>
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
                </section>
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
@endif
