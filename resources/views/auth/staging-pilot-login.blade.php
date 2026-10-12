@extends('auth.staging-pilot-shell')
@section('title', '検証用ログイン | Canovia')
@section('content')
    <p class="eyebrow">STAGING / SYNTHETIC USER ONLY</p>
    <h1>検証用アカウントにログイン</h1>
    <p class="muted">Auth0接続を安全に確認するための専用画面です。通常のCanoviaアカウントは使用できません。</p>
    @if ($errors->any())
        <div role="alert" class="note warning" data-auth-login-error>
            {{ $errors->first('email') ?: $errors->first() }}
        </div>
    @endif
    <form method="POST" action="{{ route('auth.login') }}" data-staging-synthetic-login>
        @csrf
        <label for="staging-email">メールアドレス</label>
        <input id="staging-email" type="email" name="email" autocomplete="username" required
               value="{{ old('email', \App\Services\McpStagingSyntheticActorBootstrap::EMAIL) }}">
        <label for="staging-password">検証用パスワード</label>
        <input id="staging-password" type="password" name="password" autocomplete="current-password" required>
        <button class="wide" type="submit">検証用アカウントにログイン</button>
    </form>
    <p class="muted" style="margin-top:18px">
        パスワードは事前に生成して保管したものを使用してください。ChatGPTには貼り付けないでください。
    </p>
@endsection
