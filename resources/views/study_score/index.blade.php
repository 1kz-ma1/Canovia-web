@extends('layouts.app')

@section('title', '学習スコア | Canovia')

@section('content')
@php
    $target = data_get($learningType, 'target_score');
    $unitLabel = ($profile['unit'] ?? 'score') === 'band'
        ? ''
        : '点';
@endphp

<div class="mx-auto max-w-5xl space-y-5" data-study-score-page>
    <section class="page-card border-cyan-300/20 p-5 sm:p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">SCORE / BASELINE EVIDENCE</p>
                <h1 class="mt-2 text-2xl font-black text-slate-50">現在スコアを記録</h1>
                <p class="mt-2 text-sm text-slate-400">{{ $plan->title }}</p>
                <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300">
                    ここで保存するスコアは、Canovia演習の正答率とは別の尺度です。
                    演習84%をTOEICやIELTSの点数へ自動換算することはありません。
                </p>
            </div>
            <a href="{{ route('workspace.study.index', ['plan_id' => $plan->id]) }}" class="btn-secondary">Study Workspaceへ</a>
        </div>

        <div class="mt-5 grid gap-3 sm:grid-cols-3">
            <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">SCALE</p>
                <p class="mt-2 text-xl font-black text-slate-100">{{ $profile['label'] ?? '外部スコア' }}</p>
                <p class="mt-1 text-xs text-slate-500">
                    @if (($profile['bounded'] ?? false) === true)
                        {{ $profile['min'] }}〜{{ $profile['max'] }}
                    @else
                        報告された尺度を保持
                    @endif
                </p>
            </div>
            <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">CURRENT</p>
                <p class="mt-2 text-xl font-black text-slate-100">{{ $latestObservation?->displayValue() ?? '—' }}</p>
                <p class="mt-1 text-xs text-slate-500">最新の外部スコア</p>
            </div>
            <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">TARGET</p>
                <p class="mt-2 text-xl font-black text-slate-100">{{ $target !== null ? $target.$unitLabel : '—' }}</p>
                <p class="mt-1 text-xs text-slate-500">Planから読み取った目標</p>
            </div>
        </div>
    </section>

    @if (session('success'))
        <div class="rounded-2xl border border-emerald-300/20 bg-emerald-300/[0.06] px-4 py-3 text-sm text-emerald-100">{{ session('success') }}</div>
        @if ($latestObservation)
            <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-cyan-300/20 bg-cyan-300/[0.04] px-4 py-3" data-study-score-next-action>
                <p class="flex-1 text-sm text-slate-200">得点を記録しました。学習Workspaceで最新のEvidenceに基づく次の行動を確認できます。</p>
                <a href="{{ route('workspace.study.index', ['plan_id' => $plan->id]) }}" class="btn-primary min-h-11 px-4">次の学習行動を確認する</a>
            </div>
        @endif
    @endif
    @if (session('status'))
        <div class="rounded-2xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">{{ session('status') }}</div>
    @endif

    @if ($canEdit)
        <section class="page-card p-5 sm:p-6">
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-emerald-300">ADD OBSERVATION</p>
            <h2 class="mt-1 text-lg font-black text-slate-50">スコアEvidenceを追加</h2>

            <form method="POST" action="{{ route('plans.study_scores.store', $plan) }}" class="mt-5 space-y-5" data-mutation-once>
                @csrf
                <input type="hidden" name="request_id" value="{{ $captureRequestId }}">

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="score-value" class="text-xs font-bold text-slate-300">{{ $profile['label'] ?? 'スコア' }}</label>
                        <input
                            id="score-value"
                            type="number"
                            name="score_value"
                            value="{{ old('score_value') }}"
                            step="{{ $profile['step'] ?? 0.1 }}"
                            @if (($profile['bounded'] ?? false) === true)
                                min="{{ $profile['min'] }}"
                                max="{{ $profile['max'] }}"
                            @endif
                            required
                            class="input-field mt-2 w-full"
                        >
                        @error('score_value')
                            <p class="mt-1 text-xs text-rose-300">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="observed-at" class="text-xs font-bold text-slate-300">観測日</label>
                        <input id="observed-at" type="date" name="observed_at" value="{{ old('observed_at', today()->toDateString()) }}" max="{{ today()->toDateString() }}" required class="input-field mt-2 w-full">
                        @error('observed_at')
                            <p class="mt-1 text-xs text-rose-300">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="source-kind" class="text-xs font-bold text-slate-300">出所</label>
                        <select id="source-kind" name="source_kind" class="input-field mt-2 w-full" required>
                            @foreach ($scoreSources as $key => $label)
                                <option value="{{ $key }}" @selected(old('source_kind', 'self_reported') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="source-label" class="text-xs font-bold text-slate-300">出所メモ <span class="text-slate-600">任意</span></label>
                        <input id="source-label" type="text" name="source_label" value="{{ old('source_label') }}" maxlength="120" class="input-field mt-2 w-full" placeholder="例: 公式結果票 / 模試A">
                    </div>
                </div>

                @if (! empty($profile['components']))
                    <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4">
                        <p class="text-xs font-black text-slate-200">内訳 <span class="font-normal text-slate-500">任意</span></p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            @foreach ($profile['components'] as $component)
                                <div>
                                    <label class="text-xs text-slate-400" for="component-{{ $component['key'] }}">{{ $component['label'] }}</label>
                                    <input
                                        id="component-{{ $component['key'] }}"
                                        type="number"
                                        name="components[{{ $component['key'] }}]"
                                        value="{{ old('components.'.$component['key']) }}"
                                        min="{{ $component['min'] }}"
                                        max="{{ $component['max'] }}"
                                        step="{{ $component['step'] }}"
                                        class="input-field mt-1 w-full"
                                    >
                                    @error('components.'.$component['key'])
                                        <p class="mt-1 text-xs text-rose-300">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <button type="submit" class="btn-primary">スコアを記録</button>
            </form>
        </section>
    @endif

    <section class="page-card p-5 sm:p-6">
        <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">HISTORY</p>
        <h2 class="mt-1 text-lg font-black text-slate-50">スコア履歴</h2>

        <div class="mt-4 space-y-3">
            @forelse ($observations as $observation)
                <div class="rounded-2xl border border-white/8 bg-slate-950/25 p-4" data-study-score-observation="{{ $observation->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xl font-black text-slate-100">{{ $observation->displayValue() }}</span>
                                <span class="badge badge-slate">{{ $observation->metric_label }}</span>
                                <span class="badge badge-slate">{{ $observation->sourceLabel() }}</span>
                            </div>
                            <p class="mt-2 text-xs text-slate-500">{{ $observation->observed_at?->format('Y/m/d') }}@if($observation->source_label) · {{ $observation->source_label }}@endif</p>

                            @if (! empty($observation->components))
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($observation->components as $component)
                                        <span class="badge badge-slate">{{ $component['label'] ?? $component['key'] }} {{ $component['value'] }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        @if ($canEdit)
                            <form method="POST" action="{{ route('plans.study_scores.destroy', [$plan, $observation]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-secondary px-3 py-2 text-xs">削除</button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-slate-500">まだ外部スコアEvidenceはありません。</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
