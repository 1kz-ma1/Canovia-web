@extends('auth.mcp_staging_pilot_shell')

@section('title', '検証用ログイン | Canovia')

@section('pilot_content')
    <p class="eyebrow">MCP STAGING · LOGIN PILOT</p>
    <h1>検証用ログイン</h1>
    <p class="sub">隔離ステージングのテストアカウントのみ利用できます。ChatGPT連携とPlan共有はまだ無効です。</p>

    @if ($errors->any())
        <div role="alert" aria-live="polite" class="notice error" data-auth-login-error>
            {{ $errors->first('email') ?: $errors->first() }}
        </div>
    @endif

    @if (session('status'))
        <div role="status" class="notice">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('auth.login') }}">
        @csrf
        <label class="field">
            メールアドレス（検証専用）
            <span class="fixed-identity">mcp-synthetic-owner@canovia.invalid</span>
        </label>
        <input type="hidden" name="email" value="mcp-synthetic-owner@canovia.invalid">
        <label class="field" for="stage-password">
            パスワード
            <input id="stage-password" type="password" name="password" autocomplete="current-password" minlength="1" required autofocus>
        </label>
        <button type="submit" class="button">検証用アカウントでログイン</button>
    </form>
    <p class="muted">以前生成して安全に保管したパスワードを使用してください。チャットやスクリーンショットには記載しないでください。</p>
@endsection
