@extends(($instantFragment ?? false) || in_array(request()->header('X-Canovia-Instant-Navigation'), ['prefetch', 'navigate'], true) ? 'layouts.instant' : 'layouts.app')

@section('title', 'Canovia Map | Canovia')

@section('content')
    @php
        $nodes = collect($graph['nodes'] ?? []);
        $edges = collect($graph['edges'] ?? []);
        $nodeIndex = $nodes->keyBy('id');
        $mapLevel = (string) ($graph['level'] ?? 'l3');
        $isIntentHub = $mapLevel === 'l0';
        $isDomainLevel = $mapLevel === 'l1';
        $isPlanLevel = $mapLevel === 'l2';
        $isExecutionLevel = $mapLevel === 'l3';
        $isHierarchyLevel = $isDomainLevel || $isPlanLevel;
        $isCollaborationMode = (bool) ($graph['collaboration_mode'] ?? false);
        $isCollaborationWorkspace = (bool) ($graph['collaboration_workspace_mode'] ?? false);
        $isPlanWorkspace = (bool) ($graph['plan_workspace_mode'] ?? false);
        $planWorkspace = is_array($graph['plan_workspace'] ?? null)
            ? $graph['plan_workspace']
            : null;
        $collaborationWorkspace = is_array($graph['collaboration_workspace'] ?? null)
            ? $graph['collaboration_workspace']
            : null;
        $isReflectionMode = (bool) ($graph['reflection_mode'] ?? false);
        $primaryNodeId = $graph['primary_node_id'] ?? null;
        $centerNodeId = $graph['center_node_id'] ?? $primaryNodeId;
        $primaryLaunch = $graph['primary_launch'] ?? null;
        $hierarchy = is_array($graph['hierarchy'] ?? null) ? $graph['hierarchy'] : [];
        $mapReturnUrl = request()->getRequestUri();
        $currentContextLabel = (string) ($hierarchy['current_label'] ?? ($isIntentHub ? 'Canovia Map' : 'Canovia Map'));
        $surfaceContext = is_array($mapSurfaceContext ?? null) ? $mapSurfaceContext : [];
        $complexitySnapshot = is_array($mapComplexitySnapshot ?? null) ? $mapComplexitySnapshot : [];
        $heroKicker = match (true) {
            $mapLevel === 'l0' => 'L0 · CANOVIA NAVIGATION',
            $mapLevel === 'l1' && $isCollaborationMode => 'L1 · SHARED PROJECTS',
            $mapLevel === 'l2' && $isCollaborationMode => 'L2 · PROJECT WORKSPACE',
            $mapLevel === 'l1' && data_get($hierarchy, 'intent') === 'plan' => 'L1 · PLANS',
            $mapLevel === 'l1' && data_get($hierarchy, 'intent') === 'execution' => 'L1 · EXECUTION PLANS',
            $mapLevel === 'l2' && $isPlanWorkspace => 'L2 · PLAN DASHBOARD',
            $mapLevel === 'l1' && $isReflectionMode => 'L1 · REFLECTION LENS',
            $mapLevel === 'l2' && $isReflectionMode => 'L2 · REFLECTION RECORDS',
            $mapLevel === 'l1' => 'L1 · DOMAIN MAP',
            $mapLevel === 'l2' => 'L2 · PLAN MAP',
            default => 'L3 · EXECUTION MAP',
        };
    @endphp

    <section
        class="canovia-map-page {{ $isPlanWorkspace ? 'is-document-mode is-plan-document-mode' : '' }}"
        data-canovia-map-page
        data-map-level="{{ $mapLevel }}"
        data-map-hierarchy-depth="{{ (int) ($hierarchy['depth'] ?? 0) }}"
        data-map-parent-url="{{ $hierarchy['parent_url'] ?? '' }}"
        data-map-projection-key="{{ $graph['projection_key'] ?? '' }}"
        data-map-surface-role="{{ $surfaceContext['role'] ?? 'hierarchy_context' }}"
        data-map-surface-scope="{{ $surfaceContext['scope'] ?? 'contextual' }}"
        data-map-complexity-schema="{{ (int) ($complexitySnapshot['schema_version'] ?? 1) }}"
        data-map-complexity-node-count="{{ (int) ($complexitySnapshot['node_count'] ?? 0) }}"
        data-map-complexity-edge-count="{{ (int) ($complexitySnapshot['edge_count'] ?? 0) }}"
        data-map-complexity-plan-count="{{ (int) ($complexitySnapshot['plan_count'] ?? 0) }}"
        data-map-complexity-task-count="{{ (int) ($complexitySnapshot['task_count'] ?? 0) }}"
        data-map-complexity-dependency-count="{{ (int) ($complexitySnapshot['dependency_count'] ?? 0) }}"
        data-map-complexity-cluster-count="{{ (int) ($complexitySnapshot['cluster_count'] ?? 0) }}"
        data-map-complexity-parallel-cluster-count="{{ (int) ($complexitySnapshot['parallel_cluster_count'] ?? 0) }}"
        data-map-complexity-phase-count="{{ (int) ($complexitySnapshot['phase_count'] ?? 0) }}"
        data-map-complexity-blocked-count="{{ (int) ($complexitySnapshot['blocked_count'] ?? 0) }}"
        data-event-url="{{ route('behavior_events.store') }}"
    >
        @include('map.partials.topbar')
        @include('map.partials.extensions.global-navigation')
        @include('map.partials.extensions.surface-controls')
        @include('map.partials.runtime-status')
        @include('map.partials.workspace')
        @include('map.partials.surface-templates')
        @include('map.partials.map-note')
    </section>
@endsection
