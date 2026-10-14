{{-- Owner-only listing: grant records here do not prove a live ChatGPT connection. --}}
<section class="page-card min-w-0 p-6 sm:p-8" data-mcp-delegated-revocation>
    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">
        MCP · ACCESS CONTROL
    </p>
    <h2 class="mt-2 text-lg font-bold text-slate-50">外部AIの共有許可・紐付けの解除</h2>
    <p class="mt-2 text-xs leading-5 text-slate-400">
        この画面では、Canoviaアカウントに紐付く外部本人IDと、Planごとの共有許可を管理できます。
        本人IDの紐付け、Plan共有許可、ChatGPT側のOAuth接続は別々の手続きです。
    </p>
    <div class="mt-3 min-w-0 rounded-xl border border-white/10 bg-slate-950/20 p-3 text-xs leading-6 text-slate-200"
        data-mcp-owner-connection-state>
        @if ($mcpLinkedSubjects->total() > 0)
            <p data-mcp-linked-identity-present>本人ID：解除されていない紐付け記録があります。</p>
        @else
            <p data-mcp-linked-identity-absent>本人ID：解除されていない紐付け記録はありません。</p>
        @endif
        <p class="mt-1 text-slate-400">
            ChatGPT側の接続状態は、この画面からは確認できません。
            紐付け記録やPlan共有許可があっても、ChatGPTでの接続完了や実際の読み取り成功を意味しません。
        </p>
        <p class="mt-1 text-slate-400">
            接続するときは、Canoviaで本人IDを確認し、共有したいPlanの範囲を承認した後、
            ChatGPT側でも同じ認証プロバイダーの本人アカウントを選んで接続してください。
            認証画面が別のアカウントを自動選択する場合は、そのまま承認せず認証先を確認してください。
        </p>
    </div>

    <h3 class="mt-4 text-sm font-bold text-slate-100">Planごとの許可</h3>
    @forelse ($mcpActiveGrants as $grant)
        @php
            $stillOwnsPlan = $grant->plan
                && (int) $grant->plan->user_id === (int) $user->id;
        @endphp
        <div class="mt-2 min-w-0 rounded-xl border border-white/10 bg-slate-950/20 p-3" data-mcp-grant-entry>
            <p class="break-words text-sm font-semibold text-slate-100">
                {{ $stillOwnsPlan ? $grant->plan->title : '以前のPlan（現在の所有者の情報は非表示）' }}
            </p>
            <p class="mt-1 text-xs leading-5 text-slate-400">
                対象：ChatGPT用の共有許可
                ・範囲：{{ $grant->scope === 'tasks' ? '概要＋Task' : '概要のみ' }}
                ・期限：{{ $grant->expires_at?->format('Y-m-d H:i') ?? '取り消すまで' }}
            </p>
            @if ($grant->expires_at?->isPast())
                <p class="mt-1 text-xs text-amber-200">期限切れ（外部利用不可）</p>
            @else
                <p class="mt-1 text-xs text-amber-200">
                    保存された許可記録です。現在のMCP接続が有効であることを意味しません。
                </p>
            @endif
            <form method="POST" action="{{ route('auth.account.mcp_grant.revoke', ['grant' => $grant->id]) }}"
                class="mt-3" data-mcp-revoke-grant>
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-secondary min-h-11 px-4 text-xs">
                    このPlanの許可を取り消す
                </button>
            </form>
        </div>
    @empty
        <p class="mt-2 text-xs text-slate-500">現在、取り消し対象のPlan共有許可はありません。</p>
    @endforelse
    @if ($mcpActiveGrants->hasPages())
        <div class="mt-3">{{ $mcpActiveGrants->links() }}</div>
    @endif

    <h3 class="mt-5 text-sm font-bold text-slate-100">外部IDの紐付け</h3>
    @forelse ($mcpLinkedSubjects as $subject)
        <div class="mt-2 min-w-0 rounded-xl border border-white/10 bg-slate-950/20 p-3" data-mcp-subject-entry>
            <p class="text-xs font-semibold text-slate-100">ChatGPT向けの外部ID紐付け記録</p>
            <p class="mt-1 text-[11px] leading-5 text-slate-400">
                解除すると、関連するすべてのPlan共有許可も同時に取り消します。
                この記録だけでは外部AIへの接続は有効になりません。
            </p>
            <form method="POST" action="{{ route('auth.account.mcp_subject.revoke', ['subject' => $subject->id]) }}"
                class="mt-3" data-mcp-revoke-subject>
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-secondary min-h-11 px-4 text-xs">
                    外部IDの紐付けと関連する許可を解除
                </button>
            </form>
        </div>
    @empty
        <p class="mt-2 text-xs text-slate-500">現在、外部IDの紐付け記録はありません。</p>
    @endforelse
    @if ($mcpLinkedSubjects->hasPages())
        <div class="mt-3">{{ $mcpLinkedSubjects->links() }}</div>
    @endif
</section>
