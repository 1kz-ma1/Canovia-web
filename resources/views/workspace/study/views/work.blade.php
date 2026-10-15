<div class="space-y-5" data-study-view-panel="work">
    {{-- Execute first: the composed surfaces own the next Study Action and resume CTA. --}}
    <div
        class="space-y-5"
        data-study-workspace-composed
        data-study-learning-type="{{ data_get($studyLearningType ?? [], 'key', 'general_learning') }}"
    >
        @forelse (data_get($composition, 'surfaces', []) as $surface)
            @include($surface['partial'], $surface['data'] ?? [])
        @empty
            <section class="page-card p-5 sm:p-6">
                <div class="empty-state">
                    今すぐ実行できる学習Actionを整理しています。準備画面で範囲・教材・現在地を確認できます。
                </div>
            </section>
        @endforelse
    </div>

    @include('workspace.partials.execution-setup', [
        'executionSetup' => $executionSetup ?? null,
        'plan' => $plan,
        'navigationTask' => $navigationTask,
        'canEdit' => $canEdit,
    ])

    {{-- Review actions remain actionable; source/coverage and State evidence move to their focused views. --}}
    @include('workspace.study.partials.learning-review-queue')
    @include('workspace.study.partials.learning-plan-priority')

    <nav class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-white/8 pt-3 text-xs text-slate-400"
        aria-label="学習Actionの根拠を確認" data-study-work-evidence-links>
        <span>判断の根拠や履歴は別画面で確認できます。</span>
        <a href="{{ route('workspace.study.index', ['plan_id' => $plan->id, 'surface' => 'analysis']) }}"
            class="inline-flex min-h-10 items-center font-semibold text-sky-300 underline underline-offset-4"
            data-study-work-analysis-link>学習分析・出典 →</a>
        <a href="{{ route('workspace.study.index', ['plan_id' => $plan->id, 'surface' => 'history']) }}"
            class="inline-flex min-h-10 items-center font-semibold text-sky-300 underline underline-offset-4"
            data-study-work-history-link>履歴・State変化 →</a>
    </nav>
</div>
