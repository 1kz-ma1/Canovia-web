@extends('layouts.app')

@section('title', '目標を決める | Canovia')

@section('content')
    <div class="mx-auto max-w-4xl space-y-5 pb-28 md:pb-0">
        <header class="relative overflow-hidden rounded-[1.65rem] border border-cyan-300/15 bg-[radial-gradient(circle_at_86%_16%,rgba(34,211,238,.12),transparent_28%),radial-gradient(circle_at_10%_100%,rgba(99,102,241,.14),transparent_34%),rgba(6,13,31,.78)] p-5 shadow-[0_24px_70px_rgba(2,6,23,.28)] sm:p-7">
            <div class="relative z-10 max-w-2xl">
                <p class="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">GOAL DISCOVERY</p>
                <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-50 sm:text-3xl">どんな未来にしたい？</h1>
                <p class="mt-3 max-w-xl text-sm leading-7 text-slate-400">
                    最初から計画を完成させなくて大丈夫。まず目標だけ受け取り、Canoviaが現在地を少しずつ理解しながら仮Planを育てます。
                </p>
            </div>
            <div class="pointer-events-none absolute -bottom-8 -right-4 hidden w-40 opacity-75 sm:block" aria-hidden="true">
                <img src="/brand/mascot-guide.webp" alt="" class="w-full drop-shadow-[0_18px_30px_rgba(0,0,0,.45)]">
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

        <section class="page-card p-5 sm:p-6" data-onboarding-target="plan-form" data-guide-target="goal-discovery-start">
            <div class="mx-auto max-w-2xl">
                <div class="flex items-center gap-2">
                    <span class="rounded-full border border-cyan-300/25 bg-cyan-300/10 px-2 py-1 text-[10px] font-black tracking-[.12em] text-cyan-300">最初はこれだけ</span>
                    <span class="text-[11px] text-slate-500">1入力</span>
                </div>

                <form method="POST" action="{{ route('goal_discovery.store') }}" class="mt-5" data-mutation-once>
                    @csrf
                    <label for="desired_state" class="block text-sm font-black text-slate-100">どうなりたい？ 何を達成したい？</label>
                    <textarea
                        id="desired_state"
                        name="desired_state"
                        rows="3"
                        required
                        autofocus
                        maxlength="500"
                        placeholder="例：サッカーが上手くなりたい / 車を月10台売りたい / 応用情報技術者試験 合格"
                        class="form-control mt-2 min-h-[8rem] text-base font-semibold sm:text-lg"
                    >{{ old('desired_state') }}</textarea>
                    <p class="mt-2 text-xs leading-5 text-slate-500">
                        曖昧でもOKです。現在地や成功基準は、必要な分だけ後から1問ずつ確認します。
                    </p>

                    <button type="submit" class="btn-primary mt-5 w-full sm:w-auto">Canoviaに渡す</button>
                </form>

                <div class="mt-6 border-t border-white/6 pt-4">
                    <p class="text-[11px] leading-5 text-slate-500">
                        期限・カテゴリ・見た目などを最初から自分で設定したい場合は、従来の詳細フォームも使えます。
                    </p>
                    <a href="{{ route('plans.create.manual') }}" class="btn-secondary mt-3 px-3 py-2 text-xs">手動で細かくPlanを作る</a>
                </div>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-2xl border border-slate-800 bg-slate-950/25 p-4">
                <p class="text-xs font-black text-slate-200">1. Goal</p>
                <p class="mt-1 text-[11px] leading-5 text-slate-500">まず未来を一言で渡す。</p>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-950/25 p-4">
                <p class="text-xs font-black text-slate-200">2. Current State</p>
                <p class="mt-1 text-[11px] leading-5 text-slate-500">必要なことだけ、1問ずつ確認。</p>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-slate-950/25 p-4">
                <p class="text-xs font-black text-slate-200">3. Provisional Plan</p>
                <p class="mt-1 text-[11px] leading-5 text-slate-500">分からない部分は測るTaskへ。</p>
            </div>
        </section>
    </div>
@endsection
