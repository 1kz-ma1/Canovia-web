<div
    class="canovia-dashboard-document-grid canovia-plan-dashboard-board"
    data-dashboard-document-grid
    data-plan-dashboard-board
>
    <x-dashboard-document-region
        kicker="PLAN"
        title="概要"
        :span="4"
        emphasis="primary"
        class="canovia-plan-board-overview"
        data-plan-board-region="overview"
    >
        <p class="canovia-plan-board-description">
            {{ data_get($workspaceBoard, 'overview.description') ?: 'このPlanの概要と現在地を確認します。' }}
        </p>

        <div class="canovia-plan-board-inline-meta">
            <span>{{ data_get($workspaceBoard, 'overview.task_count', 0) }} Task</span>
            <span>{{ data_get($workspaceBoard, 'overview.completed_count', 0) }} 完了</span>
            <span>{{ data_get($workspaceBoard, 'overview.active_count', 0) }} Active</span>
        </div>

        <div class="canovia-plan-document-secondary-actions">
            @if ($workspaceCanManage)
                <a href="{{ route('plans.edit', $workspacePlan) }}" class="btn-secondary">計画を編集</a>
            @endif
            <a href="{{ route('plans.show', $workspacePlan) }}" class="btn-secondary">Classic Plan</a>
        </div>
    </x-dashboard-document-region>

    <x-dashboard-document-region
        kicker="PROGRESS"
        title="進捗・必要時間"
        :span="8"
        class="canovia-plan-board-progress"
        data-plan-board-region="progress"
    >
        @include('plans.partials.summary-metrics', [
            'plan' => $workspacePlan,
            'progress' => $workspaceProgress,
            'summaryClass' => 'canovia-plan-board-metrics',
        ])
    </x-dashboard-document-region>

    <x-dashboard-document-region
        kicker="NEXT"
        title="次にやること"
        :span="4"
        emphasis="primary"
        class="canovia-plan-board-next"
        data-plan-board-region="next"
    >
        @if ($workspaceNext)
            <button
                type="button"
                class="canovia-plan-board-task-card is-primary"
                data-roadmap-task-detail-open
                data-roadmap-task-template="{{ $workspaceNext['template_id'] }}"
                aria-label="{{ $workspaceNext['title'] }}の詳細を開く"
            >
                <span class="canovia-plan-board-task-state">{{ $workspaceNext['status_label'] }}</span>
                <strong>{{ $workspaceNext['title'] }}</strong>
                <small>
                    進捗 {{ $workspaceNext['progress_percent'] }}%
                    · 残り {{ $workspaceNext['remaining_minutes'] }}分
                </small>
                @if ($workspaceNext['next_action_note'])
                    <span>{{ $workspaceNext['next_action_note'] }}</span>
                @endif
            </button>
        @else
            <p class="canovia-plan-board-empty">現在選択できるTaskはありません。</p>
        @endif

        @if ($workspaceReady->isNotEmpty())
            <div class="canovia-plan-board-ready-list">
                <p>ほかに開始可能 {{ (int) ($workspaceSignals['ready_count'] ?? 0) }}件</p>
                @foreach ($workspaceReady as $readyTask)
                    <button
                        type="button"
                        data-roadmap-task-detail-open
                        data-roadmap-task-template="{{ $readyTask['template_id'] }}"
                    >{{ $readyTask['title'] }}</button>
                @endforeach
            </div>
        @endif
    </x-dashboard-document-region>

    <x-dashboard-document-region
        kicker="ATTENTION"
        title="判断材料"
        :span="4"
        :emphasis="($workspaceAttention['level'] ?? 'normal') === 'alert' ? 'alert' : (($workspaceAttention['level'] ?? 'normal') === 'quiet' ? 'quiet' : 'primary')"
        class="canovia-plan-board-attention"
        data-plan-board-region="attention"
    >
        <div class="canovia-plan-board-attention-copy">
            <span>{{ $workspaceAttention['label'] ?? '確認' }}</span>
            <p>{{ $workspaceAttention['message'] ?? 'Plan全体を確認してください。' }}</p>
        </div>

        <div class="canovia-plan-board-signal-strip">
            <span>開始可能 <strong>{{ (int) ($workspaceSignals['ready_count'] ?? 0) }}</strong></span>
            <span>前提待ち <strong>{{ (int) ($workspaceSignals['blocked_count'] ?? 0) }}</strong></span>
            <span>並行群 <strong>{{ (int) ($workspaceSignals['parallel_cluster_count'] ?? 0) }}</strong></span>
        </div>

        @if ($workspaceBlocked->isNotEmpty())
            <div class="canovia-plan-board-blocked-list">
                @foreach ($workspaceBlocked as $blockedTask)
                    <button
                        type="button"
                        data-roadmap-task-detail-open
                        data-roadmap-task-template="{{ $blockedTask['template_id'] }}"
                    >
                        <span>前提待ち</span>
                        <strong>{{ $blockedTask['title'] }}</strong>
                    </button>
                @endforeach
            </div>
        @endif
    </x-dashboard-document-region>

    <x-dashboard-document-region
        kicker="ACTIVITY"
        title="最近の実績"
        :span="4"
        class="canovia-plan-board-activity"
        data-plan-board-region="activity"
    >
        @if ($workspaceActivity->isNotEmpty())
            <p class="mb-2 text-[10px] font-bold text-slate-400">作業ログ</p>
            <div class="canovia-plan-board-activity-list">
                @foreach ($workspaceActivity as $activity)
                    <article>
                        <div>
                            <strong>{{ $activity['task_title'] }}</strong>
                            <small>{{ $activity['worked_on'] ?? '日付なし' }}</small>
                        </div>
                        <p>
                            {{ $activity['actual_minutes'] }}分
                            @if ((int) $activity['progress_delta_percent'] !== 0)
                                · 進捗 {{ (int) $activity['progress_delta_percent'] > 0 ? '+' : '' }}{{ $activity['progress_delta_percent'] }}%
                            @endif
                        </p>
                    </article>
                @endforeach
            </div>
        @else
            <p class="canovia-plan-board-empty">時間を記録した作業はまだありません。</p>
        @endif

        @if (! empty($workspaceBoard['reflections']))
            <section class="mt-3 border-t border-slate-700/50 pt-3" data-plan-board-reflections aria-label="自分の振り返りEvidence">
                <p class="mb-2 text-[10px] font-bold text-cyan-300">振り返りEvidence · 自分の記録</p>
                <ol class="grid gap-2">
                    @foreach ($workspaceBoard['reflections'] as $reflection)
                        <li class="min-w-0 rounded-lg border border-cyan-300/15 bg-cyan-300/[0.035] p-2"
                            data-plan-board-reflection-task="{{ $reflection['task_id'] }}">
                            <a class="block break-words text-[11px] font-bold leading-5 text-sky-200 underline-offset-2 hover:underline focus-visible:underline"
                               href="{{ $reflection['url'] }}">
                                {{ $reflection['task_title'] }}の振り返りを見る →
                            </a>
                            <p class="mt-1 break-words text-[10px] leading-4 text-slate-300">{{ $reflection['outcome'] }}</p>
                            <p class="mt-1 text-[9px] text-slate-500">
                                {{ $reflection['date'] ?? '日付なし' }} · 振り返りとして保存済み（進捗率への自動反映なし）
                            </p>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif
    </x-dashboard-document-region>

    <x-dashboard-document-region
        kicker="STRUCTURE"
        title="Spatial Roadmap"
        :span="12"
        :rows="2"
        class="canovia-plan-board-roadmap"
        data-plan-board-region="roadmap"
    >
        <div class="canovia-plan-palette-card-heading">
            <p>Phase・並行作業・依存関係を俯瞰します。</p>
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
    </x-dashboard-document-region>
</div>
