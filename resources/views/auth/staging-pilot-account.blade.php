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
    // Preview only the same whitelisted fields that ChatGPT may later read.
    // The fixture, owner, linked identity, verified scope and five-minute
    // confirmation window have already been validated above.
    $consentPreview = $canConfirmConsent
        ? app(\App\Services\DevelopmentPrivateAiContextPreviewService::class)
            ->preview($fixture, $pendingConsent['scope'], 5)
        : null;
@endphp
    <p class="eyebrow">MCP STAGING / テスト用データのみ</p>

    @if ($canConfirmConsent)
        {{-- One-time verified OAuth outcome: a focused final preview, not the
             setup wizard or another pre-confirm sharing form. --}}
        <h1>共有する内容を確認</h1>
        <p class="muted">本人確認ができました。以下の情報をChatGPTから見られるようにしてよいか確認してください。</p>
        <section data-mcp-staging-consent-confirm aria-labelledby="mcp-preview-heading">
            <h2 id="mcp-preview-heading">共有内容のプレビュー</h2>
            <div class="note" data-mcp-staging-consent-preview>
                <p><strong>計画：</strong>{{ $consentPreview['plan']['title'] }}</p>
                <p><strong>共有する情報：</strong>{{ $pendingConsent['scope'] === 'tasks' ? '計画の概要とタスクの名前・状態・記録上の進捗' : '計画の名前のみ' }}</p>
                <p><strong>共有期間：</strong>許可した日から{{ $pendingConsent['duration_days'] }}日間</p>
                @if ($pendingConsent['scope'] === 'tasks')
                    <p><strong>共有するタスクの例（最大5件）：</strong></p>
                    @if (count($consentPreview['tasks']) > 0)
                        <ul data-mcp-staging-task-preview>
                            @foreach ($consentPreview['tasks'] as $task)
                                <li>
                                    {{ $task['title'] }}
                                    （{{ ['doing' => '進行中', 'todo' => '未着手', 'paused' => '一時停止'] [$task['status']] ?? '状態未設定' }}、
                                    記録上の進捗：{{ $task['recorded_progress_percent'] === null ? '未記録' : $task['recorded_progress_percent'].'%' }}）
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p>現在、共有対象のタスクはありません。</p>
                    @endif
                    @if ($consentPreview['truncated'])
                        <p class="muted">ほかにも対象タスクがあります。ChatGPTは1回につき最大8件まで取得できます。</p>
                    @endif
                @endif
            </div>
            <p class="muted">説明文・作業記録・GitHubの情報は共有されません。共有を許可しても、ChatGPTへの接続は別途必要です。共有は後から取り消せます。</p>
            <form method="POST" action="{{ route('auth.account.mcp_plan_consent.confirm') }}" data-mcp-staging-final-confirm-form>
                @csrf
                <button type="submit" class="wide" data-mcp-staging-final-confirm-button>この内容で共有を許可する</button>
            </form>
            <form method="POST" action="{{ route('auth.account.mcp_plan_consent.cancel') }}" class="row">
                @csrf
                <button type="submit" class="secondary">今は共有しない</button>
            </form>
        </section>

    @elseif ($activeGrant)
        {{-- Canovia grant is active, but never claim the separate ChatGPT OAuth
             client is connected or the remote tool has been exercised. --}}
        <section data-mcp-staging-share-complete role="status" aria-labelledby="mcp-complete-heading">
            <h1 id="mcp-complete-heading">計画の共有を許可しました</h1>
            <p>Canovia側の準備は完了です。次はChatGPTでCanoviaを接続して、共有した計画について質問できます。</p>
            <div class="note" data-mcp-staging-active-grant>
                <p><strong>共有する計画：</strong>{{ $fixture->title }}</p>
                <p><strong>ChatGPTが確認できる内容：</strong>{{ $activeGrant->scope === 'tasks' ? '計画名・タスクの名前・状態・記録上の進捗' : '計画名' }}</p>
                <p><strong>共有期限：</strong>{{ $activeGrant->expires_at->format('Y年n月j日 H:i') }}</p>
            </div>
            <h2>ChatGPTでできること</h2>
            <p>{{ $activeGrant->scope === 'tasks' ? '計画の内容や、Canoviaに記録したタスクの進み具合を確認できます。' : '共有した計画の名前を確認できます。タスク情報は共有していません。' }}</p>
            <p class="muted">ChatGPTの連携設定で「Canovia MCP Staging」を接続し、本人確認で使ったものと同じアカウントを選んでください。</p>
            <div class="note" data-mcp-staging-next-step>
                <p><strong>ChatGPTでの質問例</strong></p>
                <p>「CanoviaのPlan ID {{ $fixture->id }} の{{ $activeGrant->scope === 'tasks' ? 'タスクの状況' : '概要' }}を教えて」</p>
            </div>
            <p class="muted" data-mcp-staging-chatgpt-unknown>この画面で確認できるのは共有設定の状態です。ChatGPT側が接続済みかどうかは、ChatGPTで実際に質問して確認してください。</p>
            <a href="https://chatgpt.com/" target="_blank" rel="noopener noreferrer">ChatGPTを開く（別のタブ）</a>
        </section>

        <section class="detail" data-mcp-staging-grant-management>
            <h2>共有の管理</h2>
            <p class="muted">共有する内容や期間は変更できます。共有をやめると、ChatGPTからこの計画を取得できなくなります。</p>
            <details data-mcp-staging-change-settings>
                <summary>共有内容・期間を変更する</summary>
                <form method="POST" action="{{ route('auth.account.mcp_plan_consent.start', ['plan' => $fixture->id]) }}">
                    @csrf
                    <label for="mcp-scope">ChatGPTに見せる内容</label>
                    <select id="mcp-scope" name="scope" required>
                        <option value="overview" @selected($activeGrant->scope === 'overview')>計画の概要だけ</option>
                        <option value="tasks" @selected($activeGrant->scope === 'tasks')>計画の概要とタスク（名前・状態・進捗）</option>
                    </select>
                    <label for="mcp-duration">変更後の共有期間</label>
                    <select id="mcp-duration" name="duration_days" required>
                        <option value="1">1日</option>
                        <option value="7" selected>7日</option>
                        <option value="30">30日</option>
                    </select>
                    <div class="row">
                        <button type="submit">変更内容を確認する</button>
                    </div>
                </form>
            </details>
            <form method="POST" action="{{ route('auth.account.mcp_grant.revoke', ['grant' => $activeGrant->id]) }}" class="row">
                @csrf
                @method('DELETE')
                <button class="secondary" type="submit" data-mcp-staging-revoke>この計画の共有をやめる</button>
            </form>
        </section>

    @elseif ($canConfirm)
        <h1>このアカウントを使いますか？</h1>
        <p>本人確認ができました。ChatGPT連携に使うアカウントを確定してください。この操作だけでは計画は共有されません。</p>
        <section data-mcp-staging-account-confirm>
            <form method="POST" action="{{ route('auth.account.mcp_link.confirm') }}">
                @csrf
                <button type="submit" class="wide">このアカウントを使う</button>
            </form>
            <form method="POST" action="{{ route('auth.account.mcp_link.cancel') }}" class="row">
                @csrf
                <button type="submit" class="secondary">中止する</button>
            </form>
        </section>

    @else
        <h1>ChatGPTとCanoviaをつなぐ</h1>
        <p class="muted">検証用アカウント：{{ $user->email }}</p>
        <div class="note">
            @if ($syntheticReadReady)
                これは接続テスト用の画面です。ChatGPTに共有できるのは、テスト用に用意した計画だけです。普段の計画は共有されません。
            @else
                ログインできました。ChatGPT連携のテストは現在利用できません。
            @endif
        </div>
        @if ($syntheticReadReady && $linkReady)
            <section class="detail" data-mcp-staging-dual-client-guide>
                <h2>接続の進め方</h2>
                <p class="muted">この画面で準備を済ませたら、ChatGPTでCanoviaを接続します。</p>
                <div class="note" data-mcp-staging-link-state>
                    <strong>1. 本人確認：{{ $linked ? '済み' : '未完了' }}</strong>
                    <span> — あなたの計画をほかの人に見せないための確認です。</span>
                </div>
                <div class="note" data-mcp-staging-grant-state>
                    <strong>2. 計画の共有：許可していません</strong>
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
                <p class="muted" data-mcp-staging-chatgpt-unknown>ここに「済み」と表示されても、ChatGPT側の接続が完了したとは限りません。接続できたかどうかはChatGPTから実際に計画を確認して確かめてください。</p>
            </section>
        @endif

        <section class="detail" data-mcp-account-link>
            <h2>1. 本人確認</h2>
            <p class="muted">接続に使うアカウントを確認します。この操作だけでは、ChatGPTに計画は共有されません。</p>
            @if ($linked)
                <p class="note" role="status">本人確認が済んでいます。共有する計画は次の項目で選べます。</p>
            @elseif ($linkReady)
                <form method="POST" action="{{ route('auth.account.mcp_link.start') }}">
                    @csrf
                    <button type="submit">本人確認をはじめる</button>
                </form>
            @else
                <p class="note warning" data-mcp-account-link-disabled>本人確認は現在利用できません。</p>
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
            </section>
        @endif
    @endif

    @if (! $canConfirmConsent)
        <section class="detail">
            <form method="POST" action="{{ route('auth.logout') }}">
                @csrf
                <button class="secondary" type="submit">ログアウト</button>
            </form>
        </section>
    @endif
@endsection
