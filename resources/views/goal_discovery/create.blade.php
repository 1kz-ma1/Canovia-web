@extends('layouts.app')

@section('title', 'Canoviaと始める | Canovia')

@section('content')
    <div class="mx-auto max-w-4xl space-y-5 pb-28 md:pb-0">
        <header class="relative overflow-hidden rounded-[1.75rem] border border-violet-300/15 bg-[radial-gradient(circle_at_86%_12%,rgba(139,92,246,.16),transparent_30%),radial-gradient(circle_at_8%_100%,rgba(34,211,238,.12),transparent_34%),rgba(6,13,31,.82)] p-5 shadow-[0_24px_70px_rgba(2,6,23,.3)] sm:p-7">
            <div class="relative z-10 max-w-2xl">
                <p class="text-[10px] font-black uppercase tracking-[.18em] text-violet-300">FIRST COMPANION</p>
                <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-50 sm:text-3xl">まず、話すところから始めよう</h1>
                <p class="mt-3 max-w-xl text-sm leading-7 text-slate-400">
                    最初から計画を作る必要はありません。やりたいこと、困っていること、まだ曖昧なことでも大丈夫。
                    Canoviaが会話しながら現在地を理解し、最初のPlanと次の一歩につなげます。
                </p>
            </div>
            <div class="pointer-events-none absolute -bottom-10 -right-4 hidden w-44 opacity-80 sm:block" aria-hidden="true">
                <img src="/brand/mascot-guide.webp" alt="" class="w-full drop-shadow-[0_18px_30px_rgba(0,0,0,.45)]">
            </div>
        </header>

        @if ($errors->any())
            <div class="assistant-notice assistant-notice-error">
                <p class="font-bold">入力内容を確認してください。</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <section class="page-card p-4 sm:p-6" data-onboarding-target="plan-form" data-guide-target="goal-discovery-start">
            <div class="mx-auto max-w-2xl">
                <div class="mb-3"><span class="rounded-full border border-cyan-300/25 bg-cyan-300/10 px-2 py-1 text-[10px] font-black tracking-[.12em] text-cyan-300">最初はこれだけ</span></div>
                <div class="mr-auto max-w-[88%] rounded-2xl rounded-tl-md border border-violet-300/15 bg-violet-300/[0.04] p-4">
                    <p class="text-[10px] font-black uppercase tracking-[.14em] text-violet-300">CANOVIA</p>
                    <p class="mt-2 text-sm leading-7 text-slate-200">
                        今、進めたいことはある？ 目標がまだ言葉になっていなくても、そのまま話してくれれば大丈夫です。
                    </p>
                </div>

                <form method="POST" action="{{ route('goal_discovery.store') }}" class="mt-4 ml-auto max-w-[94%]" data-mutation-once>
                    @csrf
                    <label for="desired_state" class="sr-only">Canoviaに話す</label>
                    <textarea
                        id="desired_state"
                        name="desired_state"
                        rows="4"
                        required
                        autofocus
                        maxlength="500"
                        placeholder="例：応用情報に合格したいけど、何から始めればいいか分からない"
                        class="form-control min-h-[8.5rem] text-base leading-7"
                    >{{ old('desired_state') }}</textarea>
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <p class="text-[11px] leading-5 text-slate-500">サッカーが上手くなりたい / 車を月10台売りたい / 応用情報技術者試験 合格、くらいの一言でもOKです。</p>
                        <button type="submit" class="btn-primary shrink-0">Canoviaと始める</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-2xl border border-slate-800 bg-slate-950/25 p-4">
                <p class="text-xs font-black text-slate-200">話す</p>
                <p class="mt-1 text-[11px] leading-5 text-slate-500">入力形式を選ばず、そのまま伝える。</p>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-950/25 p-4">
                <p class="text-xs font-black text-slate-200">Canoviaが理解する</p>
                <p class="mt-1 text-[11px] leading-5 text-slate-500">Goal ContextとUnknownを会話の裏側で整理。</p>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-950/25 p-4">
                <p class="text-xs font-black text-slate-200">最初の一歩へ</p>
                <p class="mt-1 text-[11px] leading-5 text-slate-500">十分になったら仮Planを作り、実行へつなぐ。</p>
            </div>
        </section>

        <div class="px-1 text-right">
            <a href="{{ route('plans.create.manual') }}" class="text-xs font-semibold text-slate-600 hover:text-slate-400">手動で細かくPlanを作る</a>
        </div>
    </div>
@endsection
