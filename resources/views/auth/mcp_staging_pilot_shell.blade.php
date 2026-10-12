<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="color-scheme" content="dark">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title', 'Canovia 検証用ログイン')</title>
    {{-- No shared Canovia layout, Vite/JS/CSS, tracking, PWA or external assets.
         The staging pilot blocks the production app's ancillary routes. --}}
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        :root { color-scheme: dark; font-family: -apple-system, BlinkMacSystemFont, "Hiragino Kaku Gothic ProN", "Noto Sans JP", sans-serif; }
        body { min-height: 100vh; margin: 0; padding: 24px 16px; display: grid; place-items: center; background: #090f20; color: #e5eefc; line-height: 1.65; }
        main { width: 100%; max-width: 460px; }
        .identity { display: flex; align-items: center; gap: 10px; margin-bottom: 24px; font-weight: 800; font-size: 22px; letter-spacing: .025em; }
        .mark { display: inline-grid; place-items: center; width: 34px; height: 34px; border-radius: 11px; background: #2563eb; color: white; font-size: 18px; }
        .card { padding: clamp(22px, 5vw, 34px); background: #111b30; border: 1px solid #34445d; border-radius: 19px; box-shadow: 0 20px 65px #0005; }
        .eyebrow { margin: 0 0 8px; color: #93c5fd; font-size: 12px; font-weight: 800; letter-spacing: .08em; }
        h1 { margin: 0; font-size: clamp(23px, 5vw, 28px); letter-spacing: -.025em; }
        .sub { color: #c7d2e5; font-size: 14px; margin: 12px 0 23px; }
        .field { display: block; margin-top: 18px; font-weight: 700; font-size: 14px; }
        .field input { display: block; width: 100%; padding: 13px 14px; margin-top: 7px; border-radius: 10px; border: 1px solid #7184a6; background: #0b1426; color: white; font: inherit; }
        .field input:focus-visible, button:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
        .fixed-identity { display: block; padding: 13px 14px; margin-top: 7px; overflow-wrap: anywhere; border: 1px solid #354864; background: #0b1426; color: #e5eefc; border-radius: 10px; font-weight: 500; font-size: 14px; }
        .button { display: block; width: 100%; padding: 13px 16px; margin-top: 22px; border: 0; border-radius: 10px; background: #3b82f6; color: white; font: inherit; font-weight: 800; text-align: center; cursor: pointer; }
        .button:hover { background: #2563eb; }
        .button.secondary { background: #243754; border: 1px solid #64748b; margin-top: 10px; }
        .notice { margin: 16px 0; padding: 12px 14px; border: 1px solid #5a7194; border-radius: 10px; font-size: 14px; overflow-wrap: anywhere; }
        .notice.error { border-color: #b45365; background: #4c142329; }
        .muted, footer { color: #a4b4cc; font-size: 13px; }
        footer { margin-top: 20px; }
    </style>
</head>
<body>
<main>
    <div class="identity"><span class="mark" aria-hidden="true">C</span> Canovia</div>
    <section class="card">
        @yield('pilot_content')
    </section>
    <footer>検証専用の隔離ステージングです。通常のCanoviaや個人データには接続していません。</footer>
</main>
</body>
</html>
