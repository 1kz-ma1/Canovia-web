@php
    $team = is_array($developmentTeam ?? null)
        ? $developmentTeam
        : [];
    $teamMembers = collect(data_get($team, 'members', []));
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
