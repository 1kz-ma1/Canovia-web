<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="referrer" content="no-referrer">
    <meta name="color-scheme" content="dark">
    <title>@yield('title', 'Canovia 接続テスト')</title>
    {{-- Intentionally no @vite, external scripts, fonts, nav or service worker.
         Staging's allowlist must not depend on the regular product shell. --}}
    <style>
        *,*::before,*::after{box-sizing:border-box}
        html{min-height:100%;background:#080f20}
        body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;
             color:#e7edf9;background:radial-gradient(ellipse at 50% -20%,#213654,#080f20 66%);
             font-family:-apple-system,BlinkMacSystemFont,"Helvetica Neue",Arial,sans-serif;line-height:1.65}
        main{width:100%;max-width:560px}
        .brand{font-size:15px;font-weight:800;letter-spacing:.14em;color:#a9c7e7;margin-bottom:16px}
        .card{background:#101c31;border:1px solid #33445e;border-radius:20px;
              padding:clamp(22px,5vw,36px);box-shadow:0 24px 70px #0005}
        h1{font-size:clamp(22px,4vw,30px);line-height:1.3;margin:0 0 12px}
        h2{font-size:18px;margin:24px 0 8px}
        p{margin:8px 0 14px}
        .muted{font-size:14px;color:#acbbd4}
        .eyebrow{font-size:12px;font-weight:750;letter-spacing:.12em;color:#8dd5ef}
        label{display:block;font-weight:700;font-size:14px;margin:18px 0 8px}
        input{display:block;width:100%;min-height:48px;padding:11px 14px;color:#fff;
              background:#0a1324;border:1px solid #6b7e9c;border-radius:10px;font:inherit}
        select{display:block;width:100%;min-height:48px;padding:11px 14px;
               color:#fff;background:#0a1324;border:1px solid #6b7e9c;border-radius:10px;font:inherit}
        input:focus,select:focus{outline:3px solid #75c7ff55;outline-offset:2px}
        button{appearance:none;cursor:pointer;min-height:46px;padding:10px 18px;border-radius:10px;
               font:inherit;font-weight:750;border:1px solid #7cb2e1;color:#061626;background:#a7ddff}
        button.secondary{color:#d4e7ff;background:#1a3351;border-color:#506d91}
        button:hover{filter:brightness(1.07)}
        button:focus-visible,a:focus-visible{outline:3px solid #a7ddff;outline-offset:3px}
        .wide{width:100%;margin-top:24px}
        .row{display:flex;gap:12px;flex-wrap:wrap;margin-top:20px}
        .note{padding:12px 14px;border-radius:10px;border:1px solid #466781;
              background:#192f48;font-size:14px;margin:16px 0}
        .warning{border-color:#94764c;background:#34291b;color:#ffdfa9}
        a{color:#a7ddff}
        .detail{border-top:1px solid #31445d;margin-top:24px;padding-top:18px}
        .footer{color:#8296b7;text-align:center;font-size:12px;margin:16px 0 0}
        form{margin:0}
    </style>
</head>
<body>
<main>
    <div class="brand">CANOVIA <span class="muted">/ MCP STAGING</span></div>
    <div class="card">
        @if (session('status'))
            <div class="note" role="status">{{ session('status') }}</div>
        @endif
        @yield('content')
    </div>
    <p class="footer">検証用の隔離環境です。通常のCanoviaとデータは共有しません。</p>
</main>
</body>
</html>
