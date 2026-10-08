@extends('layouts.app')

@section('title', 'Canoviaをあなた向けに調整 | Canovia')

@section('content')
@php
    $domains = collect((array) data_get($context, 'domains', []));
    $common = (array) data_get($context, 'common_context', []);
    $study = (array) data_get($context, 'domain_context.study', []);
    $development = (array) data_get($context, 'domain_context.development', []);
@endphp

<div class="mx-auto max-w-4xl space-y-5 pb-28 md:pb-10" data-personalization-bootstrap>
    <header class="relative overflow-hidden rounded-[1.7rem] border border-cyan-300/15 bg-slate-950/70 p-5 sm:p-7">
        <div class="max-w-2xl">
            <p class="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">PERSONALIZATION</p>
            <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-50 sm:text-3xl">
                最初から全部設定しなくて大丈夫。
            </h1>
            <p class="mt-3 text-sm leading-7 text-slate-400">
                今進めたいことと現在地を少しだけ教えてください。Canoviaが最初のPlanの型と、今必要そうな機能だけを用意します。
            </p>
            <div class="mt-4 flex flex-wrap gap-2 text-[10px] font-bold text-slate-500">
                <span class="rounded-full border border-slate-800 bg-slate-950/40 px-3 py-1.5">約1分</span>
                <span class="rounded-full border border-slate-800 bg-slate-950/40 px-3 py-1.5">複数選択OK</span>
                <span class="rounded-full border border-slate-800 bg-slate-950/40 px-3 py-1.5">あとで変更できます</span>
            </div>
        </div>
    </header>

    @if ($errors->any())
        <div class="assistant-notice assistant-notice-error">
            <p class="font-bold">入力内容を確認してください。</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('personalization.store') }}" class="space-y-5" data-personalization-form>
        @csrf

        <section class="page-card p-5 sm:p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">STEP 1</p>
                    <h2 class="mt-1 text-lg font-black text-slate-100">どんなことをCanoviaで進めたい？</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-500">今関係するものだけでOKです。複数選べます。</p>
                </div>
                <span class="rounded-full border border-slate-800 bg-slate-950/35 px-3 py-1 text-[10px] text-slate-500">必須</span>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                @foreach ([
                    ['key' => 'study', 'label' => '学習', 'detail' => '資格・試験・学校・自主学習', 'icon' => '📚'],
                    ['key' => 'development', 'label' => '開発', 'detail' => '新規開発・既存改善・運用', 'icon' => '🛠️'],
                    ['key' => 'unsure', 'label' => 'まだ分からない', 'detail' => 'まずCanoviaを試してみたい', 'icon' => '✨'],
                ] as $option)
                    <label class="cursor-pointer">
                        <input
                            type="checkbox"
                            name="domains[]"
                            value="{{ $option['key'] }}"
                            class="peer sr-only"
                            data-personalization-domain="{{ $option['key'] }}"
                            @checked(in_array($option['key'], old('domains', $domains->all()), true))
                        >
                        <span class="block h-full rounded-2xl border border-slate-800 bg-slate-950/30 p-4 transition peer-checked:border-cyan-300/35 peer-checked:bg-cyan-300/[0.07]">
                            <span class="text-xl" aria-hidden="true">{{ $option['icon'] }}</span>
                            <strong class="mt-2 block text-sm text-slate-100">{{ $option['label'] }}</strong>
                            <small class="mt-1 block text-xs leading-5 text-slate-500">{{ $option['detail'] }}</small>
                        </span>
                    </label>
                @endforeach
            </div>
        </section>

        <section class="page-card p-5 sm:p-6">
            <p class="text-[10px] font-black uppercase tracking-[.16em] text-sky-300">COMMON</p>
            <h2 class="mt-1 text-lg font-black text-slate-100">分かる範囲だけ</h2>

            <div class="mt-4 grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2">
                <label class="block min-w-0">
                    <span class="form-label">期限・試験日</span>
                    <input
                        type="date"
                        name="deadline"
                        value="{{ old('deadline', data_get($common, 'deadline')) }}"
                        class="form-control mt-2 block w-full min-w-0 max-w-full"
                        style="box-sizing: border-box;"
                    >
                    <span class="mt-1 block text-[11px] text-slate-500">未定なら空欄でOK。</span>
                </label>

                <label class="block min-w-0">
                    <span class="form-label">1週間に使えそうな時間</span>
                    <select name="weekly_capacity" class="form-control mt-2">
                        @foreach ([
                            'unknown' => 'まだ分からない',
                            'under_2' => '2時間未満',
                            '2_5' => '2〜5時間',
                            '5_10' => '5〜10時間',
                            '10_plus' => '10時間以上',
                        ] as $value => $label)
                            <option value="{{ $value }}" @selected(old('weekly_capacity', data_get($common, 'weekly_capacity', 'unknown')) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </section>

        <section
            class="page-card p-5 sm:p-6"
            data-personalization-domain-section="study"
            hidden
        >
            <p class="text-[10px] font-black uppercase tracking-[.16em] text-amber-300">STUDY</p>
            <h2 class="mt-1 text-lg font-black text-slate-100">学習について少しだけ</h2>

            <div class="mt-4 space-y-4">
                <label class="block min-w-0">
                    <span class="form-label">何を学びたい？ <span class="font-normal text-slate-600">任意</span></span>
                    <input
                        type="text"
                        name="study_goal"
                        value="{{ old('study_goal', data_get($study, 'goal')) }}"
                        placeholder="例：応用情報技術者試験に合格したい"
                        maxlength="255"
                        class="form-control mt-2"
                    >
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block min-w-0">
                        <span class="form-label">学習の目的</span>
                        <select name="study_kind" class="form-control mt-2">
                            @foreach ([
                                'qualification' => '資格・試験',
                                'school' => '学校・授業',
                                'self_study' => '自主学習',
                                'other' => 'その他 / まだ不明',
                            ] as $value => $label)
                                <option value="{{ $value }}" @selected(old('study_kind', data_get($study, 'kind', 'qualification')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block min-w-0">
                        <span class="form-label">今の状態</span>
                        <select name="study_stage" class="form-control mt-2">
                            @foreach ([
                                'not_started' => 'まだ始めていない',
                                'beginner' => '少し触れたところ',
                                'started' => 'すでに進めている',
                                'reviewing' => '演習・復習段階',
                            ] as $value => $label)
                                <option value="{{ $value }}" @selected(old('study_stage', data_get($study, 'stage', 'not_started')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </div>
        </section>

        <section
            class="page-card p-5 sm:p-6"
            data-personalization-domain-section="development"
            hidden
        >
            <p class="text-[10px] font-black uppercase tracking-[.16em] text-cyan-300">DEVELOPMENT</p>
            <h2 class="mt-1 text-lg font-black text-slate-100">開発について少しだけ</h2>

            <div class="mt-4 space-y-4">
                <label class="block min-w-0">
                    <span class="form-label">何を作る・改善する？ <span class="font-normal text-slate-600">任意</span></span>
                    <input
                        type="text"
                        name="development_goal"
                        value="{{ old('development_goal', data_get($development, 'goal')) }}"
                        placeholder="例：学習管理アプリのMVPを公開する"
                        maxlength="255"
                        class="form-control mt-2"
                    >
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block min-w-0">
                        <span class="form-label">開発経験</span>
                        <select name="development_experience" class="form-control mt-2">
                            @foreach ([
                                'beginner' => 'これから / 初心者',
                                'standard' => '開発経験あり',
                                'advanced' => '継続的に開発している',
                            ] as $value => $label)
                                <option value="{{ $value }}" @selected(old('development_experience', data_get($development, 'experience', 'standard')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block min-w-0">
                        <span class="form-label">今の開発段階</span>
                        <select name="development_stage" class="form-control mt-2">
                            @foreach ([
                                'new' => 'これから新しく作る',
                                'existing' => '既存プロダクトを改善中',
                                'operating' => 'すでに公開・運用中',
                            ] as $value => $label)
                                <option value="{{ $value }}" @selected(old('development_stage', data_get($development, 'stage', 'new')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block min-w-0">
                        <span class="form-label">GitHubを使っている？</span>
                        <select name="github_usage" class="form-control mt-2">
                            @foreach ([
                                'yes' => '使っている',
                                'no' => '使っていない',
                                'unsure' => 'よく分からない',
                            ] as $value => $label)
                                <option value="{{ $value }}" @selected(old('github_usage', data_get($development, 'github_usage', 'unsure')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block min-w-0">
                        <span class="form-label">対象Repositoryはある？</span>
                        <select name="repository_ready" class="form-control mt-2">
                            @foreach ([
                                'yes' => 'すでにある',
                                'no' => 'まだない',
                                'unsure' => 'よく分からない',
                            ] as $value => $label)
                                <option value="{{ $value }}" @selected(old('repository_ready', data_get($development, 'repository_ready', 'unsure')) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <p class="rounded-xl border border-slate-800 bg-slate-950/30 p-3 text-xs leading-5 text-slate-500">
                    GitHubは必須ではありません。準備ができている人にだけ、後で「使うと何が変わるか」を案内します。
                </p>
            </div>
        </section>

        <section class="sticky bottom-3 z-20 rounded-2xl border border-slate-800/90 bg-slate-950/95 p-3 shadow-2xl shadow-slate-950/60 backdrop-blur md:static md:bg-transparent md:p-0 md:shadow-none">
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                <button type="submit" class="btn-primary min-h-11 justify-center px-5">
                    おすすめを作る
                </button>
            </div>
        </section>
    </form>

    <form method="POST" action="{{ route('personalization.skip') }}" class="text-center">
        @csrf
        <button type="submit" class="min-h-10 px-4 text-xs font-bold text-slate-500 hover:text-slate-300">
            今はスキップしてCanoviaを使う
        </button>
    </form>
</div>

<script>
(() => {
    const inputs = Array.from(document.querySelectorAll('[data-personalization-domain]'));
    const sections = Array.from(document.querySelectorAll('[data-personalization-domain-section]'));

    const sync = () => {
        const selected = new Set(inputs.filter((input) => input.checked).map((input) => input.value));
        sections.forEach((section) => {
            section.hidden = !selected.has(section.dataset.personalizationDomainSection);
        });
    };

    inputs.forEach((input) => input.addEventListener('change', sync));
    sync();
})();
</script>
@endsection
