(() => {
    const config = window.CANOVIA_WELCOME_CONFIG || window.PACEKEEPER_WELCOME_CONFIG || {};
    const appUrl = String(config.appUrl || '').replace(/\/$/, '');
    // Only these public routes are selectable from static links.
    // Never turn the query string into an arbitrary redirect URL.
    const destination = new URLSearchParams(window.location.search).get('destination');
    // The current Live Web exposes /feedback for free Support, but not yet
    // the curated roadmap or the new in-page Support anchor.
    // Explicit defaults prevent a static-only deploy from promising Web features.
    const publicRoadmapReady = config.publicRoadmapReady === true;
    const supportAnchorReady = config.supportAnchorReady === true;
    const destinationPaths = {
        future: '/feedback',
        support: supportAnchorReady ? '/feedback#support-candidates' : '/feedback',
    };
    const targetUrl = Object.prototype.hasOwnProperty.call(destinationPaths, destination)
        ? `${appUrl}${destinationPaths[destination]}`
        : appUrl;
    const status = document.querySelector('[data-status]');
    const openButton = document.querySelector('[data-open]');
    const openAnyway = document.querySelector('[data-open-anyway]');
    const retryButton = document.querySelector('[data-retry]');
    const slowNotice = document.querySelector('[data-slow-notice]');
    const hints = [...document.querySelectorAll('[data-hint]')];
    const dots = [...document.querySelectorAll('[data-hint-dot]')];
    const seenKey = 'canovia.welcome.seen.v1';
    const legacySeenKey = 'pacekeeper.welcome.seen.v1';
    const returning = localStorage.getItem(seenKey) === '1' || localStorage.getItem(legacySeenKey) === '1';
    const startedAt = Date.now();
    const slowAfterMs = 22000;
    let ready = false;
    let hintIndex = 0;
    let wakeTimer = null;
    let wakeInFlight = false;
    let slowStateShown = false;

    if (!appUrl) {
        status.textContent = '接続先が設定されていません。';
        slowNotice?.classList.remove('hidden');
        return;
    }

    const setHint = (index) => {
        hintIndex = index % hints.length;
        hints.forEach((hint, i) => hint.classList.toggle('is-active', i === hintIndex));
        dots.forEach((dot, i) => dot.classList.toggle('is-active', i === hintIndex));
    };

    if (!returning && hints.length > 1) {
        window.setInterval(() => setHint(hintIndex + 1), 4800);
    }

    const openApp = () => {
        localStorage.setItem(seenKey, '1');
        window.location.assign(targetUrl);
    };

    const showSlowState = () => {
        if (slowStateShown || ready) return;
        slowStateShown = true;
        status.textContent = navigator.onLine
            ? 'もう少しだけ準備しています…'
            : 'インターネット接続を確認してください';
        slowNotice?.classList.remove('hidden');
    };

    const markReady = () => {
        if (ready) return;
        ready = true;
        window.clearTimeout(wakeTimer);
        status.textContent = '準備できました';
        status.classList.add('is-ready');
        openButton.disabled = false;
        openButton.textContent = destination === 'future'
            ? (publicRoadmapReady ? '開発ロードマップを見る' : '現在の無料Support候補を見る')
            : destination === 'support'
                ? '無料Support・フィードバックへ'
                : 'Canoviaを開く';
        slowNotice?.classList.add('hidden');
        if (returning) window.setTimeout(openApp, 450);
    };

    const scheduleWake = () => {
        if (ready) return;
        const elapsed = Date.now() - startedAt;
        if (elapsed >= slowAfterMs) showSlowState();
        wakeTimer = window.setTimeout(() => wake(), elapsed >= slowAfterMs ? 6500 : 2200);
    };

    const wake = async (manual = false) => {
        if (ready || wakeInFlight) return;
        wakeInFlight = true;
        window.clearTimeout(wakeTimer);

        if (manual) {
            status.textContent = 'もう一度確認しています…';
            status.classList.remove('is-ready');
        }

        if (!navigator.onLine) {
            showSlowState();
            wakeInFlight = false;
            scheduleWake();
            return;
        }

        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 7000);
        try {
            const response = await fetch(`${appUrl}/health?from=welcome&t=${Date.now()}`, {
                method: 'GET',
                mode: 'cors',
                cache: 'no-store',
                credentials: 'omit',
                signal: controller.signal,
            });
            if ((response.ok || response.status === 204) && response.headers.get('X-Canovia-Ready') === '1') {
                markReady();
                return;
            }
        } catch (_) {
            // Renderなどの起動中レスポンスは、CanoviaのReadyヘッダーを返しません。
            // Welcome画面を維持したまま次の確認を待ちます。
        } finally {
            window.clearTimeout(timeout);
            wakeInFlight = false;
        }

        scheduleWake();
    };

    openButton.addEventListener('click', openApp);
    openAnyway?.addEventListener('click', openApp);
    retryButton?.addEventListener('click', () => wake(true));
    window.addEventListener('online', () => wake(true));
    window.addEventListener('offline', showSlowState);

    wake();
})();
