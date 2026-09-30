@php
    $workspace = is_array($collaborationWorkspace ?? null) ? $collaborationWorkspace : [];
    $workspacePlan = $workspace['plan'] ?? null;
    $workspaceArtifacts = collect($workspace['recent_artifacts'] ?? []);
    $workspaceActivities = collect($workspace['recent_activities'] ?? []);
    $workspaceCanManage = (bool) ($workspace['can_manage'] ?? false);
    $workspaceCanEdit = (bool) ($workspace['can_edit'] ?? false);
    $workspaceRole = (string) ($workspace['role'] ?? '');
    $workspaceRoleLabel = match ($workspaceRole) {
        'owner' => 'オーナー',
        'editor' => '編集者',
        'viewer' => '閲覧者',
        default => '共同',
    };
@endphp

@if ($workspacePlan)
<div
    class="canovia-collaboration-workspace-palette"
    data-map-collaboration-workspace
    aria-label="{{ $workspacePlan->title }}の共同Project Workspace"
>
    <header class="canovia-collaboration-workspace-heading">
        <div class="min-w-0">
            <p class="canovia-collaboration-workspace-kicker">SHARED PROJECT WORKSPACE</p>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <h2>{{ $workspacePlan->title }}</h2>
                <span class="badge badge-green">{{ $workspaceRoleLabel }}</span>
            </div>
            <p>Mapを無理に細分化せず、共同作業に必要なClassic情報をこのProject上へ置いています。</p>
        </div>
        <div class="canovia-collaboration-workspace-actions">
            <a href="{{ route('plans.create.manual', ['collaborative' => 1]) }}" class="btn-secondary">＋ 共同計画</a>
            <a href="{{ route('plans.collaboration.settings', $workspacePlan) }}" class="btn-secondary">共同設定</a>
            <a href="{{ route('plans.show', $workspacePlan) }}" class="btn-secondary">Classic Plan</a>
        </div>
    </header>

    <div class="canovia-collaboration-workspace-grid">
        @include('plans.partials.collaboration.artifacts-card', [
            'plan' => $workspacePlan,
            'recentArtifacts' => $workspaceArtifacts,
            'canEdit' => $workspaceCanEdit,
            'cardClass' => 'page-card canovia-collaboration-palette-card is-artifacts',
        ])

        @include('plans.partials.collaboration.members-card', [
            'plan' => $workspacePlan,
            'canManage' => $workspaceCanManage,
            'cardClass' => 'page-card canovia-collaboration-palette-card is-members',
        ])

        @include('plans.partials.collaboration.activity-card', [
            'recentActivities' => $workspaceActivities,
            'cardClass' => 'page-card canovia-collaboration-palette-card is-activity',
        ])
    </div>
</div>
@endif
