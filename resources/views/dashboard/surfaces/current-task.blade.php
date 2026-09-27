<section
    class="page-card pk-v18-section-card p-4 sm:p-5 plan-identity-shell"
    data-plan-accent="{{ $item['plan']->accentKey() }}"
    data-plan-hub-current
    data-surface-id="current_task"
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-cyan-300">CURRENT TASK</p>
            <h2 class="mt-1 text-base font-black text-slate-100 sm:text-lg">次に進めること</h2>
        </div>
        @if (($primaryExecutionTool['id'] ?? null) === 'timer')
            <span class="badge badge-slate">時間は目安</span>
        @endif
    </div>

    @if ($hubCurrentTask)
        <div class="mt-4 rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.035] p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-base font-black leading-6 text-white">{{ $hubCurrentTask->title }}</h3>
                    @if ($hubCurrentTask->next_action_note)
                        <p class="mt-2 text-sm leading-6 text-slate-300">{{ \Illuminate\Support\Str::limit($hubCurrentTask->next_action_note, 80) }}</p>
                    @endif
                </div>
                <span class="badge {{ $hubCurrentTask->status === 'doing' ? 'badge-green' : 'badge-slate' }}">{{ $hubCurrentTask->status === 'doing' ? '進行中' : '未着手' }}</span>
            </div>

            <div class="mt-3 flex flex-wrap gap-2 text-[11px] text-slate-400">
                <span>進捗 {{ (int) $hubCurrentTask->progress_percent }}%</span>
                <span>残り目安 {{ (int) ($hubCurrentTask->remaining_minutes ?? 0) }}分</span>
                <span>優先度 {{ (int) $hubCurrentTask->priority }}</span>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                @if (($primaryExecutionTool['id'] ?? null) === 'study_activity')
                    <a href="{{ route('plans.tasks.study_activity.show', [$item['plan'], $hubCurrentTask]) }}" class="btn-primary px-3 py-2 text-xs">{{ $primaryExecutionTool['icon'] ?? '◉' }} {{ data_get($primaryExecutionTool, 'activity.action_label', '学習方法を確認') }}</a>
                @elseif (($primaryExecutionTool['id'] ?? null) === 'ai_practice')
                    <a href="{{ route('plans.tasks.study_practice.show', [$item['plan'], $hubCurrentTask]) }}" class="btn-primary px-3 py-2 text-xs">✦ AI演習で進める</a>
                @elseif (($primaryExecutionTool['id'] ?? null) === 'career_workspace')
                    <a href="{{ route('plans.career.index', $item['plan']) }}" class="btn-primary px-3 py-2 text-xs">◆ Careerで進める</a>
                @elseif (($primaryExecutionTool['id'] ?? null) === 'artifacts')
                    <a href="{{ route('plans.artifacts.index', $item['plan']) }}" class="btn-primary px-3 py-2 text-xs">◇ 制作ファイルを開く</a>
                @elseif (($primaryExecutionTool['id'] ?? null) === 'resources')
                    <a href="{{ route('plans.resources.index', $item['plan']) }}" class="btn-primary px-3 py-2 text-xs">⌘ 関連資料を開く</a>
                @elseif (($primaryExecutionTool['id'] ?? null) === 'guided_execution')
                    <a href="{{ route('plans.tasks.guided_execution.show', [$item['plan'], $hubCurrentTask]) }}" class="btn-primary px-3 py-2 text-xs">◎ 方針を決めて実行する</a>
                @elseif (($primaryExecutionTool['id'] ?? null) === 'timer')
                    <form method="POST" action="{{ route('work_sessions.start') }}" data-work-start-form>
                        @csrf
                        <input type="hidden" name="task_id" value="{{ $hubCurrentTask->id }}">
                        <input type="hidden" name="source" value="dashboard">
                        <button type="submit" class="btn-primary px-3 py-2 text-xs" data-guide-target="timer-fallback">◷ 集中タイマーで進める</button>
                    </form>
                @else
                    <a href="{{ route('plans.show', $item['plan']) }}" class="btn-primary px-3 py-2 text-xs">Taskを確認</a>
                @endif
            </div>

            <details class="pk-action-details mt-3" data-current-task-details>
                <summary>次の一歩・Taskの詳細</summary>
                <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-300">
                    {{ $hubCurrentTask->next_action_note ?: ($hubCurrentTask->description ?: 'このTaskを少し前へ進めましょう。') }}
                </p>
                @if ($hubCurrentTask->next_action_note && $hubCurrentTask->description)
                    <p class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-300">{{ $hubCurrentTask->description }}</p>
                @endif
                @if (($primaryExecutionTool['id'] ?? null) === 'study_activity')
                    <p class="mt-3 text-[11px] leading-5 text-cyan-200/80">このTaskでは「{{ data_get($primaryExecutionTool, 'activity.label', 'Study Activity') }}」をAI演習より優先しています。</p>
                @elseif (($primaryExecutionTool['id'] ?? null) === 'ai_practice')
                    <p class="mt-3 text-[11px] leading-5 text-cyan-200/80">AI演習は回答・評価結果をCanoviaが自動でEvidenceとして残します。</p>
                @elseif (($primaryExecutionTool['id'] ?? null) === 'guided_execution')
                    <p class="mt-3 text-[11px] leading-5 text-cyan-200/80">時間は測らず、実行前に見るポイントを決め、戻ってからReflectionをEvidenceとして残します。</p>
                @endif
            </details>
        </div>
    @endif
</section>
