@extends('layouts.app')

@section('title', ($definition['label'] ?? 'Language Practice').' | Canovia')

@section('content')
    @php
        $outcomeLabels = [
            'struggled' => [
                'label' => 'かなり難しかった',
                'hint' => '聞き取れない / 追えない箇所が多かった',
            ],
            'partial' => [
                'label' => '一部できた',
                'hint' => '難所はあるが、ある程度はできた',
            ],
            'comfortable' => [
                'label' => '安定してできた',
                'hint' => '大きく詰まらず実施できた',
            ],
        ];
    @endphp

    <div class="mx-auto max-w-5xl space-y-5">
        <section class="page-card border-sky-300/20 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-sky-300">
                        STUDY ACTIVITY / LANGUAGE
                    </p>
                    <h1 class="mt-2 text-2xl font-black text-slate-50">
                        {{ $definition['icon'] ?? '◌' }}
                        {{ $definition['label'] ?? 'Language Practice' }}
                    </h1>
                    <p class="mt-2 text-sm text-slate-400">
                        {{ $plan->displayIcon() }} {{ $plan->title }} / {{ $task->title }}
                    </p>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300">
                        {{ $definition['description'] ?? '' }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a
                        href="{{ route('plans.tasks.study_activity.show', [$plan, $task]) }}"
                        class="btn-secondary"
                    >
                        学習方法へ
                    </a>
                    <a href="{{ route('plans.show', $plan) }}" class="btn-secondary">
                        Planへ戻る
                    </a>
                </div>
            </div>

            <div class="mt-5 rounded-xl border border-white/8 bg-slate-950/25 px-4 py-3">
                <p class="text-xs leading-5 text-slate-400">
                    Canoviaはこの画面で音声を録音・自動採点しません。
                    教材を使ってActivityを実施し、最後に自分の感触をEvidenceとして残します。
                    この自己評価だけでTask進捗は上がりません。
                </p>
            </div>
        </section>

        @if (session('success'))
            <div class="rounded-2xl border border-emerald-300/20 bg-emerald-300/[0.06] px-4 py-3 text-sm text-emerald-100">
                {{ session('success') }}
            </div>
        @endif

        <section class="page-card p-5 sm:p-6">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-violet-300">
                EXECUTION GUIDE
            </p>
            <h2 class="mt-1 text-lg font-black text-slate-50">
                この順番で進める
            </h2>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ($guidance as $index => $step)
                    <div class="rounded-xl border border-white/8 bg-slate-950/25 p-4">
                        <div class="flex items-start gap-3">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-sky-300/20 bg-sky-300/[0.05] text-xs font-black text-sky-200">
                                {{ $index + 1 }}
                            </span>
                            <div>
                                <p class="text-sm font-black text-slate-100">
                                    {{ $step['title'] }}
                                </p>
                                <p class="mt-1 text-xs leading-5 text-slate-400">
                                    {{ $step['description'] }}
                                </p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="page-card p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-violet-300">
                        MATERIAL
                    </p>
                    <h2 class="mt-1 text-lg font-black text-slate-50">
                        使う教材
                    </h2>
                </div>
                <a href="{{ route('plans.resources.index', $plan) }}" class="btn-secondary">
                    教材を管理
                </a>
            </div>

            @if ($resources->isNotEmpty())
                <div class="mt-4 grid gap-2">
                    @foreach ($resources->take(8) as $resource)
                        <a
                            href="{{ $resource->url }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="rounded-xl border border-white/8 bg-white/[0.025] p-3 transition hover:border-violet-300/25"
                        >
                            <span class="text-sm font-bold text-slate-100">
                                {{ $resource->title }}
                            </span>
                            <span class="ml-2 text-[10px] text-slate-500">
                                {{ $resource->providerLabel() }}
                            </span>
                        </a>
                    @endforeach
                </div>
                <p class="mt-3 text-[10px] leading-4 text-slate-600">
                    Canoviaは教材URLの中身をサーバー側で取得しません。リンク先をブラウザで開いて使います。
                </p>
            @else
                <div class="mt-4 rounded-xl border border-white/8 bg-slate-950/20 p-4">
                    <p class="text-sm font-bold text-slate-200">
                        まだ教材が登録されていません
                    </p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">
                        音声・動画・transcriptなどの教材URLをPlan Resourceへ登録すると、この画面からすぐ開けます。
                    </p>
                    <a
                        href="{{ route('plans.resources.index', $plan) }}"
                        class="btn-secondary mt-3"
                    >
                        教材を登録
                    </a>
                </div>
            @endif
        </section>

        <section class="page-card border-emerald-300/15 p-5 sm:p-6">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-300">
                RECORD OUTCOME
            </p>
            <h2 class="mt-1 text-lg font-black text-slate-50">
                実施結果を残す
            </h2>
            <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-500">
                これは自己評価Evidenceです。Question Practiceの採点結果とは別物として保存され、
                Task進捗や完了を自動変更しません。
            </p>

            <form
                method="POST"
                action="{{ route('plans.tasks.study_language.store', [
                    $plan,
                    $task,
                    'activity' => $activityKey,
                ]) }}"
                class="mt-4 space-y-4"
                data-mutation-once
            >
                @csrf
                <input type="hidden" name="request_uuid" value="{{ $requestUuid }}">

                <div>
                    <label for="language-rounds" class="text-xs font-bold text-slate-300">
                        何周・何セット行ったか
                    </label>
                    <input
                        id="language-rounds"
                        type="number"
                        name="rounds"
                        min="1"
                        max="20"
                        value="{{ old('rounds', 3) }}"
                        class="input-field mt-2 w-full max-w-40"
                    >
                    @error('rounds')
                        <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <p class="text-xs font-bold text-slate-300">
                        今回の感触
                    </p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-3">
                        @foreach ($outcomeLabels as $key => $meta)
                            <label class="cursor-pointer rounded-xl border border-white/8 bg-slate-950/25 p-3">
                                <input
                                    type="radio"
                                    name="outcome_rating"
                                    value="{{ $key }}"
                                    class="mr-2"
                                    @checked(old('outcome_rating', 'partial') === $key)
                                >
                                <span class="text-sm font-bold text-slate-100">
                                    {{ $meta['label'] }}
                                </span>
                                <span class="mt-1 block text-[10px] leading-4 text-slate-500">
                                    {{ $meta['hint'] }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('outcome_rating')
                        <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="language-reflection" class="text-xs font-bold text-slate-300">
                        気づいたこと（任意）
                    </label>
                    <textarea
                        id="language-reflection"
                        name="reflection"
                        rows="4"
                        class="input-field mt-2 w-full"
                        placeholder="聞き取れなかった表現、音のつながり、次に重点的にやる箇所など"
                    >{{ old('reflection') }}</textarea>
                    @error('reflection')
                        <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="btn-primary">
                    実施結果を記録
                </button>
            </form>
        </section>

        @if ($recentEvidence->isNotEmpty())
            <details class="page-card p-4 sm:p-5">
                <summary class="cursor-pointer text-sm font-black text-slate-200">
                    最近の実施結果
                </summary>
                <div class="mt-3 grid gap-2">
                    @foreach ($recentEvidence as $evidence)
                        @php
                            $rating = (string) data_get($evidence->metadata, 'outcome_rating', '');
                            $meta = $outcomeLabels[$rating] ?? [
                                'label' => '記録済み',
                                'hint' => '',
                            ];
                        @endphp
                        <div class="rounded-xl border border-white/8 bg-slate-950/20 px-3 py-3">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <p class="text-xs font-bold text-slate-200">
                                    {{ $meta['label'] }}
                                </p>
                                <span class="text-[10px] text-slate-600">
                                    {{ $evidence->occurred_at?->format('Y/m/d H:i') }}
                                </span>
                            </div>
                            <p class="mt-1 text-[10px] text-slate-500">
                                {{ (int) data_get($evidence->metadata, 'rounds', 0) }}周 / セット
                            </p>
                            @if (filled(data_get($evidence->metadata, 'reflection')))
                                <p class="mt-2 text-xs leading-5 text-slate-400">
                                    {{ data_get($evidence->metadata, 'reflection') }}
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    </div>
@endsection
