@extends('auth.staging-pilot-shell')
@section('title', 'ChatGPT連携の準備 | Canovia')
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
    $syntheticReadReady = app(\App\Services\McpStagingSyntheticReadPilotPolicy::class)->isReady();
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
    <p class="eyebrow">{{ $syntheticReadReady ? 'STAGING / SYNTHETIC MCP READ PILOT' : 'STAGING / IDENTITY LINK ONLY' }}</p>
    <h1>ChatGPTとCanoviaをつなぐ</h1>
    <p class="muted">検証用アカウント：{{ $user->email }}</p>
    <div class="note">
        @if ($syntheticReadReady)
            これは接続テスト用の画面です。ChatGPTに共有できるのは、テスト用に用意した計画だけです。普段の計画は共有されません。
        @else
            ログインできました。ChatGPT連携のテストは現在利用できません。
        @endif
    </div>

    {{-- This is a Canovia-side checklist only. Never infer the ChatGPT-side
         OAuth login/account from a linked record or a Plan grant. --}}
    @if ($syntheticReadReady && $linkReady)
        <section class="detail" data-mcp-staging-dual-client-guide>
            <h2>接続の進め方</h2>
            <p class="muted">この画面で準備を済ませたら、ChatGPTでCanoviaを接続します。順番に進めれば大丈夫です。</p>
            <div class="note" data-mcp-staging-link-state>
                <strong>1. 本人確認：{{ $linked ? '済み' : '未完了' }}</strong>
                <span> — あなたの計画をほかの人に見せないための確認です。</span>
            </div>
            <div class="note" data-mcp-staging-grant-state>
                <strong>2. 計画の共有：{{ $activeGrant ? '許可中' : '許可していません' }}</strong>
                @if ($activeGrant)
                    <span> — 共有内容：{{ $activeGrant->scope === 'tasks' ? '計画の概要とタスク' : '計画の概要のみ' }}</span>
                @endif
            </div>
            <ol>
                <li>下の「本人確認」で使うアカウントを確認します。</li>
                <li>ChatGPTに見せる計画と内容、共有する期間を選びます。</li>
                <li>ChatGPTの連携設定から「Canovia MCP Staging」を接続します。その際、<strong>本人確認で選んだものと同じアカウント</strong>でログインしてください。</li>
            </ol>
            <details class="note" data-mcp-staging-connection-help>
                <summary>うまく接続できないとき</summary>
                <p>ChatGPT側のCanovia接続をいったん解除して、再接続してみてください。最初にGitHubで本人確認した場合は、ChatGPTでもGitHubを選びます。Googleを使った場合も同様です。</p>
                <p>別のアカウントで自動ログインしてしまう場合は、接続画面を開いたブラウザで、以前のログインが残っていないか確認してください。</p>
            </details>
            <p class="muted" data-mcp-staging-chatgpt-unknown>ここに「済み」「許可中」と表示されても、ChatGPT側の接続が完了したとは限りません。接続できたかどうかはChatGPTから実際に計画を確認して確かめてください。</p>
        </section>
    @endif

    <section class="detail" data-mcp-account-link>
        <h2>1. 本人確認</h2>
        <p class="muted">接続に使うアカウントを確認します。この操作だけでは、ChatGPTに計画は共有されません。</p>
        @if ($canConfirm)
            <p class="note" role="status">本人確認ができました。このアカウントを使う場合は、下のボタンで確定してください。</p>
            <div class="row">
                <form method="POST" action="{{ route('auth.account.mcp_link.confirm') }}">
                    @csrf
                    <button type="submit">このアカウントを使う</button>
                </form>
                <form method="POST" action="{{ route('auth.account.mcp_link.cancel') }}">
                    @csrf
                    <button type="submit" class="secondary">中止</button>
                </form>
            </div>
        @elseif ($linked)
            <p class="note" role="status">本人確認が済んでいます。共有する計画は次の項目で選べます。</p>
        @elseif ($linkReady)
            <form method="POST" action="{{ route('auth.account.mcp_link.start') }}">
                @csrf
                <button type="submit">本人確認をはじめる</button>
            </form>
        @else
            <p class="note warning" data-mcp-account-link-disabled>
                本人確認は現在利用できません。
            </p>
        @endif
    </section>

    @if ($fixture !== null)
        <section class="detail" data-mcp-staging-plan-consent>
            <h2>2. ChatGPTに見せる計画を選ぶ</h2>
            <p class="muted">対象：{{ $fixture->title }}</p>
            @if ($syntheticReadReady)
                <p class="muted" data-mcp-synthetic-plan-id>テスト用Plan番号：{{ $fixture->id }}</p>
            @endif
            <p class="note warning">共有するのはテスト用の計画だけです。{{ $syntheticReadReady ? '選んだ範囲の情報だけをChatGPTから確認できます。' : '現在、ChatGPTとの共有は利用できません。' }}</p>
            @if ($canConfirmConsent)
                <div class="note" role="status" data-mcp-staging-consent-confirm>
                    本人確認ができました。共有する計画：{{ $fixture->title }}。
                    共有する内容：{{ $pendingConsent['scope'] === 'tasks' ? '計画の概要とタスク' : '計画の概要だけ' }}。
                    期間：承認から{{ $pendingConsent['duration_days'] }}日。
                </div>
                <div class="row">
                    <form method="POST" action="{{ route('auth.account.mcp_plan_consent.confirm') }}">
                        @csrf
                        <button type="submit">この計画の共有を許可する</button>
                    </form>
                    <form method="POST" action="{{ route('auth.account.mcp_plan_consent.cancel') }}">
                        @csrf
                        <button class="secondary" type="submit">承認を中止</button>
                    </form>
                </div>
            @else
                @if ($activeGrant)
                    <div class="note" data-mcp-staging-active-grant>
                        この計画を共有しています。
                        共有範囲：{{ $activeGrant->scope === 'tasks' ? '概要＋Task' : '概要のみ' }}。
                        有効期限：{{ $activeGrant->expires_at->format('Y-m-d H:i') }}。
                        {{ $syntheticReadReady ? '許可した内容だけをChatGPTから確認できます。' : 'ChatGPTとの共有は現在利用できません。' }}
                    </div>
                    <form method="POST" action="{{ route('auth.account.mcp_grant.revoke', ['grant' => $activeGrant->id]) }}">
                        @csrf
                        @method('DELETE')
                        <button class="secondary" type="submit">共有をやめる</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('auth.account.mcp_plan_consent.start', ['plan' => $fixture->id]) }}">
                    @csrf
                    <label for="mcp-scope">ChatGPTに見せる内容</label>
                    <select id="mcp-scope" name="scope" required>
                        <option value="overview">計画の概要だけ</option>
                        <option value="tasks">計画の概要とタスク（名前・状態・進捗）</option>
                    </select>
                    <label for="mcp-duration">共有する期間</label>
                    <select id="mcp-duration" name="duration_days" required>
                        <option value="1">1日</option>
                        <option value="7" selected>7日</option>
                        <option value="30">30日</option>
                    </select>
                    <div class="row">
                        <button type="submit">本人確認して共有内容を確認する</button>
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
