@php
    $team = is_array($developmentTeam ?? null)
        ? $developmentTeam
        : [];
    $teamMembers = collect(data_get($team, 'members', []));
    $teamTaskOverview = data_get($team, 'task_overview', []);
    $teamTaskItems = collect(data_get($teamTaskOverview, 'tasks', []));
    $teamTaskStatusLabels = [
        'todo' => '未着手',
        'doing' => '進行中',
        'paused' => '保留',
        'done' => '完了',
    ];
    $teamActivityLabels = [
        'member_joined' => '参加',
        'member_role_changed' => '権限変更',
        'member_removed' => 'メンバー更新',
        'plan_updated' => 'Plan更新',
        'task_created' => 'Task追加',
        'task_updated' => 'Task更新',
        'task_completed' => 'Task完了',
        'task_deleted' => 'Task削除',
        'artifact_created' => '成果物追加',
        'artifact_updated' => '成果物更新',
        'artifact_deleted' => '成果物削除',
    ];
@endphp

<div class="space-y-4" data-development-surface-panel="team">
    <section class="page-card p-5 sm:p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-emerald-300">TEAM</p>
                <h2 class="mt-1 text-xl font-black text-slate-50">誰が、何を担当して、今どんな状態か。</h2>
                <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-500">
                    Canoviaが持っている共同計画の権限・担当成果物・最近の操作だけを表示します。Task担当者を推測して埋めることはしません。
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                @if ($plan->is_collaborative)
                    <a href="{{ route('plans.collaboration.settings', $plan) }}" class="btn-secondary min-h-10 px-3 text-xs">
                        メンバー管理
                    </a>
                @elseif ($canManage)
                    <a href="{{ route('plans.collaboration.settings', $plan) }}" class="btn-primary min-h-10 px-3 text-xs">
                        チーム利用を開始
                    </a>
                @endif
            </div>
        </div>

        <div class="mt-5 grid gap-3 lg:grid-cols-2 2xl:grid-cols-3">
            @forelse ($teamMembers as $member)
                @php
                    $memberRole = (string) data_get($member, 'role', 'viewer');
                    $memberActivity = data_get($member, 'last_activity');
                    $memberArtifacts = collect(data_get($member, 'assigned_artifacts', []));
                    $memberTasks = collect(data_get($member, 'task_titles', []));
                @endphp
                <article class="rounded-2xl border border-white/8 bg-slate-950/30 p-4" data-development-team-member="{{ data_get($member, 'user_id') }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-black text-slate-100">{{ data_get($member, 'name', 'メンバー') }}</p>
                            @if ($canManage && data_get($member, 'email'))
                                <p class="mt-1 truncate text-[10px] text-slate-600">{{ data_get($member, 'email') }}</p>
                            @endif
                        </div>
                        <span class="badge {{ $memberRole === 'owner' ? 'badge-green' : 'badge-slate' }}">
                            {{ data_get($member, 'role_label', '閲覧者') }}
                        </span>
                    </div>

                    <div class="mt-4">
                        <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">CURRENT WORK</p>
                        @if ($memberTasks->isNotEmpty())
                            <ul class="mt-2 space-y-1.5 text-xs leading-5 text-slate-300">
                                @foreach ($memberTasks as $taskTitle)
                                    <li>• {{ $taskTitle }}</li>
                                @endforeach
                            </ul>
                        @elseif ($memberArtifacts->isNotEmpty())
                            <ul class="mt-2 space-y-1.5 text-xs leading-5 text-slate-300">
                                @foreach ($memberArtifacts->take(3) as $artifact)
                                    <li>• {{ data_get($artifact, 'title') }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-2 text-xs leading-5 text-slate-600">
                                担当Taskの明示モデルはまだありません。担当成果物が設定されるとここに表示します。
                            </p>
                        @endif
                    </div>

                    <div class="mt-4 border-t border-white/8 pt-3">
                        <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">LATEST STATE</p>
                        @if (is_array($memberActivity))
                            <p class="mt-2 text-xs font-bold text-slate-300">
                                {{ $teamActivityLabels[data_get($memberActivity, 'action')] ?? '更新' }}
                                @if (data_get($memberActivity, 'target_title'))
                                    · {{ data_get($memberActivity, 'target_title') }}
                                @endif
                            </p>
                            <p class="mt-1 text-[10px] text-slate-600">{{ data_get($memberActivity, 'relative') }}</p>
                        @else
                            <p class="mt-2 text-xs text-slate-600">最近の共同更新はありません。</p>
                        @endif
                    </div>

                    @if ($memberArtifacts->isNotEmpty())
                        <details class="mt-3">
                            <summary class="cursor-pointer text-[10px] font-black text-cyan-300">担当成果物を見る</summary>
                            <div class="mt-2 space-y-2">
                                @foreach ($memberArtifacts as $artifact)
                                    <a href="{{ data_get($artifact, 'url') }}" target="_blank" rel="noopener noreferrer" class="block rounded-xl border border-white/8 bg-white/[0.02] p-2.5 text-xs text-slate-400 hover:text-slate-200">
                                        {{ data_get($artifact, 'title') }}
                                        @if (data_get($artifact, 'state'))
                                            <span class="ml-1 text-[9px] text-slate-600">· {{ data_get($artifact, 'state') }}</span>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </article>
            @empty
                <div class="empty-state lg:col-span-2 2xl:col-span-3">
                    まだチームメンバーはいません。共同計画を有効にすると、役割と状態共有をここへ集約できます。
                </div>
            @endforelse
        </div>
    </section>

    @if ($plan->is_collaborative)
        <section class="page-card p-5 sm:p-6" data-development-team-task-overview>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">DEPENDENCIES</p>
                    <h3 class="mt-2 text-lg font-black text-slate-100">チーム全体のTaskと前提条件</h3>
                    <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-400">
                        Canoviaに登録されているTask間の依存関係だけを表示します。
                        担当者はTaskへ直接記録されていないため推測しません。
                        進捗や担当割り当ては自動変更されません。
                    </p>
                </div>
                <a href="{{ route('plans.show', $plan) }}" class="btn-secondary inline-flex min-h-11 items-center px-4 text-xs">
                    Task一覧で確認する →
                </a>
            </div>
            <div class="mt-4 flex flex-wrap gap-3 text-xs text-slate-300" data-development-team-task-summary>
                <span>未完了 {{ (int) data_get($teamTaskOverview, 'total_open', 0) }}件</span>
                <span>完了 {{ (int) data_get($teamTaskOverview, 'total_done', 0) }}件</span>
                @if ((int) data_get($teamTaskOverview, 'blocked_count', 0) > 0)
                    <span>確認した前提待ち {{ (int) data_get($teamTaskOverview, 'blocked_count', 0) }}件</span>
                @endif
                @if ((int) data_get($teamTaskOverview, 'paused_count', 0) > 0)
                    <span>確認した保留 {{ (int) data_get($teamTaskOverview, 'paused_count', 0) }}件</span>
                @endif
            </div>
            @if ((bool) data_get($teamTaskOverview, 'truncated', false))
                <p class="mt-2 text-[11px] text-amber-300" data-development-team-task-sample-limit>
                    Task数が多いため、優先度順の最大80件について前提関係を確認しています。
                    前提待ち・保留の件数もこの範囲に限ります。
                </p>
            @endif
            @if ($teamTaskItems->isNotEmpty())
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    @foreach ($teamTaskItems->take(8) as $taskItem)
                        @php
                            $blockingTasks = collect(data_get($taskItem, 'blocked_by', []));
                            $blocked = (bool) data_get($taskItem, 'is_blocked', false);
                            $status = (string) data_get($taskItem, 'status', 'todo');
                        @endphp
                        <article class="rounded-xl border border-white/10 bg-slate-950/25 p-4"
                            data-development-team-task="{{ (int) data_get($taskItem, 'id') }}"
                            data-development-team-task-blocked="{{ $blocked ? 'true' : 'false' }}">
                            <div class="flex items-start justify-between gap-3">
                                <p class="min-w-0 break-words text-sm font-bold text-slate-100">{{ data_get($taskItem, 'title') }}</p>
                                <span class="shrink-0 text-[10px] text-slate-400">{{ $teamTaskStatusLabels[$status] ?? '未確認' }}</span>
                            </div>
                            <p class="mt-1 text-[11px] text-slate-500">Task進捗 {{ (int) data_get($taskItem, 'progress_percent', 0) }}%</p>
                            @if ($blocked)
                                <div class="mt-3 border-t border-amber-300/15 pt-2">
                                    <p class="text-[11px] font-black text-amber-300">先に進める必要があるTask</p>
                                    <ul class="mt-1 space-y-1 text-xs leading-5 text-slate-300">
                                        @foreach ($blockingTasks as $blockingTask)
                                            <li>• {{ data_get($blockingTask, 'title') }}
                                                （{{ $teamTaskStatusLabels[(string) data_get($blockingTask, 'status')] ?? '未確認' }}）
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @elseif ($status === 'paused')
                                <p class="mt-2 text-xs text-slate-400">保留中です。再開条件をチームで確認してください。</p>
                            @else
                                <p class="mt-2 text-xs text-slate-500">未完了の前提Taskは記録されていません。</p>
                            @endif
                        </article>
                    @endforeach
                </div>
                @if ($teamTaskItems->count() > 8)
                    <p class="mt-3 text-xs text-slate-500">
                        この画面は最初の8件だけ表示しています。残りのTaskは一覧から確認できます。
                    </p>
                @endif
            @else
                <p class="mt-4 text-xs text-slate-500" data-development-team-no-tasks>
                    まだ未完了Taskがありません。最初の作業を記録してから進行状況を確認できます。
                </p>
            @endif
        </section>
    @endif

    @if ($plan->is_collaborative)
        <details class="page-card p-4 sm:p-5" data-development-team-management>
            <summary class="cursor-pointer list-none">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">MANAGEMENT</p>
                        <h3 class="mt-1 text-sm font-black text-slate-100">メンバー権限を管理</h3>
                    </div>
                    <span class="text-[10px] text-slate-600">必要なときだけ開く</span>
                </div>
            </summary>

            <div class="mt-4">
                @include('plans.partials.collaboration.members-card', [
                    'cardClass' => 'rounded-2xl border border-white/8 bg-slate-950/20 p-4',
                ])
            </div>
        </details>
    @endif
</div>
