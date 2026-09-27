<dialog class="onboarding-intro-dialog" data-onboarding-intro aria-labelledby="onboarding-intro-title">
    <section class="onboarding-intro-card pk-onboarding-intro-card">
        <img src="/brand/mascot-guide.webp" alt="" class="pk-onboarding-mascot" aria-hidden="true">
        <div class="relative z-10 flex items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <img src="/brand/logo-mark.svg" alt="" class="h-8 w-8" width="32" height="32">
                    <p class="onboarding-kicker">はじめに</p>
                </div>
                <h2 id="onboarding-intro-title" class="mt-2 max-w-md text-2xl font-black text-slate-50">Canoviaは、話しながら次の一歩を作るアプリです</h2>
            </div>
            <button type="button" class="feedback-close" data-onboarding-intro-skip aria-label="案内をスキップ">×</button>
        </div>

        <p class="relative z-10 mt-4 max-w-lg text-sm leading-7 text-slate-300">
            目標がまだ曖昧でも大丈夫。最初にCanoviaへ話すと、会話から現在地を理解し、最初のPlanと次の一歩へつなげます。
            その後は実行・Evidence・Inboxを同じContextとして引き継ぎます。
        </p>

        <div class="relative z-10 onboarding-intro-points mt-5">
            <article>
                <span aria-hidden="true">🤝</span>
                <div><strong>まずCanoviaに話す</strong><small>入力方法を選ばず、今の目標や状況をそのまま伝えられます。</small></div>
            </article>
            <article>
                <span aria-hidden="true">🗺️</span>
                <div><strong>先はロードマップで確認</strong><small>やる順番と現在地を見失いにくくします。</small></div>
            </article>
            <article>
                <span aria-hidden="true">⚡</span>
                <div><strong>新しい情報はInboxへ</strong><small>整理先を決める前でも、まずCanoviaへ渡しておけます。</small></div>
            </article>
        </div>

        <div class="relative z-10 mt-6 flex flex-col gap-2 sm:flex-row">
            <button type="button" class="btn-primary flex-1 justify-center" data-onboarding-intro-start>Canoviaと始める</button>
            <button type="button" class="btn-secondary flex-1 justify-center" data-onboarding-intro-skip>今はスキップ</button>
        </div>
    </section>
</dialog>

<div
    class="onboarding-layer hidden"
    data-onboarding-root
    data-complete-url="{{ route('onboarding.complete') }}"
    data-skip-url="{{ route('onboarding.skip') }}"
    aria-live="polite"
>
    <div class="onboarding-blocker" data-onboarding-blocker="top"></div>
    <div class="onboarding-blocker" data-onboarding-blocker="left"></div>
    <div class="onboarding-blocker" data-onboarding-blocker="right"></div>
    <div class="onboarding-blocker" data-onboarding-blocker="bottom"></div>
    <div class="onboarding-focus-ring" data-onboarding-focus-ring aria-hidden="true"></div>

    <section class="onboarding-bubble" data-onboarding-bubble role="dialog" aria-modal="false" aria-labelledby="onboarding-title">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="onboarding-kicker" data-onboarding-progress></p>
                <h2 id="onboarding-title" class="onboarding-title" data-onboarding-title></h2>
            </div>
            <button type="button" class="onboarding-skip" data-onboarding-skip>スキップ</button>
        </div>
        <p class="onboarding-copy" data-onboarding-copy></p>
        <div class="onboarding-actions hidden" data-onboarding-actions>
            <button type="button" class="btn-primary" data-onboarding-next>次へ</button>
        </div>
    </section>
</div>

<dialog class="install-guide-dialog" data-install-guide aria-labelledby="install-guide-title">
    <section class="install-guide-card">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-xs font-black uppercase tracking-[0.14em] text-sky-300">Canoviaをすぐ開く</p>
                <h2 id="install-guide-title" class="mt-1 text-xl font-black text-slate-50">ホーム画面に追加</h2>
            </div>
            <button type="button" class="feedback-close" data-install-guide-close aria-label="閉じる">×</button>
        </div>

        <div class="mt-5 flex items-center gap-4 rounded-2xl border border-slate-800 bg-slate-950/45 p-4">
            <img src="/icons/icon-192.png" alt="" class="h-16 w-16 rounded-2xl shadow-lg" width="64" height="64">
            <div class="min-w-0">
                <p class="font-bold text-slate-100">Canovia</p>
                <p class="mt-1 text-sm leading-6 text-slate-400" data-install-guide-copy>ブラウザのデータを引き継いだ状態でホーム画面へ追加できます。</p>
            </div>
        </div>

        <div class="mt-5 rounded-2xl border border-amber-300/20 bg-amber-300/5 p-4 text-sm leading-6 text-slate-300">
            <p class="font-bold text-amber-100">データ引き継ぎ対応</p>
            <p class="mt-1">Safariとホーム画面版で保存領域が分かれても、ログイン状態またはGuest計画を初回起動時に引き継ぎます。</p>
        </div>

        <div class="mt-5 hidden rounded-2xl border border-sky-400/25 bg-sky-500/10 p-4 text-sm leading-6 text-slate-200" data-install-ios-help>
            <p class="font-bold text-slate-100">iPhone / iPadの場合</p>
            <p class="mt-1">Safariで共有ボタンを押し、「ホーム画面に追加」→「追加」の順に選んでください。</p>
        </div>

        <div class="mt-5 hidden rounded-2xl border border-slate-700 bg-slate-950/35 p-4 text-sm leading-6 text-slate-300" data-install-browser-help>
            <p class="font-bold text-slate-100" data-install-browser-title>ブラウザから追加できます</p>
            <p class="mt-1" data-install-browser-copy>ブラウザのメニューから「アプリをインストール」または「ホーム画面に追加」を選んでください。</p>
        </div>

        <div class="mt-5 flex flex-col gap-2 sm:flex-row">
            <button type="button" class="btn-primary flex-1 justify-center" data-install-guide-action>ホーム画面に追加</button>
            <button type="button" class="btn-secondary flex-1 justify-center" data-install-guide-later>今はしない</button>
        </div>
    </section>
</dialog>
