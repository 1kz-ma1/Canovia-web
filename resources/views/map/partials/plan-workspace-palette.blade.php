@php
    $workspace = is_array($planWorkspace ?? null) ? $planWorkspace : [];
    $workspacePlan = $workspace['plan'] ?? null;
    $workspaceProgress = is_array($workspace['progress'] ?? null) ? $workspace['progress'] : [];
    $workspaceRoadmap = is_array($workspace['roadmap'] ?? null) ? $workspace['roadmap'] : ['nodes' => []];
    $workspaceCanEdit = (bool) ($workspace['can_edit'] ?? false);
    $workspaceCanManage = (bool) ($workspace['can_manage'] ?? false);
    $workspaceExecutionUrl = (string) ($workspace['execution_url'] ?? '');
@endphp

@if ($workspacePlan)
<div
    class="canovia-plan-workspace-palette"
    data-map-plan-workspace
    aria-label="{{ $workspacePlan->title }}のPlan Workspace"
>
    <header class="canovia-plan-workspace-heading">
        <div class="min-w-0">
            <p class="canovia-plan-workspace-kicker">PLAN WORKSPACE</p>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <h2>{{ $workspacePlan->title }}</h2>
                <span class="badge badge-slate">{{ $workspacePlan->category ?: '未分類' }}</span>
            </div>
            <p>Planの構造はMap上に増やしすぎず、Classicで使っている進捗カードとRoadmapをそのまま配置しています。</p>
        </div>

        <div class="canovia-plan-workspace-actions">
            <a
                href="{{ route('map.index', ['level' => 'l1', 'intent' => 'plan']) }}"
                class="btn-secondary"
                data-map-semantic-zoom
                data-map-zoom-direction="out"
                data-route-lock-skip
            >← Plan一覧</a>
            @if ($workspaceExecutionUrl !== '')
                <a href="{{ $workspaceExecutionUrl }}" class="btn-primary" data-map-plan-execution>実行Mapへ</a>
            @endif
            @if ($workspaceCanManage)
                <a href="{{ route('plans.edit', $workspacePlan) }}" class="btn-secondary">計画を編集</a>
            @endif
            <a href="{{ route('plans.show', $workspacePlan) }}" class="btn-secondary">Classic Plan</a>
        </div>
    </header>

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
                    <p class="canovia-plan-workspace-kicker">CLASSIC CARD</p>
                    <h3>Roadmap</h3>
                </div>
                <span class="badge badge-slate">{{ count($workspaceRoadmap['nodes'] ?? []) }} Task</span>
            </div>

            <div class="canovia-plan-palette-roadmap-body">
                @include('plans.partials.roadmap', [
                    'roadmap' => $workspaceRoadmap,
                    'roadmapPlan' => $workspacePlan,
                    'roadmapCanEdit' => $workspaceCanEdit,
                    'roadmapCanManage' => $workspaceCanManage,
                    'roadmapMode' => 'plan',
                    'roadmapRecommendedMinutes' => null,
                    'roadmapRecommendationReasons' => [],
                ])
            </div>
        </section>
    </div>
</div>
@endif
