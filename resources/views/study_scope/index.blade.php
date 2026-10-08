@extends('layouts.app')

@section('title', '試験範囲 | Canovia')

@section('content')
@php
    $statusLabels = [
        'captured' => '未解析',
        'review' => '確認待ち',
        'confirmed' => '確定済み',
        'failed' => '解析失敗',
    ];
@endphp

<div class="mx-auto max-w-6xl space-y-5">
    @if (session('success'))
        <div class="assistant-notice assistant-notice-success">{{ session('success') }}</div>
        @if (str_contains((string) session('success'), '試験範囲を確定し'))
            <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-sky-300/20 bg-sky-300/[0.04] px-4 py-3" data-study-scope-next-action>
                <p class="flex-1 text-sm text-slate-200">確定した範囲をもとに、学習Workspaceで次の行動を確認できます。</p>
                <a href="{{ route('workspace.study.index', ['plan_id' => $plan->id]) }}" class="btn-primary min-h-11 px-4">次の学習行動を確認する</a>
            </div>
        @endif
    @endif
    @if (session('status'))
        <div class="assistant-notice assistant-notice-info">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="assistant-notice assistant-notice-error">
            <p class="font-bold">確認が必要な項目があります。</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="page-card p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-3xl">
                <p class="text-xs font-black uppercase tracking-[0.16em] text-sky-300">STUDY SCOPE CAPTURE</p>
                <h1 class="mt-2 text-2xl font-black text-slate-50">試験範囲を撮って、確認する</h1>
                <p class="mt-2 text-sm leading-6 text-slate-300">
                    テスト範囲表・先生のプリント・スクリーンショット・PDFを追加すると、
                    Canoviaが科目と範囲を整理します。確定するまではTaskや進捗を変更しません。
                </p>
                <p class="mt-2 text-xs text-slate-500">{{ $plan->displayIcon() }} {{ $plan->title }}</p>
            </div>
            <a href="{{ route('plans.show', $plan) }}" class="btn-secondary">Planへ戻る</a>
        </div>

        @if ($canEdit)
            <form method="POST" action="{{ route('plans.study_scope.store', $plan) }}" enctype="multipart/form-data" class="mt-5 rounded-2xl border border-sky-300/15 bg-sky-300/[0.035] p-4 sm:p-5">
                @csrf
                <div class="grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end">
                    <div>
                        <label class="text-sm font-bold text-slate-200" for="study-scope-file">画像・スクリーンショット・PDF</label>
                        <input
                            id="study-scope-file"
                            type="file"
                            name="source_file"
                            required
                            accept="image/jpeg,image/png,image/webp,application/pdf"
                            class="mt-2 block w-full rounded-xl border border-slate-700 bg-slate-950/50 px-3 py-3 text-sm text-slate-200"
                        >
                        <p class="mt-2 text-xs text-slate-500">最大10MB。iPhoneでは写真・カメラ・ファイルから選べます。</p>
                    </div>
                    <button type="submit" class="btn-primary min-h-11">範囲を読み取る</button>
                </div>
                <div class="mt-4">
                    <label class="text-xs font-bold text-slate-400" for="study-scope-note">補足（任意）</label>
                    <input
                        id="study-scope-note"
                        name="note"
                        maxlength="2000"
                        value="{{ old('note') }}"
                        class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950/50 px-3 py-2 text-sm text-slate-100"
                        placeholder="例：2学期中間テストの範囲表"
                    >
                </div>
            </form>
        @endif
    </section>

    @if ($intelligencePresentation ?? null)
        @include('intelligence.partials.summary', [
            'intelligencePresentation' => $intelligencePresentation,
            'intelligenceHistory' => $intelligenceHistory ?? [],
            'canExecuteIntelligence' => $canEdit,
            'intelligenceAnchor' => 'study-intelligence',
        ])
    @endif

    @if ($studyIntelligence)
        @php
            $studyState = $studyIntelligence->state;
            $studyReadiness = $studyIntelligence->readiness;
            $studyMetrics = $studyState->metrics;
            $studyFacts = $studyState->facts;
            $pressureLabel = match ((string) data_get($studyFacts, 'deadline_pressure', 'unknown')) {
                'low' => '余裕あり',
                'medium' => 'やや詰まり気味',
                'high' => '負荷高め',
                'overdue' => '期限超過',
                default => '未判定',
            };
            $priorityScope = collect(data_get($studyFacts, 'priority_remaining_scope', []));
        @endphp

        <section class="page-card border-emerald-300/20 p-5 sm:p-6" data-study-intelligence-detail>
            <div>
                <p class="text-xs font-black uppercase tracking-[0.16em] text-emerald-300">STUDY EVIDENCE DETAIL</p>
                <h2 class="mt-1 text-lg font-black text-slate-50">学習状態の内訳</h2>
                <p class="mt-2 text-xs leading-5 text-slate-500">
                    上の判断を構成している範囲・残り負荷・期限Contextを確認できます。
                </p>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3">
                    <p class="text-[11px] text-slate-500">確定範囲</p>
                    <p class="mt-1 text-sm font-bold text-slate-100">{{ (int) data_get($studyMetrics, 'confirmed_scope_count', 0) }}件</p>
                </div>
                <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3">
                    <p class="text-[11px] text-slate-500">残りStudy Units</p>
                    <p class="mt-1 text-sm font-bold text-slate-100">
                        {{ data_get($studyMetrics, 'remaining_effort_units') !== null ? number_format((float) data_get($studyMetrics, 'remaining_effort_units'), 2) : '—' }}
                    </p>
                    <p class="mt-1 text-[10px] text-slate-500">分数ではなく、範囲1件=1を基準にした相対負荷</p>
                </div>
                <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3">
                    <p class="text-[11px] text-slate-500">期限負荷</p>
                    <p class="mt-1 text-sm font-bold text-slate-100">{{ $pressureLabel }}</p>
                    @if (data_get($studyMetrics, 'days_until_exam') !== null)
                        <p class="mt-1 text-[10px] text-slate-500">試験まで {{ (int) data_get($studyMetrics, 'days_until_exam') }}日</p>
                    @endif
                </div>
                <div class="rounded-xl border border-white/8 bg-white/[0.025] p-3">
                    <p class="text-[11px] text-slate-500">Speed</p>
                    <p class="mt-1 text-sm font-bold text-slate-100">未計測</p>
                    <p class="mt-1 text-[10px] text-slate-500">authoritativeな解答時間が取れるまでscoreへ入れません。</p>
                </div>
            </div>

            @if ($priorityScope->isNotEmpty())
                <div class="mt-5">
                    <p class="text-xs font-black text-slate-300">残り負荷が大きい範囲</p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach ($priorityScope->take(4) as $item)
                            <div class="rounded-xl border border-white/8 bg-slate-950/20 p-3">
                                <p class="text-sm font-bold text-slate-100">
                                    {{ $item['subject'] ?? '科目未設定' }}{{ ! empty($item['unit']) ? ' · '.$item['unit'] : '' }}
                                </p>
                                <p class="mt-1 text-[11px] text-slate-500">
                                    残り {{ number_format((float) ($item['remaining_unit'] ?? 0), 2) }} units
                                    · Mastery {{ isset($item['mastery_score_percent']) ? $item['mastery_score_percent'].'%' : '未計測' }}
                                    · Retention {{ isset($item['retention_score_percent']) ? $item['retention_score_percent'].'%' : '未計測' }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <p class="mt-4 text-xs leading-5 text-slate-500">
                Speedは現在のPractice Session時間からは採点・待機時間を分離できないため、V53.5では推測せず未計測にしています。
            </p>
        </section>
    @endif

    <section class="space-y-4">
        @forelse ($captures as $capture)
            @php
                $isOldCapture = (int) old('capture_id', 0) === (int) $capture->id;
                $draftItems = collect(data_get($capture->draft_data, 'items', []));
                $ambiguities = collect(data_get($capture->draft_data, 'ambiguities', []));
                $confirmedItems = $capture->items;

                if ($isOldCapture && is_array(old('items'))) {
                    $formItems = collect(old('items'));
                } elseif ($capture->status === 'confirmed') {
                    $formItems = $confirmedItems->map(fn ($item) => [
                        'item_id' => $item->id,
                        'subject' => $item->subject,
                        'unit' => $item->unit,
                        'range_text' => $item->range_text,
                        'page_start' => $item->page_start,
                        'page_end' => $item->page_end,
                        'source_excerpt' => $item->source_excerpt,
                        'confidence' => 100,
                    ]);
                } else {
                    $formItems = $draftItems->map(function ($item, $index) {
                        $item = is_array($item) ? $item : [];
                        $item['draft_index'] = $index;
                        return $item;
                    });
                }

                if ($formItems->isEmpty()) {
                    $formItems = collect([[
                        'subject' => '',
                        'unit' => '',
                        'range_text' => '',
                        'page_start' => null,
                        'page_end' => null,
                        'confidence' => null,
                    ]]);
                }
            @endphp

            <article class="page-card p-4 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="badge badge-slate">{{ $statusLabels[$capture->status] ?? $capture->status }}</span>
                            <span class="text-xs text-slate-500">{{ $capture->created_at?->format('Y/m/d H:i') }}</span>
                            @if ($capture->confidence !== null)
                                <span class="text-xs text-slate-500">読取信頼度 {{ (int) round($capture->confidence * 100) }}%</span>
                            @endif
                        </div>
                        <h2 class="mt-2 truncate text-lg font-black text-slate-50">
                            {{ $capture->exam_title ?: ($capture->inboxItem?->displayTitle() ?? '試験範囲') }}
                        </h2>
                        @if ($capture->exam_date_text && ! $capture->exam_date)
                            <p class="mt-1 text-xs text-amber-300">日付表記「{{ $capture->exam_date_text }}」は自動確定していません。</p>
                        @endif
                    </div>

                    <div class="flex flex-wrap gap-2">
                        @if ($capture->inboxItem)
                            <a href="{{ route('inbox.file', $capture->inboxItem) }}" target="_blank" rel="noopener noreferrer" class="btn-secondary">元ファイル</a>
                        @endif
                        @if ($canEdit && $canAnalyze && $capture->status !== 'confirmed')
                            <form method="POST" action="{{ route('plans.study_scope.analyze', [$plan, $capture]) }}">
                                @csrf
                                <button type="submit" class="btn-secondary">{{ $capture->status === 'review' ? '再解析' : 'AIで解析' }}</button>
                            </form>
                        @endif
                        @if ($canEdit && $capture->status !== 'confirmed')
                            <form method="POST" action="{{ route('plans.study_scope.destroy', [$plan, $capture]) }}" onsubmit="return confirm('この未確定Captureを破棄しますか？')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-secondary">破棄</button>
                            </form>
                        @endif
                    </div>
                </div>

                @if ($capture->inboxItem?->source_type === 'image')
                    <div class="mt-4 overflow-hidden rounded-2xl border border-white/8 bg-slate-950/30">
                        <img
                            src="{{ route('inbox.file', $capture->inboxItem) }}"
                            alt="試験範囲の元画像"
                            class="max-h-80 w-full object-contain"
                            loading="lazy"
                        >
                    </div>
                @endif

                @if ($ambiguities->isNotEmpty() && $capture->status !== 'confirmed')
                    <div class="mt-4 rounded-xl border border-amber-300/15 bg-amber-300/[0.035] p-3">
                        <p class="text-xs font-black text-amber-200">Canoviaが確定できなかった点</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs leading-5 text-slate-300">
                            @foreach ($ambiguities as $ambiguity)
                                <li>{{ $ambiguity }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($capture->status === 'failed')
                    <p class="mt-4 text-sm leading-6 text-slate-400">
                        AI解析に失敗しました。元ファイルは保存されています。再解析を待たず、下のフォームへ直接入力して確定することもできます。
                    </p>
                @elseif ($capture->status === 'captured')
                    <p class="mt-4 text-sm leading-6 text-slate-400">
                        まだ自動解析されていません。下のフォームへ直接入力して確定できます。
                    </p>
                @endif

                @if ($canEdit)
                    <form
                        method="POST"
                        action="{{ route('plans.study_scope.confirm', [$plan, $capture]) }}"
                        class="mt-5 space-y-4"
                        data-study-scope-form
                    >
                        @csrf
                        <input type="hidden" name="capture_id" value="{{ $capture->id }}">

                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-xs font-bold text-slate-400">テスト・試験名（任意）</span>
                                <input
                                    name="exam_title"
                                    maxlength="255"
                                    value="{{ $isOldCapture ? old('exam_title') : $capture->exam_title }}"
                                    class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950/50 px-3 py-2 text-sm text-slate-100"
                                    placeholder="例：2学期中間テスト"
                                >
                            </label>
                            <label class="block">
                                <span class="text-xs font-bold text-slate-400">試験日（分かる場合）</span>
                                <input
                                    type="date"
                                    name="exam_date"
                                    value="{{ $isOldCapture ? old('exam_date') : $capture->exam_date?->format('Y-m-d') }}"
                                    class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-950/50 px-3 py-2 text-sm text-slate-100"
                                >
                            </label>
                        </div>

                        <div class="space-y-3" data-study-scope-items>
                            @foreach ($formItems as $index => $item)
                                @php
                                    $item = is_array($item) ? $item : [];
                                    $excerpt = $item['source_excerpt'] ?? null;
                                    $itemConfidence = $item['confidence'] ?? null;
                                @endphp
                                <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-3 sm:p-4" data-study-scope-row>
                                    @if (! empty($item['item_id']))
                                        <input type="hidden" name="items[{{ $index }}][item_id]" value="{{ $item['item_id'] }}">
                                    @endif
                                    @if (array_key_exists('draft_index', $item))
                                        <input type="hidden" name="items[{{ $index }}][draft_index]" value="{{ $item['draft_index'] }}">
                                    @endif

                                    <div class="flex items-start justify-between gap-3">
                                        <p class="text-xs font-black text-slate-400">範囲 {{ $index + 1 }}</p>
                                        <div class="flex items-center gap-2">
                                            @if ($itemConfidence !== null && $capture->status !== 'confirmed')
                                                <span class="text-[11px] text-slate-500">読取 {{ (int) $itemConfidence }}%</span>
                                            @endif
                                            <button type="button" class="text-xs font-bold text-slate-500 hover:text-slate-300" data-study-scope-remove>削除</button>
                                        </div>
                                    </div>

                                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                        <label>
                                            <span class="text-xs font-bold text-slate-400">科目 *</span>
                                            <input
                                                name="items[{{ $index }}][subject]"
                                                maxlength="120"
                                                value="{{ $item['subject'] ?? '' }}"
                                                class="mt-1.5 w-full rounded-xl border border-slate-700 bg-slate-950/50 px-3 py-2 text-sm text-slate-100"
                                                placeholder="数学"
                                            >
                                        </label>
                                        <label>
                                            <span class="text-xs font-bold text-slate-400">単元・章</span>
                                            <input
                                                name="items[{{ $index }}][unit]"
                                                maxlength="255"
                                                value="{{ $item['unit'] ?? '' }}"
                                                class="mt-1.5 w-full rounded-xl border border-slate-700 bg-slate-950/50 px-3 py-2 text-sm text-slate-100"
                                                placeholder="二次関数"
                                            >
                                        </label>
                                    </div>

                                    <label class="mt-3 block">
                                        <span class="text-xs font-bold text-slate-400">範囲の説明</span>
                                        <input
                                            name="items[{{ $index }}][range_text]"
                                            maxlength="1000"
                                            value="{{ $item['range_text'] ?? '' }}"
                                            class="mt-1.5 w-full rounded-xl border border-slate-700 bg-slate-950/50 px-3 py-2 text-sm text-slate-100"
                                            placeholder="教科書 第3章、ワーク問題1〜20"
                                        >
                                    </label>

                                    <div class="mt-3 grid grid-cols-2 gap-3">
                                        <label>
                                            <span class="text-xs font-bold text-slate-400">開始ページ</span>
                                            <input
                                                type="number"
                                                min="1"
                                                max="100000"
                                                name="items[{{ $index }}][page_start]"
                                                value="{{ $item['page_start'] ?? '' }}"
                                                class="mt-1.5 w-full rounded-xl border border-slate-700 bg-slate-950/50 px-3 py-2 text-sm text-slate-100"
                                            >
                                        </label>
                                        <label>
                                            <span class="text-xs font-bold text-slate-400">終了ページ</span>
                                            <input
                                                type="number"
                                                min="1"
                                                max="100000"
                                                name="items[{{ $index }}][page_end]"
                                                value="{{ $item['page_end'] ?? '' }}"
                                                class="mt-1.5 w-full rounded-xl border border-slate-700 bg-slate-950/50 px-3 py-2 text-sm text-slate-100"
                                            >
                                        </label>
                                    </div>

                                    @if ($excerpt)
                                        <p class="mt-3 text-[11px] leading-5 text-slate-500">根拠: {{ $excerpt }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <template data-study-scope-template>
                            <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-3 sm:p-4" data-study-scope-row>
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-xs font-black text-slate-400">追加範囲</p>
                                    <button type="button" class="text-xs font-bold text-slate-500 hover:text-slate-300" data-study-scope-remove>削除</button>
                                </div>
                                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                    <label>
                                        <span class="text-xs font-bold text-slate-400">科目 *</span>
                                        <input data-field="subject" maxlength="120" class="mt-1.5 w-full rounded-xl border border-slate-700 bg-slate-950/50 px-3 py-2 text-sm text-slate-100" placeholder="英語">
                                    </label>
                                    <label>
                                        <span class="text-xs font-bold text-slate-400">単元・章</span>
                                        <input data-field="unit" maxlength="255" class="mt-1.5 w-full rounded-xl border border-slate-700 bg-slate-950/50 px-3 py-2 text-sm text-slate-100" placeholder="Lesson 4">
                                    </label>
                                </div>
                                <label class="mt-3 block">
                                    <span class="text-xs font-bold text-slate-400">範囲の説明</span>
                                    <input data-field="range_text" maxlength="1000" class="mt-1.5 w-full rounded-xl border border-slate-700 bg-slate-950/50 px-3 py-2 text-sm text-slate-100" placeholder="教科書・ワークの範囲">
                                </label>
                                <div class="mt-3 grid grid-cols-2 gap-3">
                                    <label>
                                        <span class="text-xs font-bold text-slate-400">開始ページ</span>
                                        <input data-field="page_start" type="number" min="1" max="100000" class="mt-1.5 w-full rounded-xl border border-slate-700 bg-slate-950/50 px-3 py-2 text-sm text-slate-100">
                                    </label>
                                    <label>
                                        <span class="text-xs font-bold text-slate-400">終了ページ</span>
                                        <input data-field="page_end" type="number" min="1" max="100000" class="mt-1.5 w-full rounded-xl border border-slate-700 bg-slate-950/50 px-3 py-2 text-sm text-slate-100">
                                    </label>
                                </div>
                            </div>
                        </template>

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <button type="button" class="btn-secondary" data-study-scope-add>＋ 範囲を追加</button>
                            <button type="submit" class="btn-primary">
                                {{ $capture->status === 'confirmed' ? '確定内容を更新' : 'この範囲で確定' }}
                            </button>
                        </div>
                        <p class="text-xs leading-5 text-slate-500">
                            確定するとStudy Scopeとして保存し、Study Intelligence Stateを再計算します。Task生成・Task進捗は変更しません。
                        </p>
                    </form>
                @else
                    <div class="mt-4 space-y-2">
                        @foreach ($confirmedItems as $item)
                            <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3">
                                <p class="text-sm font-bold text-slate-100">{{ $item->subject }}{{ $item->unit ? ' · '.$item->unit : '' }}</p>
                                @if ($item->range_text)
                                    <p class="mt-1 text-xs text-slate-400">{{ $item->range_text }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </article>
        @empty
            <div class="page-card border-dashed p-8 text-center">
                <p class="text-sm font-bold text-slate-300">まだ試験範囲はありません。</p>
                <p class="mt-2 text-xs leading-5 text-slate-500">最初は範囲表を1枚追加するだけで大丈夫です。</p>
            </div>
        @endforelse
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-study-scope-form]').forEach((form) => {
        const list = form.querySelector('[data-study-scope-items]');
        const template = form.querySelector('[data-study-scope-template]');
        const add = form.querySelector('[data-study-scope-add]');

        const wireRemove = (row) => {
            const button = row.querySelector('[data-study-scope-remove]');
            if (!button) return;
            button.addEventListener('click', () => {
                const rows = list.querySelectorAll('[data-study-scope-row]');
                if (rows.length === 1) {
                    row.querySelectorAll('input:not([type="hidden"])').forEach((input) => input.value = '');
                    return;
                }
                row.remove();
            });
        };

        list.querySelectorAll('[data-study-scope-row]').forEach(wireRemove);

        let nextIndex = list.querySelectorAll('[data-study-scope-row]').length;

        add?.addEventListener('click', () => {
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('[data-study-scope-row]');
            const index = nextIndex++;

            row.querySelectorAll('[data-field]').forEach((input) => {
                const field = input.getAttribute('data-field');
                input.name = `items[${index}][${field}]`;
                input.removeAttribute('data-field');
            });

            wireRemove(row);
            list.appendChild(fragment);
        });
    });
});
</script>
@endsection
