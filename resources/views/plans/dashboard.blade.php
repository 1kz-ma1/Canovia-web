@extends('layouts.app')

@php
    $workspacePlan = $workspace['plan'];
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
@endphp

@section('title', $workspacePlan->title.' | Plan Dashboard | Canovia')

@section('content')
<section
    class="canovia-plan-dashboard-page"
    data-plan-dashboard-standalone
    data-plan-id="{{ $workspacePlan->id }}"
>
    <x-dashboard-document
        kind="plan"
        :label="$workspacePlan->title.'のPlan Dashboard'"
        width="70rem"
        class="canovia-plan-dashboard-document"
    >
        <x-slot:chrome>
            <div class="canovia-plan-dashboard-chrome">
                <div class="canovia-plan-dashboard-leading">
                    <a href="{{ route('my_plans.index') }}" class="canovia-plan-dashboard-back" aria-label="計画一覧へ戻る">
                        <span aria-hidden="true">←</span><span>計画一覧</span>
                    </a>

                    <div class="canovia-plan-dashboard-title">
                        <p>PLAN DASHBOARD</p>
                        <div>
                            <h1>{{ $workspacePlan->title }}</h1>
                            <span class="badge badge-slate">{{ $workspacePlan->category ?: '未分類' }}</span>
                        </div>
                    </div>
                </div>

                <div class="canovia-plan-dashboard-actions">
                    <a href="{{ route('plans.show', $workspacePlan) }}" class="btn-secondary">Classic Plan</a>
                    @if ($workspaceCanManage)
                        <a href="{{ route('plans.edit', $workspacePlan) }}" class="btn-secondary">編集</a>
                    @endif
                    @if ($workspaceExecutionUrl !== '')
                        <a href="{{ $workspaceExecutionUrl }}" class="btn-primary">実行へ</a>
                    @endif
                </div>
            </div>
        </x-slot:chrome>

        @include('plans.partials.dashboard-information-board')
    </x-dashboard-document>

    @include('map.partials.roadmap-task-surface-templates', [
        'workspaceRoadmapSpatial' => $workspaceRoadmapSpatial,
        'workspacePlan' => $workspacePlan,
        'workspaceCanEdit' => $workspaceCanEdit,
    ])

    <div class="canovia-plan-dashboard-task-detail" data-plan-dashboard-task-detail aria-hidden="true">
        <button type="button" class="canovia-plan-dashboard-task-backdrop" data-plan-dashboard-task-detail-close aria-label="Task Detailを閉じる"></button>

        <section class="canovia-plan-dashboard-task-panel" role="dialog" aria-modal="true" aria-labelledby="canovia-plan-dashboard-task-title">
            <header class="canovia-plan-dashboard-task-heading">
                <div>
                    <p>TASK DETAIL</p>
                    <h2 id="canovia-plan-dashboard-task-title" data-plan-dashboard-task-detail-title>Task Detail</h2>
                </div>
                <button type="button" class="canovia-plan-dashboard-task-close" data-plan-dashboard-task-detail-close aria-label="閉じる">×</button>
            </header>

            <div class="canovia-plan-dashboard-task-content" data-plan-dashboard-task-detail-content></div>
        </section>
    </div>
</section>
@endsection
