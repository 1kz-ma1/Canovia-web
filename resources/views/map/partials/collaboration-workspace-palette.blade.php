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
    $activityLabels = [
        'member_joined' => '共同計画に参加',
        'member_role_changed' => 'メンバー権限を変更',
        'member_removed' => 'メンバーを外しました',
        'plan_updated' => '計画を更新',
        'task_created' => 'タスクを追加',
        'task_updated' => 'タスクを更新',
        'task_completed' => 'タスクを完了',
        'task_deleted' => 'タスクを削除',
        'plan_ai_updated' => 'AI更新を反映',
        'resource_created' => '関連資料を追加',
        'resource_updated' => '関連資料を更新',
        'resource_deleted' => '関連資料を削除',
        'resource_ai_assigned' => 'AIで資料を整理',
        'artifact_created' => '制作ファイルを追加',
        'artifact_updated' => '制作ファイルを更新',
        'artifact_deleted' => '制作ファイルを削除',
        'invite_regenerated' => '招待情報を再発行',
    ];
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
        <article class="page-card canovia-collaboration-palette-card is-artifacts" data-collaboration-palette="artifacts">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-violet-300">PROJECT OUTPUTS</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-50">制作ファイル / 成果物</h3>
                    <p class="mt-1 text-sm text-slate-400">GitHub・Drive・OneDriveなど、制作物の最新版を同じ場所から開けます。</p>
                </div>
                <a href="{{ route('plans.artifacts.index', $workspacePlan) }}" class="btn-secondary px-3 py-2 text-xs">一覧を開く</a>
            </div>

            <div class="mt-5 space-y-3">
                @forelse ($workspaceArtifacts as $artifact)
                    <a href="{{ $artifact->url }}" target="_blank" rel="noopener noreferrer" class="block rounded-2xl border border-white/8 bg-white/[0.03] p-4 transition hover:border-violet-300/30 hover:bg-violet-300/[0.04]">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <strong class="block truncate text-slate-50">{{ $artifact->title }}</strong>
                                <p class="mt-1 text-xs text-slate-500">{{ $artifact->providerLabel() }} · 担当 {{ $artifact->assignedUser?->name ?? '未設定' }}</p>
                            </div>
                            @if ($artifact->version_label)
                                <span class="badge badge-green">{{ $artifact->version_label }}</span>
                            @endif
                        </div>
                        @if ($artifact->collaborationStateLabel())
                            <div class="mt-2"><span class="badge badge-slate">{{ $artifact->collaborationStateLabel() }}</span></div>
                        @endif
                    </a>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-700 p-4">
                        <p class="text-sm text-slate-500">まだ制作ファイルはありません。</p>
                        @if ($workspaceCanEdit)
                            <a href="{{ route('plans.artifacts.index', $workspacePlan) }}" class="mt-2 inline-block text-xs font-bold text-cyan-300">最初の制作物を登録 →</a>
                        @endif
                    </div>
                @endforelse
            </div>
        </article>

        <article class="page-card canovia-collaboration-palette-card is-members" data-collaboration-palette="members">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-lg font-bold text-slate-50">参加メンバー</h3>
                    <p class="mt-1 text-sm text-slate-400">誰が参加し、どこまで操作できるかを確認します。</p>
                </div>
                @if ($workspaceCanManage)
                    <a href="{{ route('plans.collaboration.settings', $workspacePlan) }}" class="btn-secondary px-3 py-2 text-xs">管理</a>
                @endif
            </div>

            <div class="mt-5 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-400/20 bg-amber-400/5 p-4">
                    <div class="min-w-0">
                        <strong class="block truncate text-slate-50">{{ $workspacePlan->user?->name ?? 'オーナー' }}</strong>
                        @if ($workspaceCanManage)
                            <span class="text-xs text-slate-400">{{ $workspacePlan->user?->email }}</span>
                        @endif
                    </div>
                    <span class="badge badge-green">オーナー</span>
                </div>

                @forelse ($workspacePlan->memberships as $member)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-700/80 bg-slate-950/35 p-4">
                        <div class="min-w-0">
                            <strong class="block truncate text-slate-50">{{ $member->user?->name ?? 'メンバー' }}</strong>
                            @if ($workspaceCanManage)
                                <span class="text-xs text-slate-400">{{ $member->user?->email }}</span>
                            @endif
                        </div>
                        <span class="badge badge-slate">{{ $member->role === 'editor' ? '編集者' : '閲覧者' }}</span>
                    </div>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-700 p-4 text-sm text-slate-500">
                        まだ参加者はいません。
                    </div>
                @endforelse
            </div>
        </article>

        <article class="page-card canovia-collaboration-palette-card is-activity" data-collaboration-palette="activity">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="font-bold text-slate-50">最新情報</h3>
                    <p class="mt-1 text-xs leading-5 text-slate-500">共同計画で最近行われた更新です。</p>
                </div>
                <span class="badge badge-slate">{{ $workspaceActivities->count() }}件</span>
            </div>

            <div class="mt-4 space-y-3">
                @forelse ($workspaceActivities as $activity)
                    @php
                        $meta = $activity->metadata ?? [];
                        $targetTitle = $meta['task_title'] ?? $meta['resource_title'] ?? $meta['artifact_title'] ?? $meta['member_name'] ?? null;
                        $actorName = $activity->user?->name ?? 'Canovia';
                    @endphp
                    <div class="rounded-2xl border border-white/8 bg-white/[0.035] p-3">
                        <div class="flex items-start gap-3">
                            <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-cyan-300 shadow-[0_0_12px_rgba(103,232,249,.7)]"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm leading-6 text-slate-200">
                                    <strong class="text-slate-50">{{ $actorName }}</strong>が{{ $activityLabels[$activity->action] ?? '計画を更新' }}@if($targetTitle)<span class="text-slate-400">「{{ $targetTitle }}」</span>@endif
                                </p>
                                <p class="mt-1 text-[11px] text-slate-500">{{ $activity->created_at?->diffForHumans() }}</p>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="rounded-2xl border border-dashed border-slate-700 p-4 text-sm leading-6 text-slate-500">
                        まだ共同更新はありません。参加や編集が行われるとここに表示されます。
                    </p>
                @endforelse
            </div>
        </article>
    </div>
</div>
@endif
