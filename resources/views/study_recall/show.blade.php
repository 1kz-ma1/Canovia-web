@extends('layouts.app')

@section('title', 'Recall学習 | Canovia')

@section('content')
    @php
        $ratingLabels = [
            'again' => ['label' => 'もう一度', 'hint' => '10分後', 'class' => 'border-rose-300/25 bg-rose-300/[0.05] text-rose-100'],
            'hard' => ['label' => '難しい', 'hint' => '短め', 'class' => 'border-amber-300/25 bg-amber-300/[0.05] text-amber-100'],
            'good' => ['label' => '思い出せた', 'hint' => '標準', 'class' => 'border-cyan-300/25 bg-cyan-300/[0.05] text-cyan-100'],
            'easy' => ['label' => '余裕', 'hint' => '長め', 'class' => 'border-emerald-300/25 bg-emerald-300/[0.05] text-emerald-100'],
        ];
    @endphp

    <div class="mx-auto max-w-5xl space-y-5">
        <section class="page-card border-emerald-300/20 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-300">STUDY ACTIVITY / RECALL</p>
                    <h1 class="mt-2 text-2xl font-black text-slate-50">思い出す練習</h1>
                    <p class="mt-2 text-sm text-slate-400">{{ $plan->displayIcon() }} {{ $plan->title }} / {{ $task->title }}</p>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300">答えを読む前に思い出し、感触に応じて次に確認する間隔をCanoviaが調整します。学習時間だけではTask進捗を上げません。</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('plans.tasks.study_activity.show', [$plan, $task]) }}" class="btn-secondary">学習方法へ</a>
                    <a href="{{ route('plans.show', $plan) }}" class="btn-secondary">Planへ戻る</a>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-5">
                @foreach ([
                    ['label' => 'カード', 'value' => $stats['total']],
                    ['label' => '今やる', 'value' => $stats['due']],
                    ['label' => '未学習', 'value' => $stats['new']],
                    ['label' => '定着候補', 'value' => $stats['mastered']],
                    ['label' => '今日の確認', 'value' => $stats['reviewed_today']],
                ] as $stat)
                    <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3 text-center">
                        <p class="text-[10px] text-slate-500">{{ $stat['label'] }}</p>
                        <strong class="mt-1 block text-xl text-slate-100">{{ $stat['value'] }}</strong>
                    </div>
                @endforeach
            </div>
        </section>

        @if (session('success'))
            <div class="rounded-2xl border border-emerald-300/20 bg-emerald-300/[0.06] px-4 py-3 text-sm text-emerald-100">{{ session('success') }}</div>
        @endif
        @if (session('status'))
            <div class="rounded-2xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">{{ session('status') }}</div>
        @endif

        @php
            $recallProgressMetrics = (array) data_get($recallProgression ?? [], 'metrics', []);
            $recallProgressKind = (string) data_get($recallProgression ?? [], 'kind', 'empty');
            $recallProgressEligible = (bool) data_get($recallProgression ?? [], 'eligible', false);
        @endphp

        <section
            class="page-card border-emerald-300/15 p-4 sm:p-5"
            data-study-recall-progression
            data-study-recall-progression-kind="{{ $recallProgressKind }}"
        >
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-[10px] font-black uppercase tracking-[0.16em] text-emerald-300">TASK PROGRESSION</p>
                        @if ($recallProgressEligible)
                            <span class="badge badge-slate">Task完了候補</span>
                        @elseif ($recallProgressKind === 'supplementary')
                            <span class="badge badge-slate">補助学習</span>
                        @elseif ($recallProgressKind === 'completed')
                            <span class="badge badge-slate">完了済み</span>
                        @endif
                    </div>

                    <p class="mt-2 text-sm font-black text-slate-100">
                        Recallの定着をTask進行へつなげる
                    </p>
                    <p class="mt-1 max-w-3xl text-xs leading-5 text-slate-500">
                        {{ data_get($recallProgression ?? [], 'reason', 'Recallの進行状態を確認しています。') }}
                    </p>

                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-slate-500">
                        <span>確認済み {{ (int) ($recallProgressMetrics['reviewed'] ?? 0) }}/{{ (int) ($recallProgressMetrics['total'] ?? 0) }}</span>
                        <span>定着候補 {{ (int) ($recallProgressMetrics['mastered'] ?? 0) }}/{{ (int) ($recallProgressMetrics['total'] ?? 0) }}</span>
                        <span>今やる {{ (int) ($recallProgressMetrics['due'] ?? 0) }}</span>
                    </div>
                </div>

                @if ($recallProgressEligible && ($canEdit ?? false))
                    <form
                        method="POST"
                        action="{{ route('plans.tasks.study_recall.complete', [$plan, $task]) }}"
                        class="shrink-0"
                        data-study-recall-complete-form
                        data-mutation-once
                    >
                        @csrf
                        <button type="submit" class="btn-primary min-h-10 px-4 text-xs">
                            Recall定着を確認してTask完了
                        </button>
                    </form>
                @elseif ($recallProgressEligible)
                    <p class="shrink-0 text-[10px] leading-4 text-slate-600">
                        Task完了の反映には編集権限が必要です。
                    </p>
                @endif
            </div>

            @error('recall_progression')
                <p class="mt-3 text-xs text-rose-300">{{ $message }}</p>
            @enderror
        </section>

        @if ($currentItem)
            <section class="page-card border-cyan-300/20 p-5 sm:p-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[11px] font-black uppercase tracking-[0.14em] text-cyan-300">RECALL NOW</p>
                        <p class="mt-1 text-xs text-slate-500">まず答えを見ずに思い出してください。</p>
                    </div>
                    <span class="badge badge-slate">復習 {{ (int) $currentItem->repetitions }}回</span>
                </div>

                <div class="mt-6 rounded-2xl border border-white/10 bg-white/[0.03] p-5 sm:p-7">
                    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">FRONT</p>
                    <h2 class="mt-3 whitespace-pre-line text-xl font-black leading-8 text-slate-50">{{ $currentItem->prompt }}</h2>
                </div>

                <details class="mt-4 rounded-2xl border border-emerald-300/15 bg-emerald-300/[0.035] p-4">
                    <summary class="cursor-pointer text-sm font-black text-emerald-100">答えを表示</summary>
                    <div class="mt-4 border-t border-white/8 pt-4">
                        <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">BACK</p>
                        <p class="mt-2 whitespace-pre-line text-base leading-7 text-slate-100">{{ $currentItem->answer }}</p>
                        @if ($currentItem->note)
                            <p class="mt-3 text-xs leading-5 text-slate-400">{{ $currentItem->note }}</p>
                        @endif

                        <p class="mt-5 text-xs font-bold text-slate-400">思い出せた感触を選択</p>
                        <div class="mt-2 grid gap-2 sm:grid-cols-4">
                            @foreach ($ratingLabels as $rating => $meta)
                                <form method="POST" action="{{ route('plans.tasks.study_recall.items.review', [$plan, $task, $currentItem]) }}" data-mutation-once>
                                    @csrf
                                    <input type="hidden" name="rating" value="{{ $rating }}">
                                    <input type="hidden" name="review_request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                    <button type="submit" class="w-full rounded-xl border p-3 text-left transition hover:bg-white/[0.06] {{ $meta['class'] }}">
                                        <strong class="block text-sm">{{ $meta['label'] }}</strong>
                                        <span class="mt-1 block text-[10px] opacity-70">{{ $meta['hint'] }}</span>
                                    </button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                </details>
            </section>
        @elseif ($stats['total'] > 0)
            <section class="page-card border-emerald-300/20 p-5 sm:p-6">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-300">RECALL COMPLETE</p>
                <h2 class="mt-2 text-xl font-black text-slate-50">今すぐ確認するカードはありません</h2>
                <p class="mt-2 text-sm leading-6 text-slate-300">次回の復習時期まで間隔を空けます。覚えているカードを無意味に繰り返すより、忘れかけた頃に思い出すことを優先します。</p>
            </section>
        @endif

        <section class="page-card border-violet-300/20 p-5 sm:p-6" data-guide-target="recall-material">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-violet-300">MATERIAL → CANDIDATE</p>
                    <h2 class="mt-1 text-lg font-black text-slate-50">教材からRecall候補を作る</h2>
                    <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-500">参考書の写真・スクリーンショット・PDF・コピーした本文をNative AIで読み取り、まずCandidateとして隔離します。確認するまでDeckには入りません。</p>
                </div>
                @if (! $canGenerateRecallCandidates)
                    <span class="badge badge-slate">Native AI利用時のみ</span>
                @endif
            </div>

            @if ($canGenerateRecallCandidates)
                <form method="POST" action="{{ route('plans.tasks.study_recall.candidates.extract', [$plan, $task]) }}" enctype="multipart/form-data" class="mt-4 grid gap-4 lg:grid-cols-2" data-mutation-once>
                    @csrf
                    <div class="rounded-xl border border-white/8 bg-slate-950/20 p-4">
                        <label class="text-xs font-bold text-slate-300" for="recall-source-file">画像 / PDF</label>
                        <input id="recall-source-file" type="file" name="source_file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" class="input-field mt-2 w-full">
                        <p class="mt-2 text-[10px] leading-4 text-slate-600">最大10MB。参考書は必要なページだけ撮影・PDF化すると、抽出精度とコストの両方を抑えられます。</p>
                    </div>
                    <div class="rounded-xl border border-white/8 bg-slate-950/20 p-4">
                        <label class="text-xs font-bold text-slate-300" for="recall-source-text">または本文を貼り付け</label>
                        <textarea id="recall-source-text" name="source_text" rows="5" class="input-field mt-2 w-full" placeholder="教材の本文・単語一覧・用語解説など">{{ old('source_text') }}</textarea>
                    </div>
                    @error('source_file')
                        <p class="text-xs text-rose-300 lg:col-span-2">{{ $message }}</p>
                    @enderror
                    @error('source_text')
                        <p class="text-xs text-rose-300 lg:col-span-2">{{ $message }}</p>
                    @enderror
                    <div class="lg:col-span-2">
                        <button type="submit" class="btn-primary">候補を抽出</button>
                    </div>
                </form>

                <details
                    class="mt-4 rounded-xl border border-violet-300/15 bg-violet-300/[0.025] p-4"
                    data-recall-batch-ingest
                >
                    <summary class="cursor-pointer text-xs font-black text-violet-100">
                        複数ページをまとめて取り込む
                    </summary>
                    <div class="mt-3 border-t border-white/8 pt-3">
                        <p class="text-xs leading-5 text-slate-500">
                            2〜5個の画像 / PDFを1回の解析へまとめます。ページをまたぐ同じ論点も重複を抑えてCandidate化します。
                        </p>
                        <form
                            method="POST"
                            action="{{ route('plans.tasks.study_recall.candidates.extract_batch', [$plan, $task]) }}"
                            enctype="multipart/form-data"
                            class="mt-3"
                            data-recall-batch-form
                            data-mutation-once
                        >
                            @csrf
                            <label class="text-xs font-bold text-slate-300" for="recall-source-files">
                                画像 / PDFを2〜5個選択
                            </label>
                            <input
                                id="recall-source-files"
                                type="file"
                                name="source_files[]"
                                multiple
                                required
                                accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
                                class="input-field mt-2 w-full"
                            >
                            <p class="mt-2 text-[10px] leading-4 text-slate-600">
                                1ファイル最大10MB・合計20MBまで。1回のNative AI実行でまとめて読み取ります。
                            </p>
                            @error('source_files')
                                <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                            @enderror
                            @error('source_files.*')
                                <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                            @enderror
                            <button type="submit" class="btn-secondary mt-3">
                                まとめて候補を抽出
                            </button>
                        </form>
                    </div>
                </details>
            @else
                <p class="mt-4 text-sm leading-6 text-slate-400">Recall自体は手動カードで利用できます。教材からの自動抽出はAutomatic AI Executionが利用できる場合に表示されます。</p>
            @endif

            @if ($recallSources->isNotEmpty())
                <details class="mt-4 rounded-xl border border-white/8 bg-white/[0.02] p-3">
                    <summary class="cursor-pointer text-xs font-bold text-slate-300">最近取り込んだ教材</summary>
                    <div class="mt-3 grid gap-2">
                        @foreach ($recallSources as $source)
                            <div
                                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-white/6 bg-slate-950/20 px-3 py-2"
                                data-recall-source-row="{{ $source->id }}"
                                data-recall-source-status="{{ $source->status }}"
                            >
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-xs font-bold text-slate-200">{{ $source->original_name ?: $source->sourceLabel() }}</p>
                                        @if ($source->status === 'failed')
                                            <span class="badge badge-slate">抽出失敗</span>
                                        @elseif ($source->status === 'ready')
                                            <span class="badge badge-slate">抽出済み</span>
                                        @else
                                            <span class="badge badge-slate">処理中</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-[10px] text-slate-600">{{ $source->sourceLabel() }} · 新規候補 {{ (int) $source->candidate_count }}件</p>
                                    @if ($source->status === 'failed')
                                        <p class="mt-1 text-[10px] leading-4 text-amber-200/70">
                                            教材は保存済みです。利用条件を満たせば同じ教材から再抽出できます。
                                        </p>
                                    @endif
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($source->storage_path)
                                        <a href="{{ route('plans.tasks.study_recall.sources.file', [$plan, $task, $source]) }}" target="_blank" class="text-xs font-bold text-violet-200 hover:text-violet-100">元教材を確認</a>
                                    @endif

                                    @if (
                                        $source->status === 'failed'
                                        && $source->hasStoredMaterial()
                                        && $canGenerateRecallCandidates
                                    )
                                        <form
                                            method="POST"
                                            action="{{ route('plans.tasks.study_recall.sources.retry', [$plan, $task, $source]) }}"
                                            data-recall-source-retry-form="{{ $source->id }}"
                                            data-mutation-once
                                        >
                                            @csrf
                                            <button type="submit" class="btn-secondary px-3 py-2 text-xs">再抽出</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif
        </section>

        @php
            $candidateOutcomeAggregate = (array) data_get(
                $candidateOutcomes ?? [],
                'aggregate',
                [],
            );
            $candidateOutcomeRows = (array) data_get(
                $candidateOutcomes ?? [],
                'recent_candidates',
                [],
            );
            $candidateOutcomeLabels = [
                'unobserved' => '未観測',
                'developing' => '学習中',
                'needs_reinforcement' => '要補強',
                'retained' => '定着',
            ];
        @endphp

        @if ((bool) data_get($candidateOutcomes ?? [], 'has_lineage', false))
            <details
                class="page-card border-cyan-300/15 p-4 sm:p-5"
                data-recall-candidate-outcome
            >
                <summary class="cursor-pointer list-none">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">
                                CANDIDATE OUTCOME
                            </p>
                            <h2 class="mt-1 text-sm font-black text-slate-100">
                                AI候補の実Recall結果
                            </h2>
                        </div>
                        <span class="badge badge-slate">
                            観測 {{ (int) ($candidateOutcomeAggregate['observed_item_count'] ?? 0) }}
                            / {{ (int) ($candidateOutcomeAggregate['promoted_item_count'] ?? 0) }} cards
                        </span>
                    </div>
                </summary>

                <div class="mt-4 border-t border-white/8 pt-4">
                    <p class="max-w-3xl text-xs leading-5 text-slate-500">
                        AIが生成時に付けたconfidenceと、その後のAgain / Hard / Good / Easyを別軸で観測します。
                        覚えにくさは内容自体の難しさも含むため、この結果だけでCandidate品質を自動判定・再採点はしません。
                    </p>

                    <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-5">
                        @foreach ([
                            [
                                'label' => 'AI候補',
                                'value' => (int) ($candidateOutcomeAggregate['promoted_candidate_count'] ?? 0),
                            ],
                            [
                                'label' => '実カード',
                                'value' => (int) ($candidateOutcomeAggregate['promoted_item_count'] ?? 0),
                            ],
                            [
                                'label' => '定着',
                                'value' => (int) ($candidateOutcomeAggregate['retained_item_count'] ?? 0),
                            ],
                            [
                                'label' => 'Recall確認',
                                'value' => (int) ($candidateOutcomeAggregate['review_count'] ?? 0),
                            ],
                            [
                                'label' => '想起成功',
                                'value' => ($candidateOutcomeAggregate['self_rated_recall_success_percent'] ?? null) !== null
                                    ? ((int) $candidateOutcomeAggregate['self_rated_recall_success_percent']).'%'
                                    : '—',
                            ],
                        ] as $stat)
                            <div class="rounded-xl border border-white/8 bg-slate-950/25 p-3 text-center">
                                <p class="text-[10px] text-slate-500">{{ $stat['label'] }}</p>
                                <strong class="mt-1 block text-lg text-slate-100">{{ $stat['value'] }}</strong>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-slate-600">
                        <span>
                            元AI confidence平均
                            {{ ($candidateOutcomeAggregate['average_candidate_confidence'] ?? null) !== null
                                ? ((int) $candidateOutcomeAggregate['average_candidate_confidence']).'/100'
                                : '—' }}
                        </span>
                        <span>
                            要補強
                            {{ (int) ($candidateOutcomeAggregate['reinforcement_item_count'] ?? 0) }} cards
                        </span>
                        <span>
                            Hard / Good / Easyを想起成功として集計
                        </span>
                    </div>

                    @if ($candidateOutcomeRows !== [])
                        <div class="mt-4 grid gap-2">
                            @foreach ($candidateOutcomeRows as $row)
                                <div
                                    class="rounded-xl border border-white/8 bg-slate-950/20 px-3 py-3"
                                    data-recall-candidate-outcome-row="{{ (int) ($row['candidate_id'] ?? 0) }}"
                                    data-recall-candidate-outcome-state="{{ $row['outcome_state'] ?? 'unobserved' }}"
                                >
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="truncate text-xs font-bold text-slate-200">
                                                {{ $row['prompt'] ?? 'Recall Candidate' }}
                                            </p>
                                            <p class="mt-1 text-[10px] text-slate-600">
                                                {{ $row['source_name'] ?? '教材' }}
                                                · confidence {{ (int) ($row['confidence'] ?? 0) }}/100
                                            </p>
                                        </div>
                                        <span class="badge badge-slate">
                                            {{ $candidateOutcomeLabels[$row['outcome_state'] ?? 'unobserved'] ?? '観測中' }}
                                        </span>
                                    </div>

                                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-slate-500">
                                        <span>Review {{ (int) ($row['review_count'] ?? 0) }}</span>
                                        <span>Again {{ (int) data_get($row, 'rating_counts.again', 0) }}</span>
                                        <span>
                                            想起成功
                                            {{ ($row['self_rated_recall_success_percent'] ?? null) !== null
                                                ? ((int) $row['self_rated_recall_success_percent']).'%'
                                                : '—' }}
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </details>
        @endif

        @if ($recallCandidates->isNotEmpty())
            <section class="page-card border-amber-300/20 p-5 sm:p-6">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-amber-300">CANDIDATE REVIEW</p>
                    <h2 class="mt-1 text-lg font-black text-slate-50">Deckへ入れる前に確認</h2>
                    <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-500">AI抽出結果は正解扱いしません。表・裏を必要なら修正し、採用する候補だけチェックしてください。</p>
                </div>

                @error('candidates')
                    <p class="mt-3 text-xs text-rose-300">{{ $message }}</p>
                @enderror

                <form method="POST" action="{{ route('plans.tasks.study_recall.candidates.review', [$plan, $task]) }}" class="mt-4 space-y-3" data-mutation-once>
                    @csrf
                    @foreach ($recallCandidates as $candidate)
                        <div class="rounded-2xl border border-white/8 bg-white/[0.025] p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <label class="flex items-center gap-2 text-xs font-bold text-slate-200">
                                    <input type="checkbox" name="candidates[{{ $candidate->id }}][selected]" value="1" checked>
                                    Candidate #{{ $candidate->id }}
                                </label>
                                <div class="flex flex-wrap gap-2">
                                    <span class="badge badge-slate">根拠 {{ (int) $candidate->confidence }}/100</span>
                                    <span class="badge badge-slate">{{ $candidate->source?->sourceLabel() ?? '教材' }}</span>
                                </div>
                            </div>

                            @if ($candidate->source_excerpt)
                                <blockquote class="mt-3 rounded-xl border border-violet-300/10 bg-violet-300/[0.025] px-3 py-2 text-[11px] leading-5 text-slate-400">
                                    根拠: {{ $candidate->source_excerpt }}
                                </blockquote>
                            @endif

                            <div class="mt-3 grid gap-3 lg:grid-cols-2">
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">FRONT</label>
                                    <textarea name="candidates[{{ $candidate->id }}][prompt]" rows="3" class="input-field mt-1 w-full">{{ $candidate->prompt }}</textarea>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">BACK</label>
                                    <textarea name="candidates[{{ $candidate->id }}][answer]" rows="3" class="input-field mt-1 w-full">{{ $candidate->answer }}</textarea>
                                </div>
                            </div>
                            <div class="mt-3">
                                <label class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">NOTE</label>
                                <textarea name="candidates[{{ $candidate->id }}][note]" rows="2" class="input-field mt-1 w-full">{{ $candidate->note }}</textarea>
                            </div>
                        </div>
                    @endforeach

                    <div class="flex flex-wrap gap-2">
                        <button type="submit" name="decision" value="promote" class="btn-primary">選択した候補をDeckへ追加</button>
                        <button type="submit" name="decision" value="reject" class="btn-secondary">選択した候補を見送る</button>
                    </div>
                </form>
            </section>
        @endif

        <section class="page-card p-5 sm:p-6">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-violet-300">ADD CARDS</p>
                <h2 class="mt-1 text-lg font-black text-slate-50">Recallカードを追加</h2>
                <p class="mt-2 text-xs leading-5 text-slate-500">1行につき「表 | 裏」。タブ区切りも使えます。一度に100件まで。</p>
            </div>
            <form method="POST" action="{{ route('plans.tasks.study_recall.items.store', [$plan, $task]) }}" class="mt-4">
                @csrf
                <textarea name="cards_text" rows="6" class="input-field w-full" placeholder="abandon | 放棄する&#10;accurate | 正確な&#10;maintain | 維持する">{{ old('cards_text') }}</textarea>
                @error('cards_text')
                    <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                @enderror
                <button type="submit" class="btn-primary mt-3">カードを追加</button>
            </form>
        </section>

        @if ($items->isNotEmpty())
            <details class="page-card p-4 sm:p-5">
                <summary class="cursor-pointer text-sm font-black text-slate-200">カード一覧・管理</summary>
                <div class="mt-4 grid gap-2">
                    @foreach ($items as $item)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-white/8 bg-white/[0.025] p-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-slate-100">{{ $item->prompt }}</p>
                                <p class="mt-1 truncate text-xs text-slate-500">{{ $item->answer }}</p>
                                <p class="mt-1 text-[10px] text-slate-600">
                                    {{ $item->isMastered() ? '定着候補' : ($item->repetitions === 0 ? '未学習' : '学習中') }}
                                    · 次回 {{ $item->due_at ? $item->due_at->diffForHumans() : '今' }}
                                </p>
                            </div>
                            <form method="POST" action="{{ route('plans.tasks.study_recall.items.destroy', [$plan, $task, $item]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-secondary px-3 py-2 text-xs">削除</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif

        @if ($recentReviews->isNotEmpty())
            <details class="page-card p-4 sm:p-5">
                <summary class="cursor-pointer text-sm font-black text-slate-200">最近のRecall履歴</summary>
                <div class="mt-4 space-y-2">
                    @foreach ($recentReviews as $review)
                        <div class="rounded-xl border border-white/8 bg-white/[0.02] px-3 py-2 text-xs text-slate-400">
                            <span class="font-bold text-slate-200">{{ $review->item?->prompt ?? '削除済みカード' }}</span>
                            · {{ $ratingLabels[$review->rating]['label'] ?? $review->rating }}
                            · {{ $review->interval_after_days > 0 ? $review->interval_after_days.'日後' : '短時間後' }}
                            · {{ $review->reviewed_at?->diffForHumans() }}
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    </div>
@endsection
