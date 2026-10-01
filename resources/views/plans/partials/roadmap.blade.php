@php
    $roadmapPlan = $roadmapPlan ?? $plan ?? null;
    $roadmapMode = $roadmapMode ?? 'plan';
    $roadmapPreview = $roadmapMode === 'preview';
    $roadmapPlanId = $roadmapPreview ? 'preview' : ($roadmapPlan?->id ?? 'generic');
    $roadmapAccent = $roadmapPlan?->accentKey() ?? 'sky';
    $roadmapWorld = $roadmapPlan?->roadmapWorld() ?? 'default';
    $roadmapSpatial = $roadmapSpatial ?? null;
    $roadmapSurfaceMode = $roadmapSurfaceMode ?? 'standard';
    $roadmapDashboardOverview = $roadmapSurfaceMode === 'dashboard-overview';
@endphp

<div
    class="plan-identity-shell {{ $roadmapDashboardOverview ? 'is-dashboard-overview' : '' }}"
    data-plan-accent="{{ $roadmapAccent }}"
    data-roadmap-view-root
    data-roadmap-plan-id="{{ $roadmapPlanId }}"
    @if ($roadmapDashboardOverview) data-roadmap-fixed-view="map" @endif
>
    @unless ($roadmapDashboardOverview)
    <div class="pk-v19-roadmap-toolbar">
        <div class="roadmap-view-switch" role="group" aria-label="ロードマップ表示切替">
            <button type="button" class="roadmap-view-button is-active" data-roadmap-view-button="map" aria-pressed="true">
                <span aria-hidden="true">⌑</span> マップ
            </button>
            <button type="button" class="roadmap-view-button" data-roadmap-view-button="list" aria-pressed="false">
                <span aria-hidden="true">☷</span> リスト
            </button>
        </div>
        <button type="button" class="pk-v19-roadmap-overview" data-roadmap-overview>
            <span aria-hidden="true">⌗</span> 全体を表示
        </button>
    </div>
    @endunless

    <div data-roadmap-view-panel="map">
        @if (is_array($roadmapSpatial))
            @include('plans.partials.roadmap-spatial-map', [
                'roadmap' => $roadmap,
                'roadmapSpatial' => $roadmapSpatial,
                'roadmapPlan' => $roadmapPlan,
                'roadmapCanEdit' => $roadmapCanEdit ?? false,
                'roadmapCanManage' => $roadmapCanManage ?? false,
                'roadmapMode' => $roadmapMode,
                'roadmapSurfaceMode' => $roadmapSurfaceMode,
                'roadmapRecommendedMinutes' => $roadmapRecommendedMinutes ?? null,
            ])
        @else
            @include('plans.partials.roadmap-map', [
                'roadmap' => $roadmap,
                'roadmapPlan' => $roadmapPlan,
                'roadmapCanEdit' => $roadmapCanEdit ?? false,
                'roadmapMode' => $roadmapMode,
                'roadmapRecommendedMinutes' => $roadmapRecommendedMinutes ?? null,
                'roadmapRecommendationReasons' => $roadmapRecommendationReasons ?? [],
                'roadmapWorld' => $roadmapWorld,
            ])
        @endif
    </div>

    @unless ($roadmapDashboardOverview)
        <div data-roadmap-view-panel="list" hidden>
            @include('plans.partials.roadmap-list', [
                'roadmap' => $roadmap,
                'roadmapPlan' => $roadmapPlan,
                'roadmapCanEdit' => $roadmapCanEdit ?? false,
                'roadmapMode' => $roadmapMode,
                'roadmapRecommendedMinutes' => $roadmapRecommendedMinutes ?? null,
                'roadmapRecommendationReasons' => $roadmapRecommendationReasons ?? [],
            ])
        </div>
    @endunless
</div>
