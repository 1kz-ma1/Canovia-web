@php
    $oauthLinkSettings = app(\App\Services\McpOAuthAccountLinkConfiguration::class)->settings();
    $oauthVerifiedPending = session('mcp.account_link.verified');
    $oauthCanConfirm = $oauthLinkSettings !== null
        && is_array($oauthVerifiedPending)
        && isset($oauthVerifiedPending['actor_id'], $oauthVerifiedPending['verified_at'])
        && (int) $oauthVerifiedPending['actor_id'] === (int) $user->id
        && is_int($oauthVerifiedPending['verified_at'])
        && $oauthVerifiedPending['verified_at'] <= now()->timestamp
        && now()->timestamp - $oauthVerifiedPending['verified_at'] <= 300;
@endphp
<section class="page-card min-w-0 p-6 sm:p-8" data-mcp-account-link>
    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">
        MCP · VERIFIED IDENTITY
    </p>
    <h2 class="mt-2 text-lg font-bold text-slate-50">外部AIとの本人ID紐付け</h2>
    <p class="mt-2 text-xs leading-5 text-slate-400">
        Canoviaへログインしている本人と、認証プロバイダーの本人IDを安全に紐付けるための機能です。
        <strong class="text-slate-200">紐付けだけではChatGPTにPlan・Task情報は公開されません。</strong>
        外部AIへの共有には、別途Planを指定した正式な許可が必要です。
    </p>

    @if ($oauthCanConfirm)
        <div class="mt-3 min-w-0 rounded-xl border border-cyan-500/25 bg-slate-950/25 p-3">
            <p class="text-xs leading-5 text-cyan-200">
                認証プロバイダーで本人確認できました。内容を確認し、必要な場合だけ紐付けを確定してください。
                この操作ではPlan共有許可を作りません。
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <form method="POST" action="{{ route('auth.account.mcp_link.confirm') }}">
                    @csrf
                    <button type="submit" class="btn-secondary min-h-11 px-4 text-xs">
                        本人IDの紐付けを確定
                    </button>
                </form>
                <form method="POST" action="{{ route('auth.account.mcp_link.cancel') }}">
                    @csrf
                    <button type="submit" class="btn-secondary min-h-11 px-4 text-xs">
                        今回の紐付けを中止
                    </button>
                </form>
            </div>
        </div>
    @elseif ($oauthLinkSettings !== null)
        @if ($mcpLinkedSubjects->total() === 0)
            <form method="POST" action="{{ route('auth.account.mcp_link.start') }}" class="mt-3">
                @csrf
                <button type="submit" class="btn-secondary min-h-11 px-4 text-xs">
                    認証プロバイダーで本人確認を開始
                </button>
            </form>
            <p class="mt-2 text-[11px] text-slate-500">
                別ページで本人認証後、この画面で確認・確定します。情報は自動共有されません。
            </p>
        @else
            <p class="mt-3 text-xs text-amber-200">
                本人IDの紐付け記録があります。別のIDを紐付ける場合は、既存の紐付けを解除してから進めてください。
            </p>
        @endif
    @else
        <p class="mt-3 text-xs text-amber-200" data-mcp-account-link-disabled>
            認証プロバイダーが未設定のため、本人IDの紐付けはまだ利用できません。
            ChatGPTのMCP接続・非公開Contextの外部参照も無効です。
        </p>
    @endif
</section>
