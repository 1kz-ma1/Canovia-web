@php
    // Normal product Account only. Stage has its own synthetic-only shell.
    $mcpIdentityRecorded = $mcpLinkedSubjects->total() > 0;
    $mcpLinkReady = app(\App\Services\McpOAuthAccountLinkConfiguration::class)->settings() !== null;
    $mcpConsentReady = app(\App\Services\McpExplicitPlanConsentService::class)->isEnabled();
    $mcpStudyGuide = config('canovia_mcp.study_read_enabled') === true
        && app(\App\Services\McpStudyPilotAccessPolicy::class)->allowsOwnerId((int) $user->id);
    $mcpReadEnabled = config('canovia_mcp.tools_enabled') === true
        && config('canovia_mcp.delegated_policy_enabled') === true
        && $mcpConsentReady;
@endphp
<section class="page-card min-w-0 p-6 sm:p-8" data-mcp-owner-onboarding aria-labelledby="mcp-owner-guide-title">
    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">CANOVIA × CHATGPT</p>
    <h2 id="mcp-owner-guide-title" class="mt-2 text-lg font-bold text-slate-50">ChatGPTと計画を共有するまで</h2>
    <p class="mt-2 text-xs leading-6 text-slate-400">
        本人IDの確認、計画の共有許可、ChatGPT側の接続はそれぞれ別の操作です。
        この画面に表示されるのはCanoviaに保存された状態で、ChatGPTが接続済みかどうかは確認できません。
    </p>

    @if (! $mcpLinkReady || ! $mcpConsentReady)
        <div class="mt-3 rounded-xl border border-amber-300/20 bg-amber-300/[0.04] p-3 text-xs leading-6 text-amber-100"
            data-mcp-owner-guide-unavailable role="status">
            通常のCanoviaアカウントでの接続は現在準備中です。
            本人確認と計画の共有許可が利用可能になるまで、ここから接続は開始できません。
            既にある共有許可・本人IDの記録は下で確認・解除できます。
        </div>
    @else
        <ol class="mt-4 space-y-3 text-xs leading-6 text-slate-200" data-mcp-owner-guide-steps>
            <li>
                <strong>1. 本人IDを確認する</strong>
                <span class="ml-1 text-slate-400">{{ $mcpIdentityRecorded ? 'Canovia側に紐付け記録があります' : '未完了' }}</span>
                <p class="text-slate-400">
                    @if (app(\App\Services\McpOAuthAccountLinkConfiguration::class)->githubOnlyEnabled())
                        GitHub専用の認証画面に進みます。別のGitHubアカウントが自動選択された場合は、承認せず確認してください。
                    @else
                        認証画面では、本人確認に使う外部アカウントを確認してください。別のアカウントで自動ログインされた場合は承認を中止してください。
                    @endif
                </p>
            </li>
            <li>
                <strong>2. 計画と共有する情報を選ぶ</strong>
                <span class="ml-1 text-slate-400">{{ $mcpHasCurrentConsentRecord ? '期間内の許可記録があります' : '許可記録なし／期限切れ' }}</span>
                <p class="text-slate-400">{{ $mcpStudyGuide ? 'Studyの自分の非公開・個人学習Plan' : 'Developmentの自分の計画' }}から対象・範囲・期間を選び、改めて本人確認した後に承認します。概要だけの共有から始めることをおすすめします。</p>
            </li>
            <li>
                <strong>3. ChatGPT側でCanoviaを接続する</strong>
                <span class="ml-1 text-slate-400">接続状態は未確認</span>
                <p class="text-slate-400">本人確認と同じ外部アカウントを使用してください。共有を許可しただけではChatGPTは接続されません。</p>
            </li>
        </ol>

        @if (! $mcpIdentityRecorded)
            <a class="btn-secondary mt-4 inline-flex min-h-11 items-center px-4 text-xs"
                href="#mcp-owner-link-section" data-mcp-owner-guide-next-link>
                次へ：本人IDの確認
            </a>
        @elseif (! $mcpHasCurrentConsentRecord)
            <a class="btn-secondary mt-4 inline-flex min-h-11 items-center px-4 text-xs"
                href="{{ route($mcpStudyGuide ? 'workspace.study.index' : 'workspace.development.index') }}" data-mcp-owner-guide-next-consent>
                次へ：{{ $mcpStudyGuide ? 'Study' : 'Development' }}で共有する計画を選ぶ
            </a>
        @elseif ($mcpReadEnabled)
            <p class="mt-3 text-xs leading-6 text-amber-100" data-mcp-owner-guide-next-chatgpt>
                Canovia側に期間内の共有許可記録があります。所有権・共有範囲・接続先の現在の認可は読み取り時にも確認されます。
                ChatGPTの接続設定でCanoviaを選び、認証したアカウントが一致することを確認してください。
            </p>
            <a class="btn-secondary mt-2 inline-flex min-h-11 items-center px-4 text-xs"
                href="https://chatgpt.com/" target="_blank" rel="noopener noreferrer">ChatGPTを開く（別タブ）</a>
        @else
            <p class="mt-3 text-xs leading-6 text-amber-100" data-mcp-owner-guide-read-disabled role="status">
                期間内の共有許可記録がありますが、Canovia側のMCP読み取りはまだ有効になっていません。
                既存の許可は下から取り消せます。ChatGPT側の接続操作は有効化の案内後に行ってください。
            </p>
        @endif
    @endif
    <details class="mt-4 text-xs leading-6 text-slate-400" data-mcp-self-account-info>
        <summary class="cursor-pointer text-slate-200">本人限定テストの設定に使うアカウント情報</summary>
        <p class="mt-2">このCanoviaアカウントの本人ID：<code data-mcp-self-account-id>{{ $user->id }}</code></p>
        <p>運用者が本人限定テストの対象を確認するためのIDです。Plan IDや外部サービスの本人IDとは異なり、この番号だけで共有は許可されません。</p>
    </details>
    <details class="mt-4 text-xs leading-6 text-slate-400" data-mcp-owner-guide-troubleshooting>
        <summary class="cursor-pointer text-slate-200">接続に失敗したとき</summary>
        <p class="mt-2">
            GitHubとGoogleなど別の認証方法を混同しないでください。
            以前のSSOログインが残っている場合は、表示されたアカウントを確認し、違えば承認を中止してください。
            HTTP 429の場合はすぐに繰り返さず、どの操作で発生したかを確認してください。
            共有を取り消しても、ChatGPT側のOAuth接続解除は別途必要になる場合があります。
        </p>
    </details>
</section>
