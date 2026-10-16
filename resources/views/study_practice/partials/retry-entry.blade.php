@if (app(\App\Services\PlanOwnershipService::class)->canEdit(request(), $plan))
    <section class="mt-5 grid gap-4 sm:grid-cols-2" data-study-retry-entry aria-label="復習後の二つの進め方">
        <div class="page-card space-y-3 p-4">
            <h2 class="font-bold text-slate-100">1. 同じ問題に再回答する</h2>
            <p class="text-sm leading-6 text-slate-400">回答の振り返り練習です。元の結果を変更せず、独立した記録に保存します。別問題の理解確認や習熟の証拠にはしません。</p>
            @if (app(\App\Services\StudyPracticeRetryService::class)->cards($source) !== [])
                <a class="btn-primary" href="{{ route('plans.tasks.study_practice.retry.create', [$plan, $task, $source->id]) }}">同じ問題への再回答を始める</a>
            @else
                <p class="text-xs text-slate-400">再回答できる誤答・一部正解の形式を確認できません。記録のない内容は推測しません。</p>
            @endif
        </div>
        <div class="page-card space-y-3 p-4">
            <h2 class="font-bold text-slate-100">2. 別問題で確認する方法を探す</h2>
            <p class="text-sm leading-6 text-slate-400">Question Bank側の復習候補で、同分野の未回答問題があるか確認できます。Bank自身の回答履歴と出題条件を使い、AI演習の評価をBankの弱点記録へ変換しません。</p>
            <a class="btn-secondary" href="{{ route('plans.tasks.learning.index', [$plan, $task, 'review' => 1]) }}">Question Bankの復習候補を確認</a>
        </div>
    </section>
@endif
