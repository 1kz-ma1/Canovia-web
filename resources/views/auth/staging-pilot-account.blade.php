@extends('auth.staging-pilot-shell')
@section('title', '本人ID接続テスト | Canovia')
@section('content')
@php
    $linkSettings = app(\App\Services\McpOAuthAccountLinkConfiguration::class)->settings();
    // The stage allowlist, not credentials alone, decides whether the link
    // form and a pending confirmation may be offered to a synthetic user.
    $linkReady = app(\App\Services\McpStagingLoginPilotPolicy::class)->accountLinkReady()
        && $linkSettings !== null;
    $verified = session('mcp.account_link.verified');
    $canConfirm = $linkReady
        && is_array($verified)
        && ($verified['actor_id'] ?? null) === (int) $user->id
        && is_int($verified['verified_at'] ?? null)
        && $verified['verified_at'] <= now()->timestamp
        && now()->timestamp - $verified['verified_at'] <= 300;
    $linked = \App\Models\McpLinkedSubject::query()
        ->where('user_id', $user->id)
        ->where('status', \App\Models\McpLinkedSubject::STATUS_LINKED)
        ->whereNull('revoked_at')
        ->exists();
    $stagePolicy = app(\App\Services\McpStagingLoginPilotPolicy::class);
    $consentService = app(\App\Services\McpExplicitPlanConsentService::class);
    $consentReady = $linked && $stagePolicy->planConsentReady()
        && $consentService->isEnabled() && $linkSettings !== null;
    $fixture = $consentReady
        ? \App\Models\Plan::query()
            ->where('user_id', $user->id)
            ->where('creation_request_id', \App\Services\McpStagingSyntheticPlanFixtureBootstrap::REQUEST_ID)
            ->first()
        : null;
    $fixture = $fixture && $stagePolicy->isApprovedSyntheticPlan($user, $fixture)
        && $consentService->isPersonalDevelopmentOwner($user, $fixture)
        ? $fixture : null;
    $pendingConsent = session(\App\Http\Controllers\McpOAuthPlanConsentController::VERIFIED_SESSION);
    $canConfirmConsent = $fixture !== null
        && is_array($pendingConsent)
        && ($pendingConsent['actor_id'] ?? null) === (int) $user->id
        && ($pendingConsent['plan_id'] ?? null) === (int) $fixture->id
        && is_string($pendingConsent['identity_fingerprint'] ?? null)
        && $consentService->matchesLinkedIdentity($user, $pendingConsent['identity_fingerprint'])
        && in_array($pendingConsent['scope'] ?? null, ['overview', 'tasks'], true)
        && in_array($pendingConsent['duration_days'] ?? null, [1, 7, 30], true)
        && is_int($pendingConsent['verified_at'] ?? null)
        && $pendingConsent['verified_at'] <= now()->timestamp
        && now()->timestamp - $pendingConsent['verified_at'] <= 300;
    $activeGrant = $fixture !== null
        ? \App\Models\McpDelegatedGrant::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $fixture->id)
            ->where('status', \App\Models\McpDelegatedGrant::STATUS_ACTIVE)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first()
        : null;
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
            <p class="note" role="status">本人IDの紐付け記録が存在します。Planの共有承認は別の操作です。</p>
        @elseif ($linkReady)
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

    @if ($fixture !== null)
        <section class="detail" data-mcp-staging-plan-consent>
            <h2>検証用Development Planの共有承認</h2>
            <p class="muted">対象：{{ $fixture->title }}</p>
            <p class="note warning">ここでの承認は隔離ステージングの合成データだけが対象です。ChatGPTからのMCP読み取りはまだ無効です。</p>
            @if ($canConfirmConsent)
                <div class="note" role="status" data-mcp-staging-consent-confirm>
                    Auth0で本人IDを再確認できました。対象：{{ $fixture->title }}。
                    共有範囲：{{ $pendingConsent['scope'] === 'tasks' ? '概要＋Task' : '概要のみ' }}。
                    期間：承認から{{ $pendingConsent['duration_days'] }}日。
                </div>
                <div class="row">
                    <form method="POST" action="{{ route('auth.account.mcp_plan_consent.confirm') }}">
                        @csrf
                        <button type="submit">この検証用Planの共有許可を確定</button>
                    </form>
                    <form method="POST" action="{{ route('auth.account.mcp_plan_consent.cancel') }}">
                        @csrf
                        <button class="secondary" type="submit">承認を中止</button>
                    </form>
                </div>
            @else
                @if ($activeGrant)
                    <div class="note" data-mcp-staging-active-grant>
                        検証用Planの共有承認があります。
                        共有範囲：{{ $activeGrant->scope === 'tasks' ? '概要＋Task' : '概要のみ' }}。
                        有効期限：{{ $activeGrant->expires_at->format('Y-m-d H:i') }}。
                        MCPの読み取りは引き続き無効です。
                    </div>
                    <form method="POST" action="{{ route('auth.account.mcp_grant.revoke', ['grant' => $activeGrant->id]) }}">
                        @csrf
                        @method('DELETE')
                        <button class="secondary" type="submit">この検証用Planの共有許可を取り消す</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('auth.account.mcp_plan_consent.start', ['plan' => $fixture->id]) }}">
                    @csrf
                    <label for="mcp-scope">共有範囲</label>
                    <select id="mcp-scope" name="scope" required>
                        <option value="overview">概要のみ</option>
                        <option value="tasks">概要＋Task</option>
                    </select>
                    <label for="mcp-duration">有効期限</label>
                    <select id="mcp-duration" name="duration_days" required>
                        <option value="1">1日</option>
                        <option value="7" selected>7日</option>
                        <option value="30">30日</option>
                    </select>
                    <div class="row">
                        <button type="submit">Auth0で本人IDを再確認して承認へ</button>
                    </div>
                </form>
            @endif
        </section>
    @endif

    <section class="detail">
        <form method="POST" action="{{ route('auth.logout') }}">
            @csrf
            <button class="secondary" type="submit">ログアウト</button>
        </form>
    </section>
@endsection
