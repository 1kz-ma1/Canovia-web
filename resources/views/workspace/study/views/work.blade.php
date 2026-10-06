<div class="space-y-5" data-study-view-panel="work">
    @include('workspace.partials.execution-setup', [
        'executionSetup' => $executionSetup ?? null,
        'plan' => $plan,
        'navigationTask' => $navigationTask,
        'canEdit' => $canEdit,
    ])

    @include('intelligence.partials.state-change', [
        'feedback' => $intelligenceStateChange ?? null,
    ])

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
</div>
