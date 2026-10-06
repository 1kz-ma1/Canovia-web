@extends('layouts.app')

@section('title', 'Resource Study | Canovia')

@section('content')
    @php
        $outcomeLabels = [
            'needs_review' => [
                'label' => 'まだ理解が浅い',
                'hint' => 'もう一度読み直す・別解説も確認したい',
            ],
            'partial' => [
                'label' => '一部理解できた',
                'hint' => '要点は分かったが、曖昧な箇所が残っている',
            ],
            'covered' => [
                'label' => '必要範囲を確認できた',
                'hint' => 'このTaskで必要な内容を一通り確認できた',
            ],
        ];
    @endphp

    <div class="mx-auto max-w-5xl space-y-5">
        <section class="page-card border-violet-300/20 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-violet-300">
                        STUDY ACTIVITY / RESOURCE
                    </p>
                    <h1 class="mt-2 text-2xl font-black text-slate-50">
                        {{ $definition['icon'] ?? '⌘' }}
                        {{ $definition['label'] ?? 'Resource Study' }}
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
                    Resourceを開いただけでは学習完了として扱いません。
                    教材を確認したあと、下のフォームから今回の実施結果を明示的に記録した時だけEvidenceが残ります。
                    そのEvidenceだけでTask進捗や完了は変更しません。
                </p>
            </div>
        </section>

        @if (session('success'))
            <div class="rounded-2xl border border-emerald-300/20 bg-emerald-300/[0.06] px-4 py-3 text-sm text-emerald-100">
                {{ session('success') }}
            </div>
        @endif

        <section class="page-card p-5 sm:p-6">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-sky-300">
                EXECUTION GUIDE
            </p>
            <h2 class="mt-1 text-lg font-black text-slate-50">
                教材を読む前に目的を絞る
            </h2>

            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ([
                    [
                        'title' => '確認したいことを1つ決める',
                        'description' => 'Task全体を漫然と読むのではなく、理解したい論点・用語・仕組みを先に決めます。',
                    ],
                    [
                        'title' => '必要箇所だけ読む',
                        'description' => '教材全体を最初から読み切ることより、今の弱点に関係する章・節・解説へ絞ります。',
                    ],
                    [
                        'title' => '自分の言葉で確認する',
                        'description' => '読み終えたら、重要点を見ずに説明できるか確認します。必要ならRecallや問題演習へつなげます。',
                    ],
                    [
                        'title' => '曖昧さを記録する',
                        'description' => '理解できたかを完璧に判定せず、「まだ浅い / 一部理解 / 必要範囲を確認」の感触を残します。',
                    ],
                ] as $index => $step)
                    <div class="rounded-xl border border-white/8 bg-slate-950/25 p-4">
                        <div class="flex items-start gap-3">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-violet-300/20 bg-violet-300/[0.05] text-xs font-black text-violet-200">
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
                        今回使う教材
                    </h2>
                </div>
                <a href="{{ route('plans.resources.index', $plan) }}" class="btn-secondary">
                    教材を管理
                </a>
            </div>

            @if ($resources->isNotEmpty())
                <div class="mt-4 grid gap-2">
                    @foreach ($resources as $resource)
                        <a
                            href="{{ $resource->url }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="rounded-xl border border-white/8 bg-white/[0.025] p-3 transition hover:border-violet-300/25"
                            data-resource-study-link="{{ $resource->id }}"
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
                    Canoviaは教材URLの内容をサーバー側で取得・解析しません。
                </p>
            @else
                <div class="mt-4 rounded-xl border border-white/8 bg-slate-950/20 p-4">
                    <p class="text-sm font-bold text-slate-200">
                        このTaskで使える登録済み教材がありません
                    </p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">
                        Taskへ教材を紐づけるか、Task未割当のPlan Resourceを登録するとここから開けます。
                    </p>
                    <a
                        href="{{ route('plans.resources.index', $plan) }}"
                        class="btn-secondary mt-3"
                    >
                        教材を登録・整理
                    </a>
                </div>
            @endif
        </section>

        <section class="page-card border-emerald-300/15 p-5 sm:p-6">
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-300">
                RECORD OUTCOME
            </p>
            <h2 class="mt-1 text-lg font-black text-slate-50">
                教材学習の実施結果を残す
            </h2>
            <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-500">
                これは「教材学習を実施した」という自己申告Evidenceです。
                理解度の客観採点やTask完了判定ではありません。
            </p>

            <form
                method="POST"
                action="{{ route('plans.tasks.study_resource.store', [$plan, $task]) }}"
                class="mt-4 space-y-4"
                data-mutation-once
            >
                @csrf
                <input type="hidden" name="request_uuid" value="{{ $requestUuid }}">

                @if ($resources->isNotEmpty())
                    <div>
                        <label for="resource-study-resource" class="text-xs font-bold text-slate-300">
                            今回使った登録済み教材（任意）
                        </label>
                        <select
                            id="resource-study-resource"
                            name="resource_id"
                            class="input-field mt-2 w-full"
                        >
                            <option value="">指定しない</option>
                            @foreach ($resources as $resource)
                                <option
                                    value="{{ $resource->id }}"
                                    @selected((string) old('resource_id') === (string) $resource->id)
                                >
                                    {{ $resource->title }} · {{ $resource->providerLabel() }}
                                </option>
                            @endforeach
                        </select>
                        @error('resource_id')
                            <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                <div>
                    <p class="text-xs font-bold text-slate-300">
                        今回の理解感触
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
                    <label for="resource-study-reflection" class="text-xs font-bold text-slate-300">
                        メモ（任意）
                    </label>
                    <textarea
                        id="resource-study-reflection"
                        name="reflection"
                        rows="4"
                        maxlength="2000"
                        class="input-field mt-2 w-full"
                        placeholder="例: DNSのMX/CNAMEの違いは理解できたが、NSの委任がまだ曖昧"
                    >{{ old('reflection') }}</textarea>
                    @error('reflection')
                        <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="btn-primary">
                    教材学習を記録
                </button>
            </form>
        </section>

        @if ($recentEvidence->isNotEmpty())
            <section class="page-card p-5 sm:p-6">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">
                    RECENT EVIDENCE
                </p>
                <h2 class="mt-1 text-lg font-black text-slate-50">
                    最近のResource Study
                </h2>

                <div class="mt-4 grid gap-2">
                    @foreach ($recentEvidence as $evidence)
                        <div class="rounded-xl border border-white/8 bg-slate-950/20 p-3">
                            <p class="text-xs font-bold text-slate-200">
                                {{ $evidence->summary() }}
                            </p>
                            <p class="mt-1 text-[10px] text-slate-600">
                                {{ $evidence->occurred_at?->format('Y/m/d H:i') }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
