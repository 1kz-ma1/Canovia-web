<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>はじめまして | Canovia</title>
    @vite(['resources/css/app.css'])
</head>
<body class="pk-cosmic-shell min-h-screen bg-slate-950 text-slate-100 antialiased">
    <div class="pk-cosmic-backdrop pointer-events-none fixed inset-0 -z-10 overflow-hidden" aria-hidden="true">
        <span class="pk-space-glow pk-space-glow-a"></span>
        <span class="pk-space-glow pk-space-glow-b"></span>
        <span class="pk-star-field pk-star-field-a"></span>
        <span class="pk-star-field pk-star-field-b"></span>
    </div>

    <main class="mx-auto flex min-h-screen w-full max-w-3xl items-center px-4 py-8 sm:px-6">
        <section class="pk-onboarding-intro-card relative w-full overflow-hidden rounded-[2rem] border border-slate-700/70 bg-slate-950/90 p-5 shadow-2xl shadow-slate-950/60 sm:p-8" aria-labelledby="first-run-title">
            <img src="/brand/mascot-guide.webp" alt="" class="pk-onboarding-mascot" aria-hidden="true">

            <div class="relative z-10">
                <div class="flex items-center gap-3">
                    <img src="/brand/logo-mark.svg" alt="" class="h-9 w-9" width="36" height="36">
                    <div>
                        <p class="onboarding-kicker">はじめまして</p>
                        <p class="mt-0.5 text-[11px] font-semibold tracking-[0.16em] text-slate-500">FIRST ROUTE</p>
                    </div>
                </div>

                <h1 id="first-run-title" class="mt-5 max-w-xl text-3xl font-black leading-tight text-slate-50 sm:text-4xl">
                    Canoviaは、話しながら<br class="hidden sm:block">次の一歩を作るアプリです
                </h1>
                <p class="mt-3 max-w-xl text-sm leading-7 text-slate-300 sm:text-base">
                    目標がまだ曖昧でも大丈夫。最初に今進めたいことと現在地を少し教えると、Canoviaが最初のPlanの型と次の一歩を用意します。
                    その後の会話・実行・Evidence・Inboxも同じContextとして引き継ぎます。
                </p>

                <div class="onboarding-intro-points mt-6">
                    <article>
                        <span aria-hidden="true">🤝</span>
                        <div>
                            <strong>まず現在地を少し伝える</strong>
                            <small>全部設定せず、今分かる範囲だけで始められます。</small>
                        </div>
                    </article>
                    <article>
                        <span aria-hidden="true">🗺️</span>
                        <div>
                            <strong>先はロードマップで確認</strong>
                            <small>やる順番と現在地を見失いにくくします。</small>
                        </div>
                    </article>
                    <article>
                        <span aria-hidden="true">⚡</span>
                        <div>
                            <strong>新しい情報はInboxへ</strong>
                            <small>整理先を決める前でも、まずCanoviaへ渡しておけます。</small>
                        </div>
                    </article>
                </div>

                <form method="POST" action="{{ route('first_run.start') }}" class="mt-7" data-first-run-start>
                    @csrf
                    <button type="submit" class="btn-primary w-full justify-center py-4 text-base">Canoviaと始める</button>
                </form>

                @guest
                    <div class="mt-4 text-center">
                        <p class="text-xs text-slate-500">すでにアカウントをお持ちですか？</p>
                        <a href="{{ route('auth.login.form') }}" class="mt-2 inline-flex min-h-11 items-center justify-center px-4 text-sm font-bold text-sky-300 hover:text-sky-200">
                            ログインする
                        </a>
                    </div>
                @endguest
            </div>
        </section>
    </main>

    <script>
        document.querySelector('[data-first-run-start]')?.addEventListener('submit', () => {
            try {
                const version = @json($onboardingVersion);
                localStorage.removeItem('pacekeeper.onboarding.v' + version);
                localStorage.setItem('pacekeeper.onboarding.stage.v' + version, 'home-create');
            } catch (_) {}
        });
    </script>
</body>
</html>
