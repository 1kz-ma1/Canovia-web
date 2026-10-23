<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '正式リリースへの準備 | Canovia')</title>
    @vite(['resources/css/app.css'])
</head>
<body class="pk-cosmic-shell min-h-screen bg-slate-950 text-slate-100 antialiased" data-preparation-only>
<a href="#main" class="sr-only focus:not-sr-only">本文へ移動</a>
<header class="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-4 p-5">
    <a href="{{ route('home') }}" aria-label="Canovia ホーム"><img src="/brand/canovia-wordmark.png" alt="Canovia" class="h-8 w-auto"></a>
    <nav aria-label="メイン" class="flex flex-wrap gap-4 text-sm">
        <a href="{{ route('home') }}">準備する</a>
        @auth
            <a href="{{ route('auth.account') }}">アカウント</a>
            <form method="POST" action="{{ route('auth.logout') }}">@csrf<button type="submit">ログアウト</button></form>
        @else
            <a href="{{ route('auth.login.form') }}">ログイン</a>
            <a href="{{ route('auth.register.form') }}">アカウント作成</a>
        @endauth
    </nav>
</header>
<main id="main" class="mx-auto max-w-4xl space-y-5 px-5 pb-12">
    @if(session('status'))<p role="status" class="page-card p-4">{{ session('status') }}</p>@endif
    @if($errors->any())
        <div role="alert" class="page-card p-4"><p>保存できませんでした。入力内容を確認してください。</p><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @yield('content')
</main>
<footer class="mx-auto flex max-w-4xl flex-wrap gap-5 px-5 pb-8 text-sm text-slate-400">
    <a href="{{ route('legal.privacy') }}">プライバシー</a><a href="{{ route('legal.support') }}">お問い合わせ・データの確認や削除</a>
</footer>
</body>
</html>
