@extends('auth.mcp_staging_pilot_shell')

@section('title', '検証用アカウント | Canovia')

@section('pilot_content')
    <p class="eyebrow">MCP STAGING · SIGNED IN</p>
    <h1>ログインを確認しました</h1>
    <p class="sub">隔離ステージングの検証用アカウントでログインしています。通常のCanovia機能やPlan共有は利用できません。</p>

    @if (session('status'))
        <div role="status" class="notice">{{ session('status') }}</div>
    @endif

    <p class="field">検証用アカウント<span class="fixed-identity">mcp-synthetic-owner@canovia.invalid</span></p>

    @if ($linkPilotReady)
        @if (session('mcp.account_link.verified'))
            <p class="notice">認証プロバイダーで本人確認できました。次の操作でCanoviaとの紐付けを確定できます（Planへの共有許可ではありません）。</p>
            <form method="POST" action="{{ route('auth.account.mcp_link.confirm') }}">
                @csrf
                <button type="submit" class="button">本人IDの紐付けを確定</button>
            </form>
            <form method="POST" action="{{ route('auth.account.mcp_link.cancel') }}">
                @csrf
                <button type="submit" class="button secondary">紐付けを中止</button>
            </form>
        @else
            <form method="POST" action="{{ route('auth.account.mcp_link.start') }}">
                @csrf
                <button type="submit" class="button">Auth0で本人確認を開始</button>
            </form>
        @endif
        <p class="muted">本人IDの確認は共有許可と別です。MCPとPlan共有は引き続き無効です。</p>
    @else
        <div class="notice">ステージングへのログイン成功。Auth0本人確認のテストはまだ有効化していません。</div>
    @endif

    <form method="POST" action="{{ route('auth.logout') }}">
        @csrf
        <button type="submit" class="button secondary">ログアウト</button>
    </form>
@endsection
