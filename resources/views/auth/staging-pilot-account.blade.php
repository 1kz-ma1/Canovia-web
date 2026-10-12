@extends('auth.staging-pilot-shell')
@section('title', '本人ID接続テスト | Canovia')
@section('content')
@php
    $linkSettings = app(\App\Services\McpOAuthAccountLinkConfiguration::class)->settings();
    $verified = session('mcp.account_link.verified');
    $canConfirm = $linkSettings !== null
        && is_array($verified)
        && ($verified['actor_id'] ?? null) === (int) $user->id
        && is_int($verified['verified_at'] ?? null)
        && $verified['verified_at'] <= now()->timestamp
        && now()->timestamp - $verified['verified_at'] <= 300;
    $linked = \App\Models\McpLinkedSubject::query()
        ->where('user_id', $user->id)
        ->where('status', \App\Models\McpLinkedSubject::STATUS_LINKED)
        ->exists();
@endphp
    <p class="eyebrow">STAGING / IDENTITY LINK ONLY</p>
    <h1>ログインできました</h1>
    <p class="muted">検証用アカウント：{{ $user->email }}</p>
    <div class="note">Canoviaのログインは成功しています。ChatGPTへのPlan・Task共有やMCP読み取りは、まだ一切有効になっていません。</div>

    <section class="detail" data-mcp-account-link>
        <h2>Auth0の本人IDを確認</h2>
        <p class="muted">これから認証プロバイダーで本人確認し、確認結果をこの検証用アカウントに紐付けます。実際のデータ共有は行いません。</p>
        @if ($canConfirm)
            <p class="note" role="status">Auth0の確認が完了しました。紐付ける場合のみ確定してください。</p>
            <div class="row">
                <form method="POST" action="{{ route('auth.account.mcp_link.confirm') }}">
                    @csrf
                    <button type="submit">本人IDの紐付けを確定</button>
                </form>
                <form method="POST" action="{{ route('auth.account.mcp_link.cancel') }}">
                    @csrf
                    <button type="submit" class="secondary">中止</button>
                </form>
            </div>
        @elseif ($linked)
            <p class="note" role="status">本人IDの紐付け記録が存在します。Planの共有許可はまだ付与されていません。</p>
        @elseif ($linkSettings !== null)
            <form method="POST" action="{{ route('auth.account.mcp_link.start') }}">
                @csrf
                <button type="submit">Auth0で本人確認を開始</button>
            </form>
        @else
            <p class="note warning" data-mcp-account-link-disabled>
                Auth0の本人確認はまだ無効です。安全性の検証が完了するまで、この画面に留まります。
            </p>
        @endif
    </section>

    <section class="detail">
        <form method="POST" action="{{ route('auth.logout') }}">
            @csrf
            <button class="secondary" type="submit">ログアウト</button>
        </form>
    </section>
@endsection
