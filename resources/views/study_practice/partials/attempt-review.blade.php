<section class="mt-5 space-y-4" data-attempt-review aria-label="問題別レビュー">
    <header>
        <h2 class="text-xl font-black text-slate-50">問題別レビュー · 間違えた問題から振り返る</h2>
        <p class="mt-2 text-xs leading-6 text-slate-400">この回の保存済み問題・回答・評価を表示しています。記録のない思考過程や正答は補っていません。</p>
        @unless ($attemptReview['trusted'])
            <p class="mt-2 rounded-xl border border-amber-300/30 p-3 text-sm text-amber-100" data-review-provenance>保存済みの評価です。正答根拠のない項目の内容・正誤・誤解ポイントは独立検証されていないため、確定した事実として扱わないでください。</p>
        @endunless
        <div class="mt-3 flex flex-wrap gap-2" data-review-counts>
            @foreach (['incorrect' => '誤答', 'partial' => '一部正解', 'correct' => '正解', 'ungraded' => '評価不能'] as $status => $label)
                <span class="rounded-full border border-slate-600 px-3 py-1 text-sm text-slate-200">{{ $label }} {{ $attemptReview['counts'][$status] }}件</span>
            @endforeach
        </div>
    </header>
    @forelse ($attemptReview['cards'] as $card)
        <article class="rounded-2xl border p-4 sm:p-5 {{ in_array($card['status'], ['incorrect', 'partial']) ? 'border-amber-300/40 bg-amber-300/[0.04]' : 'border-slate-700 bg-slate-950/25' }}" data-review-question="{{ $card['id'] }}" data-review-status="{{ $card['status'] }}">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="font-black text-slate-100">元の第{{ $card['ordinal'] }}問</h3>
                <span class="badge {{ ['correct' => 'badge-green', 'partial' => 'badge-amber', 'incorrect' => 'border-rose-300/40 bg-rose-300/10 text-rose-200', 'ungraded' => 'badge-slate'][$card['status']] }}">{{ ['incorrect' => '誤答', 'partial' => '一部正解', 'correct' => '正解', 'ungraded' => '評価不能'][$card['status']] }}</span>
                <span class="text-xs text-slate-400">記録された評価</span>
            </div>
            <p class="mt-3 whitespace-pre-wrap text-base leading-7 text-slate-100" data-review-prompt>{{ $card['prompt'] }}</p>
            @if ($card['mapping_issue'])
                <p class="mt-2 text-sm text-amber-200">IDの欠損・重複、または対応する記録がない項目があります。位置や近くの問題から対応を推測していません。</p>
            @endif
            @foreach ($card['fields'] as $field)
                <section class="mt-4 rounded-xl border border-slate-700 p-3">
                    <h4 class="text-sm font-bold text-slate-200">{{ $field['label'] }}</h4>
                    @if ($field['choices'])
                        <p class="mt-2 text-xs text-slate-400">全選択肢</p>
                        <ul class="mt-2 space-y-2 text-sm leading-6 text-slate-300" data-review-choices>
                            @foreach ($field['choices'] as $choice)
                                <li>{{ is_scalar($choice['id'] ?? null) ? $choice['id'] : '—' }}：{{ is_scalar($choice['label'] ?? null) ? $choice['label'] : '本文の記録なし' }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <p class="mt-3 text-xs font-bold text-cyan-200">{{ in_array($field['type'], ['textarea', 'short_text']) ? '記録済みの記述・思考過程' : '自分の回答（保存記録）' }}</p>
                    <p class="mt-1 whitespace-pre-wrap text-sm leading-6 text-slate-100" data-review-answer>{{ $field['recorded'] ? $field['answer_text'] : 'この回答欄の記録はありません。' }}</p>
                </section>
            @endforeach
            <section class="mt-4 space-y-2 text-sm leading-6 text-slate-300">
                <h4 class="font-bold text-slate-100">問題別フィードバック（保存記録）</h4>
                <p class="whitespace-pre-wrap">{{ $card['feedback'] ?: '問題別フィードバックは記録されていません。' }}</p>
                @if ($card['reasoning_feedback'])
                    <p class="font-bold text-violet-200">思考過程へのフィードバック</p>
                    <p class="whitespace-pre-wrap">{{ $card['reasoning_feedback'] }}</p>
                @endif
                @if ($card['misconceptions'])
                    <p class="font-bold text-amber-200">記録された誤解ポイント（候補）</p>
                    <ul>@foreach ($card['misconceptions'] as $point)<li>・{{ $point }}</li>@endforeach</ul>
                @endif
            </section>
            @if ($card['reference'])
                <section class="mt-4 rounded-xl border border-emerald-300/25 p-3 text-sm leading-6 text-slate-300" data-review-reference>
                    <h4 class="font-bold text-emerald-200">正答・解説の根拠：保存時の公開Question Bank</h4>
                    <p class="mt-2 whitespace-pre-wrap">{{ $card['reference']['answer_text'] }}</p>
                    <p class="mt-2 whitespace-pre-wrap">{{ $card['reference']['explanation'] ?: '解説は記録されていません。' }}</p>
                    <p class="mt-2 text-xs">{{ $card['reference']['source_reference'] ?: '出典の追加記録なし' }}</p>
                </section>
            @else
                <p class="mt-4 text-xs leading-6 text-slate-400" data-review-no-reference>信頼できる保存済み正答・解説の根拠がないため、確定した正答・解説は表示しません。上のフィードバックは評価者の記録です。</p>
            @endif
        </article>
    @empty
        <p class="text-sm text-slate-400">この回の問題本文は保存されていません。元のスコアや履歴は変更していません。</p>
    @endforelse
</section>
