@extends('layouts.app')

@section('title', $plan->title . ' | Canovia')

@section('content')
    @php
        $status = $progress['status'] ?? '予定通り';

        $progressColorClass = match ($status) {
            '順調' => 'progress-green',
            '予定通り' => 'progress-blue',
            '遅れ気味' => 'progress-amber',
            '期限切れ' => 'progress-red',
            '作業時間不足' => 'progress-red',
            default => 'progress-slate',
        };

        $statusClass = match ($status) {
            '順調' => 'status-green',
            '予定通り' => 'status-blue',
            '遅れ気味' => 'status-amber',
            '期限切れ' => 'status-red',
            '作業時間不足' => 'status-red',
            default => 'status-slate',
        };
        $needsPlanUpdate = (bool) data_get($continuity, 'needs_plan_update', false);
        $showArtifactEntry = $plan->artifacts->isNotEmpty()
            || collect($planTools ?? [])->contains(fn ($tool) => ($tool['id'] ?? null) === 'artifacts');
    @endphp

    @if (session('success'))
        <div class="assistant-notice assistant-notice-success mb-6">{{ session('success') }}</div>
    @endif

    @if (session('status'))
        <div class="assistant-notice assistant-notice-info mb-6">{{ session('status') }}</div>
    @endif

    <section class="mb-8 plan-identity-shell" data-plan-accent="{{ $plan->accentKey() }}">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 flex-1">
                <div class="flex items-start gap-3">
                    <span class="plan-identity-icon" aria-hidden="true">{{ $plan->displayIcon() }}</span>
                    <div class="min-w-0">
                        <h1 class="text-3xl font-bold tracking-tight text-slate-50">{{ $plan->title }}</h1>
                    </div>
                </div>
                <div class="mt-3 max-w-3xl">
                    @include('layouts.partials.collapsible-text', [
                        'text' => $plan->description,
                        'toneClass' => 'leading-7 text-slate-300',
                    ])
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <span class="badge badge-slate">{{ $plan->category ?? '未設定' }}</span>
                    <span class="badge badge-slate">
                        優先度 {{ (int) ($priorityEvaluation['priority'] ?? 3) }}
                        · {{ ($priorityEvaluation['mode'] ?? 'auto') === 'manual' ? '手動' : '自動' }}
                    </span>
                    <span class="badge {{ $plan->is_public ? 'badge-green' : 'badge-slate' }}">
                        {{ $plan->is_public ? '公開' : '非公開' }}
                    </span>
                    @if ($plan->is_collaborative)
                        <span class="badge badge-slate">共同計画 · {{ ($collaborationRole ?? 'viewer') === 'owner' ? 'オーナー' : (($collaborationRole ?? 'viewer') === 'editor' ? '編集者' : '閲覧者') }}</span>
                    @endif
                </div>
            </div>

            <div class="w-full md:w-auto">
                <div class="grid grid-cols-2 gap-2 md:flex md:flex-wrap">
                    @if ($toolFocusTask && ($primaryPlanAction['id'] ?? null) === 'study_activity')
                        <a href="{{ route('plans.tasks.study_activity.show', [$plan, $toolFocusTask]) }}" class="btn-primary col-span-2 min-h-12 w-full justify-center md:w-auto md:flex-none">{{ $primaryPlanAction['icon'] ?? '◉' }} {{ data_get($primaryPlanAction, 'activity.action_label', '学習方法で進める') }}</a>
                    @elseif ($toolFocusTask && ($primaryPlanAction['id'] ?? null) === 'ai_practice')
                        <a href="{{ route('plans.tasks.study_practice.show', [$plan, $toolFocusTask]) }}" class="btn-primary col-span-2 min-h-12 w-full justify-center md:w-auto md:flex-none" data-guide-target="study-practice">✦ AI演習で進める</a>
                    @elseif ($toolFocusTask && ($primaryPlanAction['id'] ?? null) === 'career_workspace')
                        <a href="{{ route('plans.career.index', $plan) }}" class="btn-primary col-span-2 min-h-12 w-full justify-center md:w-auto md:flex-none">◆ Careerで進める</a>
                    @elseif ($toolFocusTask && ($primaryPlanAction['id'] ?? null) === 'artifacts')
                        <a href="{{ route('plans.artifacts.index', $plan) }}" class="btn-primary col-span-2 min-h-12 w-full justify-center md:w-auto md:flex-none">◇ 制作ファイルを開く</a>
                    @elseif ($toolFocusTask && ($primaryPlanAction['id'] ?? null) === 'resources')
                        <a href="{{ route('plans.resources.index', $plan) }}" class="btn-primary col-span-2 min-h-12 w-full justify-center md:w-auto md:flex-none">⌘ 関連資料を開く</a>
                    @elseif ($toolFocusTask && ($primaryPlanAction['id'] ?? null) === 'guided_execution')
                        <a href="{{ route('plans.tasks.guided_execution.show', [$plan, $toolFocusTask]) }}" class="btn-primary col-span-2 min-h-12 w-full justify-center md:w-auto md:flex-none">◎ 方針を決めて実行する</a>
                    @elseif ($toolFocusTask && ($primaryPlanAction['id'] ?? null) === 'timer')
                        <form method="POST" action="{{ route('work_sessions.start') }}" data-work-start-form class="col-span-2 w-full md:w-auto md:flex-none">
                            @csrf
                            <input type="hidden" name="task_id" value="{{ $toolFocusTask->id }}">
                            <input type="hidden" name="source" value="plan">
                            <button type="submit" class="btn-primary min-h-12 w-full justify-center">◷ 集中タイマーで進める</button>
                        </form>
                    @endif
                    <a href="{{ route('plans.dashboard', $plan) }}" class="btn-secondary min-h-11 w-full justify-center md:w-auto md:flex-none">Dashboard</a>
                    @if (! empty($planTools))
                        <a href="#canovia-tools" class="btn-secondary min-h-11 w-full justify-center md:w-auto md:flex-none">Tools</a>
                    @endif
                    @if ($canManage ?? false)
                        <a href="{{ route('plans.review_assistant.show', $plan) }}" class="{{ $needsPlanUpdate ? 'btn-primary' : 'btn-secondary' }} min-h-11 w-full justify-center md:w-auto md:flex-none">計画を更新</a>
                    @endif
                    @if (($planCategoryProfile->key ?? null) === 'study')
                        <a href="{{ route('plans.study_scope.index', $plan) }}" class="btn-secondary min-h-11 w-full justify-center md:w-auto md:flex-none">試験範囲</a>
                    @endif
                    <a href="{{ route('plans.resources.index', $plan) }}" class="btn-secondary min-h-11 w-full justify-center md:w-auto md:flex-none">関連資料{{ $plan->resources->isNotEmpty() ? ' · '.$plan->resources->count() : '' }}</a>
                    @if ($showArtifactEntry)
                        <a href="{{ route('plans.artifacts.index', $plan) }}" class="btn-secondary min-h-11 w-full justify-center md:w-auto md:flex-none">制作ファイル{{ $plan->artifacts->isNotEmpty() ? ' · '.$plan->artifacts->count() : '' }}</a>
                    @endif
                </div>
                @if ($canManage ?? false)
                    <div class="mt-2 hidden flex-wrap gap-2 md:flex">
                        <form method="POST" action="{{ route('chat.start', 'ai_context') }}">
                            @csrf
                            <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                            <button type="submit" class="btn-secondary">AIに現状を共有</button>
                        </form>
                        <a href="{{ route('plans.edit', $plan) }}#plan-design" class="btn-secondary">🎨 デザイン</a>
                        <a href="{{ route('plans.edit', $plan) }}" class="btn-secondary">計画を編集</a>
                        @auth<a href="{{ route('plans.collaboration.settings', $plan) }}" class="btn-secondary">共同計画</a>@endauth
                    </div>
                    <details class="plan-secondary-actions mt-2 md:hidden">
                        <summary>その他の操作</summary>
                        <div class="mt-2 grid gap-2">
                            <form method="POST" action="{{ route('chat.start', 'ai_context') }}">
                                @csrf
                                <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                                <button type="submit" class="btn-secondary w-full">AIに現状を共有</button>
                            </form>
                            <a href="{{ route('plans.edit', $plan) }}#plan-design" class="btn-secondary w-full">🎨 デザイン</a>
                            <a href="{{ route('plans.edit', $plan) }}" class="btn-secondary w-full">計画を編集</a>
                            @auth<a href="{{ route('plans.collaboration.settings', $plan) }}" class="btn-secondary w-full">共同計画</a>@endauth
                        </div>
                    </details>
                @endif
            </div>
        </div>
    </section>

    @if (($studyToolCategoryMismatch ?? false) && ($canEdit ?? false))
        <section id="ai-practice-hint" class="mb-6 page-card border-amber-300/20 bg-amber-300/[0.035] p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-3xl">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-300">CANOVIA TOOLS</p>
                    <h2 class="mt-1 text-lg font-black text-slate-50">AI演習を使えそうですが、Planカテゴリが一致していません</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-300">
                        AI演習は学習カテゴリのPlanで利用できます。
                        このPlanが学習用なら、カテゴリを学習・試験系に変更すると未完了Taskからすぐ演習を始められます。
                    </p>
                </div>
                @if ($canManage ?? false)
                    <a href="{{ route('plans.edit', $plan) }}" class="btn-secondary">カテゴリを確認</a>
                @endif
            </div>
        </section>
    @endif

    @if ($plan->is_collaborative)
        @php
            $activityLabels = [
                'member_joined' => '共同計画に参加',
                'member_role_changed' => '権限を変更',
                'member_removed' => 'メンバーを外しました',
                'plan_updated' => '計画を更新',
                'task_created' => 'タスクを追加',
                'task_updated' => 'タスクを更新',
                'task_completed' => 'タスクを完了',
                'task_deleted' => 'タスクを削除',
                'plan_ai_updated' => 'AI更新を反映',
                'invite_regenerated' => '招待情報を再発行',
                'resource_created' => '関連資料を追加',
                'resource_updated' => '関連資料を更新',
                'resource_deleted' => '関連資料を削除',
                'resource_ai_assigned' => 'AIで資料を整理',
                'artifact_created' => '制作ファイルを追加',
                'artifact_updated' => '制作ファイルを更新',
                'artifact_deleted' => '制作ファイルを削除',
            ];
        @endphp
        <section class="mb-6 page-card p-4 sm:p-5">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-cyan-300">TOGETHER / ACTIVITY</p>
                    <h2 class="mt-1 text-lg font-bold text-slate-50">共同計画の最新情報</h2>
                </div>
                @if ($canManage ?? false)
                    <a href="{{ route('plans.collaboration.settings', $plan) }}" class="text-xs font-bold text-cyan-300 hover:text-cyan-200">共有設定</a>
                @endif
            </div>
            <div class="mt-4 grid gap-2 sm:grid-cols-2">
                @forelse (($recentActivities ?? collect()) as $activity)
                    @php
                        $meta = $activity->metadata ?? [];
                        $targetTitle = $meta['task_title'] ?? $meta['resource_title'] ?? $meta['artifact_title'] ?? $meta['member_name'] ?? null;
                    @endphp
                    <div class="rounded-2xl border border-white/8 bg-white/[0.035] p-3">
                        <p class="text-sm leading-6 text-slate-200">
                            <strong class="text-slate-50">{{ $activity->user?->name ?? 'Canovia' }}</strong>が{{ $activityLabels[$activity->action] ?? '計画を更新' }}@if($targetTitle)<span class="text-slate-400">「{{ $targetTitle }}」</span>@endif
                        </p>
                        <p class="mt-1 text-[11px] text-slate-500">{{ $activity->created_at?->diffForHumans() }}</p>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">まだ共同更新はありません。</p>
                @endforelse
            </div>
        </section>
    @endif

    @if ($canManage ?? false)
    @if (($canManage ?? false) && ($draftSpotlightReady ?? null))
        <section class="mb-5 rounded-2xl border border-slate-300 bg-slate-50 p-4 sm:p-5"
                 role="status" data-plan-draft-ready-spotlight data-plan-draft-id="{{ $draftSpotlightReady->id }}">
            <div>
                <p class="text-xs font-bold tracking-wider text-slate-600">計画案が準備できました</p>
                <h2 class="mt-1 text-base font-bold text-slate-900">3段階の次の行動を、確認・修正できます</h2>
                <p class="mt-2 text-sm leading-6 text-slate-700">記録をもとにした未承認の案です。今の作業を止める必要はありません。Taskへの追加は確認して承認した場合だけ行われます。</p>
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
                <form method="POST" action="{{ route('plans.action_drafts.spotlight.acknowledge', $plan) }}">
                    @csrf
                    <input type="hidden" name="action" value="open">
                    <button type="submit" class="btn-primary">計画案を確認する</button>
                </form>
                <form method="POST" action="{{ route('plans.action_drafts.spotlight.acknowledge', $plan) }}">
                    @csrf
                    <input type="hidden" name="action" value="dismiss">
                    <button type="submit" class="btn-secondary">今は閉じる</button>
                </form>
            </div>
        </section>
    @endif

        <section class="mb-8 adaptive-entry-card">
            <div>
                <h2 class="mt-1 text-xl font-bold text-slate-900">{{ $plan->tasks->isEmpty() ? '最初の行動から始めましょう' : '計画外の作業も記録できます' }}</h2>
                <p class="mt-2 max-w-3xl text-sm leading-7 text-slate-600">
                    {{ $plan->tasks->isEmpty()
                        ? 'ロードマップを最初に完成させる必要はありません。いまの状況や実際にやったことを記録して、次の行動を整理できます。'
                        : 'やったことをそのまま入力すれば、あとから計画に反映できます。' }}
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('plans.action_drafts.index', $plan) }}" class="btn-secondary" data-plan-action-drafts-entry>行動から計画案を育てる</a>
                <a href="{{ route('plans.review_assistant.show', $plan) }}" class="btn-primary">
                    {{ $plan->tasks->isEmpty() ? '現在の状況・やったことを記録する' : '実績・方針をまとめて更新' }}
                </a>
                @if ($plan->tasks->isEmpty())
                    <a href="{{ route('plans.ai_task_assistant.show', $plan) }}" class="btn-secondary" data-plan-optional-task-planning>
                        必要なら初期タスクを考える
                    </a>
                @endif
            </div>
        </section>
    @endif

    @if (($canEdit ?? false) && $continuity)
        <section class="mb-6 continuity-card plan-identity-shell" data-plan-accent="{{ $plan->accentKey() }}">
            <div class="min-w-0">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-sky-300"><span aria-hidden="true">{{ $plan->displayIcon() }}</span> 昨日の自分から今日へ</p>
                <h2 class="mt-2 text-lg font-bold text-slate-50">{{ $continuity['task_title'] }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-300">
                    {{ $continuity['next_action_note'] ?: ($continuity['is_active'] ? 'いま作業中です。' : '前回の続きから始められます。') }}
                </p>
                <div class="mt-3 flex flex-wrap gap-2 text-xs">
                    @if (! empty($continuity['ended_at']))
                        <span class="badge badge-slate">前回 {{ $continuity['ended_at']->diffForHumans() }}</span>
                    @elseif (! empty($continuity['started_at']))
                        <span class="badge badge-slate">開始 {{ $continuity['started_at']->diffForHumans() }}</span>
                    @endif
                    @if ($recommendation?->task?->id === ($continuity['task_id'] ?? null) && $recommendation?->recommendedMinutes)
                        <span class="badge badge-green">今回 {{ $recommendation->recommendedMinutes }}分</span>
                    @endif
                    @if ($continuity['needs_plan_update'])
                        <span class="badge badge-slate">計画へ未反映</span>
                    @endif
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2 sm:mt-0">
                @if ($continuity['is_active'])
                    <a href="{{ route('work_sessions.active', $continuity['session_id']) }}" class="btn-primary">作業へ戻る</a>
                @elseif ($continuity['can_resume_task'])
                    <form method="POST" action="{{ route('work_sessions.start') }}" data-work-start-form>
                        @csrf
                        <input type="hidden" name="task_id" value="{{ $continuity['task_id'] }}">
                        <input type="hidden" name="source" value="plan">
                        <button type="submit" class="btn-primary">続きから開始</button>
                    </form>
                @endif
                @if ($continuity['needs_plan_update'] && ($canManage ?? false))
                    <a href="{{ route('plans.review_assistant.show', ['plan' => $plan, 'work_session_id' => $continuity['session_id']]) }}" class="btn-secondary">結果を計画へ反映</a>
                @endif
            </div>
        </section>
    @endif

    @if (($canEdit ?? false) && $toolFocusTask && ! empty($planTools))
        <section id="canovia-tools" class="mb-6 page-card border-cyan-300/20 p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-cyan-300">CANOVIA TOOLS</p>
                    <h2 class="mt-1 text-lg font-black text-slate-50">「{{ $toolFocusTask->title }}」を進める</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-400">このTaskに合う実行方法を優先し、Timerは必要なときだけ表示します。</p>
                </div>
                <span class="badge badge-slate">Task #{{ $toolFocusTask->id }}</span>
            </div>
            @include('plans.partials.task-tools', [
                'tools' => $planTools,
                'toolTask' => $toolFocusTask,
                'toolPlan' => $plan,
                'compactTools' => false,
            ])
        </section>
    @endif

    @if ($plan->tasks->isNotEmpty())
    <section class="mb-8 page-card roadmap-shell p-4 sm:p-6">
        <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-emerald-400">ロードマップ</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-50">現在地と、次に進む道</h2>
                <p class="mt-2 max-w-3xl text-sm leading-7 text-slate-400"><span class="md:hidden">今いる場所と、この先を見られます。</span><span class="hidden md:inline">今いる場所と、この先をひとつの流れで見られます。</span></p>
            </div>
            @if ($canManage ?? false)
                <a href="{{ route('plans.review_assistant.show', $plan) }}" class="btn-secondary">ロードマップを更新</a>
            @endif
        </div>
        @include('plans.partials.roadmap', [
            'roadmap' => $roadmap,
            'roadmapPlan' => $plan,
            'roadmapCanEdit' => $canEdit ?? false,
            'roadmapMode' => 'plan',
            'roadmapRecommendedMinutes' => $recommendation?->recommendedMinutes,
            'roadmapRecommendationReasons' => $recommendation?->reasons ?? [],
        ])
    </section>
    @endif

    @include('plans.partials.summary-metrics', [
        'plan' => $plan,
        'progress' => $progress,
    ])

    @if (($progress['availability_configured'] ?? false))
        <section class="mb-8 page-card p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-sky-600">Availability</p>
                    <h2 class="mt-1 text-2xl font-bold text-slate-900">作業可能時間</h2>
                    <p class="mt-2 text-sm leading-7 text-slate-600">毎日同じ量を前提にせず、授業・休日・長期休暇などの現実の制約を進捗計算とおすすめ時間に使います。</p>
                </div>
                @if ($canManage ?? false)
                    <a href="{{ route('plans.review_assistant.show', $plan) }}" class="btn-secondary">AIと作業可能時間を更新</a>
                @endif
            </div>
            <div class="mt-5 mobile-metric-strip sm:grid sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($plan->availabilityRules->sortBy('day_of_week') as $rule)
                    @php $dayLabels = [0 => '日', 1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土']; @endphp
                    <div class="metric-card"><p class="text-xs text-slate-500">{{ $dayLabels[$rule->day_of_week] ?? $rule->day_of_week }}曜日</p><p class="mt-1 font-bold text-slate-900">{{ $rule->available_minutes }}分{{ $rule->is_optional ? '（任意）' : '' }}</p></div>
                @endforeach
            </div>
            @if ($plan->availabilityOverrides->isNotEmpty())
                <div class="mt-5 border-t border-slate-200 pt-4">
                    <p class="text-sm font-semibold text-slate-700">例外日</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($plan->availabilityOverrides->sortBy('date')->take(12) as $override)
                            <span class="badge badge-slate">{{ $override->date->format('m/d') }} {{ $override->available_minutes }}分{{ $override->note ? '・'.$override->note : '' }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="mt-5 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
                残り作業 {{ $progress['remaining_minutes'] }}分 / 残り作業可能 {{ $progress['remaining_available_minutes'] }}分
                @if (($progress['required_capacity_ratio'] ?? null) !== null)
                    ・必要負荷率 {{ round($progress['required_capacity_ratio'] * 100) }}%
                @endif
            </div>
        </section>
    @endif

    <section class="mb-8 page-card p-6">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-900">進捗情報</h2>
                <p class="mt-1 text-sm text-slate-500">
                    中止したタスクは必要作業量と進捗率の計算から除外しています。
                </p>
            </div>
            <div class="text-right">
                <p class="text-sm text-slate-500">現在の進捗率</p>
                <p class="text-3xl font-bold text-slate-900">{{ $progress['weighted_progress_percent'] }}%</p>
            </div>
        </div>

        <div class="mb-6 progress-track h-3">
            <div class="progress-bar {{ $progressColorClass }}" style="width: {{ min(100, max(0, $progress['weighted_progress_percent'])) }}%;"></div>
        </div>

        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
            <div class="metric-card">
                <p class="text-sm text-slate-500">合計想定時間</p>
                <p class="mt-1 text-xl font-bold text-slate-900">{{ round($progress['total_estimated_minutes'] / 60, 1) }}時間</p>
            </div>
            <div class="metric-card">
                <p class="text-sm text-slate-500">合計実績時間</p>
                <p class="mt-1 text-xl font-bold text-slate-900">{{ round($progress['total_actual_minutes'] / 60, 1) }}時間</p>
            </div>
            <div class="metric-card">
                <p class="text-sm text-slate-500">期待進捗率</p>
                <p class="mt-1 text-xl font-bold text-slate-900">{{ $progress['expected_progress_percent'] === null ? '—' : $progress['expected_progress_percent'] . '%' }}</p>
            </div>
            <div class="metric-card">
                <p class="text-sm text-slate-500">残り時間</p>
                <p class="mt-1 text-xl font-bold text-slate-900">{{ round($progress['remaining_minutes_by_progress'] / 60, 1) }}時間</p>
            </div>
            <div class="metric-card">
                <p class="text-sm text-slate-500">中止タスク</p>
                <p class="mt-1 text-xl font-bold text-slate-900">{{ $progress['cancelled_task_count'] ?? 0 }}件</p>
            </div>
        </div>
    </section>

    @if ($plan->is_public)
        <section class="mb-8 rounded-2xl border border-green-200 bg-green-50 p-5">
            <h2 class="font-bold text-green-900">公開URL</h2>
            <a href="{{ route('public_plans.show', $plan->public_slug) }}" class="mt-3 inline-flex break-all text-sm font-medium text-green-900 hover:underline">
                {{ route('public_plans.show', $plan->public_slug) }}
            </a>
        </section>
    @endif

    @php
        $taskModelsById = $plan->tasks->keyBy('id');
        $orderedTasks = collect($roadmap['nodes'] ?? [])
            ->pluck('task_id')
            ->filter()
            ->map(fn ($taskId) => $taskModelsById->get((int) $taskId))
            ->filter()
            ->values();
    @endphp

    <section class="mb-8 page-card p-6 hidden md:block">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-900">タスク一覧</h2>
                <p class="mt-1 text-sm text-slate-500">今やる順に並んでいます。</p>
            </div>
        </div>

        @if ($orderedTasks->isEmpty())
            <div class="empty-state">
                <p class="font-bold text-slate-900">まだタスクはありません。</p>
                @if ($canEdit ?? false)
                    <form method="POST" action="{{ route('chat.start', 'task') }}" class="mt-4">
                        @csrf
                        <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                        <button type="submit" class="btn-primary">チャットでタスクを追加</button>
                    </form>
                @endif
            </div>
        @else
            <div class="space-y-4">
                @foreach ($orderedTasks as $task)
                    @php
                        $taskProgressColorClass = match ($task->status) {
                            'done' => 'progress-green',
                            'doing' => 'progress-blue',
                            'cancelled' => 'progress-red',
                            default => 'progress-slate',
                        };
                        $taskStatusLabel = match ($task->status) {
                            'todo' => '未着手',
                            'doing' => '進行中',
                            'done' => '完了',
                            'cancelled' => '中止',
                            default => $task->status,
                        };
                        $taskStatusClass = match ($task->status) {
                            'done' => 'status-green',
                            'doing' => 'status-blue',
                            'cancelled' => 'status-red',
                            default => 'status-slate',
                        };
                    @endphp

                    <article class="rounded-xl border border-slate-200 p-4 {{ $task->status === 'cancelled' ? 'opacity-70' : '' }}">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-bold text-slate-900">{{ $task->title }}</h3>
                                    <span class="status-pill {{ $taskStatusClass }}">{{ $taskStatusLabel }}</span>
                                    @if ($task->continuation_of_task_id)
                                        <span class="badge badge-slate">前回作業から切り出し</span>
                                    @endif
                                </div>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $task->description ?? '説明なし' }}</p>
                                @if ($task->prerequisite)
                                    <p class="mt-2 text-xs font-medium text-amber-700">前提：{{ $task->prerequisite->title }}（{{ $task->prerequisite->status === 'done' ? '完了' : '未完了' }}）</p>
                                @endif
                                @if ($task->resources->isNotEmpty())
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @foreach ($task->resources as $resource)
                                            <a href="{{ $resource->url }}" target="_blank" rel="noopener noreferrer" class="rounded-full border border-cyan-300/20 bg-cyan-300/[0.06] px-3 py-1 text-xs font-semibold text-cyan-700 hover:bg-cyan-300/[0.12]">📎 {{ $resource->title }}</a>
                                        @endforeach
                                    </div>
                                @endif
                                @if ($task->artifacts->isNotEmpty())
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($task->artifacts as $artifact)
                                            <a href="{{ $artifact->url }}" target="_blank" rel="noopener noreferrer" class="rounded-full border border-violet-300/20 bg-violet-300/[0.06] px-3 py-1 text-xs font-semibold text-violet-700 hover:bg-violet-300/[0.12]">🛠 {{ $artifact->title }}@if($artifact->version_label) · {{ $artifact->version_label }}@endif</a>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            @if ($canEdit ?? false)
                                <div class="flex flex-wrap gap-2">
                                    @if (! in_array($task->status, ['done', 'cancelled'], true))
                                        <form method="POST" action="{{ route('work_sessions.start') }}">
                                            @csrf
                                            <input type="hidden" name="task_id" value="{{ $task->id }}">
                                            <input type="hidden" name="source" value="plan">
                                            <button type="submit" class="btn-primary px-3 py-2 text-sm">このタスクを始める</button>
                                        </form>
                                    @endif

                                    <a href="{{ route('tasks.edit', $task) }}" class="btn-secondary px-3 py-2 text-sm">編集</a>
                                </div>
                            @endif
                        </div>

                        @if (($canEdit ?? false) && ! empty($taskTools[(int) $task->id] ?? []))
                            @include('plans.partials.task-tools', [
                                'tools' => $taskTools[(int) $task->id],
                                'toolTask' => $task,
                                'toolPlan' => $plan,
                                'compactTools' => true,
                            ])
                        @endif

                        <div class="mt-4 mobile-metric-strip md:grid md:grid-cols-6">
                            <div class="metric-card"><p class="text-xs text-slate-500">総想定時間</p><p class="font-semibold text-slate-900">{{ $task->estimated_minutes }}分</p></div>
                            <div class="metric-card"><p class="text-xs text-slate-500">残り時間</p><p class="font-semibold text-slate-900">{{ $task->remaining_minutes ?? 0 }}分</p></div>
                            <div class="metric-card"><p class="text-xs text-slate-500">進捗率</p><p class="font-semibold text-slate-900">{{ $task->progress_percent }}%</p></div>
                            <div class="metric-card"><p class="text-xs text-slate-500">開始ハードル</p><p class="font-semibold text-slate-900">{{ $task->activation_cost ?? 3 }}/5</p></div>
                            <div class="metric-card"><p class="text-xs text-slate-500">状態</p><p class="font-semibold text-slate-900">{{ $taskStatusLabel }}</p></div>
                            <div class="metric-card"><p class="text-xs text-slate-500">優先度</p><p class="font-semibold text-slate-900">{{ $task->priority }}</p></div>
                        </div>

                        <div class="mt-4 progress-track">
                            <div class="progress-bar {{ $taskProgressColorClass }}" style="width: {{ min(100, max(0, $task->progress_percent)) }}%;"></div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="page-card p-6">
        <h2 class="text-2xl font-bold text-slate-900">これまでの記録</h2>
        <p class="mt-1 text-sm text-slate-500">作業結果と計画変更を、現在地がどう変わったかと一緒に時系列で残します。</p>

        @if ($timeline->isEmpty())
            <div class="empty-state mt-5">まだ記録はありません。</div>
        @else
            <div class="mt-5 space-y-4 border-l-2 border-slate-200 pl-5">
                @foreach ($timeline as $event)
                    <article class="relative rounded-xl border border-slate-200 p-4">
                        <span class="absolute -left-[1.85rem] top-5 h-3 w-3 rounded-full {{ $event['type'] === 'result' ? 'bg-sky-500' : 'bg-emerald-500' }}"></span>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <span class="badge {{ $event['type'] === 'result' ? 'badge-slate' : 'badge-green' }}">{{ $event['type'] === 'result' ? '結果' : '変更' }}</span>
                                <h3 class="mt-2 font-bold text-slate-900">{{ $event['title'] }}</h3>
                            </div>
                            <p class="text-sm text-slate-500">{{ $event['date_label'] }}</p>
                        </div>

                        @if ($event['summary'])
                            <p class="mt-3 text-sm leading-6 text-slate-600">{{ $event['summary'] }}</p>
                        @endif

                        @if ($event['type'] === 'result')
                            <div class="mt-4 flex flex-wrap gap-2 text-sm">
                                <span class="badge badge-slate">{{ $event['actual_minutes'] }}分</span>
                                @if ($event['progress_after'] !== null)<span class="badge badge-slate">進捗 {{ $event['progress_before'] ?? '—' }}% → {{ $event['progress_after'] }}%</span>@endif
                                @if ($event['remaining_after'] !== null)<span class="badge badge-slate">残り {{ $event['remaining_before'] ?? '—' }}分 → {{ $event['remaining_after'] }}分</span>@endif
                            </div>
                        @else
                            <div class="mt-4 flex flex-wrap gap-2 text-sm">
                                <span class="badge badge-slate">{{ $event['operation_count'] }}操作</span>
                                @if (data_get($event, 'metrics_after.weighted_progress_percent') !== null)
                                    <span class="badge badge-slate">計画進捗 {{ data_get($event, 'metrics_before.weighted_progress_percent', '—') }}% → {{ data_get($event, 'metrics_after.weighted_progress_percent') }}%</span>
                                    <span class="badge badge-slate">1日必要 {{ data_get($event, 'metrics_before.daily_required_minutes', '—') }}分 → {{ data_get($event, 'metrics_after.daily_required_minutes') }}分</span>
                                @endif
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection

@section('offline_snapshot')
@php
    $offlineCurrent = ! empty($roadmap['current'])
        ? collect($roadmap['current'])->only(['task_id', 'title', 'status', 'status_label', 'progress_percent', 'remaining_minutes', 'next_action_note', 'is_current'])->all()
        : null;
    $offlineSnapshot = [
        'type' => 'plan',
        'captured_at' => now()->toIso8601String(),
        'csrf_token' => csrf_token(),
        'plan' => ['id' => $plan->id, 'title' => $plan->title, 'category' => $plan->category],
        'current' => $offlineCurrent,
        'roadmap' => collect($roadmap['nodes'] ?? [])
            ->map(fn ($node) => collect($node)->only(['task_id', 'title', 'status', 'status_label', 'progress_percent', 'remaining_minutes', 'next_action_note', 'is_current'])->all())
            ->values()
            ->all(),
        'continuity' => $continuity,
    ];
@endphp
<script type="application/json" id="pacekeeper-offline-snapshot">{!! json_encode($offlineSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection
