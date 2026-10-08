@php
    $mcpConsentService = app(\App\Services\McpExplicitPlanConsentService::class);
    $mcpConsentReady = auth()->check()
        && $mcpConsentService->isPersonalDevelopmentOwner(auth()->user(), $plan)
        && $mcpConsentService->isEnabled()
        && $mcpConsentService->hasActiveLinkedIdentity(auth()->user());
@endphp
<details class="page-card min-w-0 p-4 sm:p-5" data-mcp-plan-consent>
    <summary class="cursor-pointer text-sm font-black text-slate-100">
        ChatGPTへのPlan共有許可
        <span class="ml-1 text-xs font-normal text-amber-200">
            {{ $mcpConsentReady ? '本人確認が必要' : '未接続' }}
        </span>
    </summary>
    <p class="mt-3 text-xs leading-5 text-slate-400">
        共有準備とは別の正式なPlan別の許可です。
        対象Planの所有者だけが、範囲と期限を選び、認証プロバイダーで再認証し、
        アカウント画面で最終確定した場合に限り記録します。
        <strong class="text-slate-200">現段階ではChatGPTがCanoviaを直接読む機能はまだ無効です。</strong>
    </p>
    @if ($mcpConsentReady)
        <p class="mt-3 break-words text-xs font-semibold text-slate-200">
            対象Plan：{{ $plan->title }}
        </p>
        <form method="POST"
            action="{{ route('auth.account.mcp_plan_consent.start', ['plan' => $plan->id]) }}"
            class="mt-3 space-y-3" data-mcp-plan-consent-start>
            @csrf
            <div class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="block min-w-0 text-xs text-slate-300">
                    共有する情報
                    <select name="scope" class="form-control mt-1 block w-full min-w-0 max-w-full" required>
                        <option value="overview">Plan概要のみ（初期値）</option>
                        <option value="tasks">Plan概要＋進行中Task</option>
                    </select>
                </label>
                <label class="block min-w-0 text-xs text-slate-300">
                    許可の有効期限
                    <select name="duration_days" class="form-control mt-1 block w-full min-w-0 max-w-full" required>
                        <option value="1">1日</option>
                        <option value="7" selected>7日</option>
                        <option value="30">30日</option>
                    </select>
                </label>
            </div>
            <button type="submit" class="btn-secondary min-h-11 px-4 text-xs">
                選択したPlanの本人確認へ
            </button>
        </form>
    @else
        <p class="mt-3 text-xs text-amber-200" data-mcp-plan-consent-disabled>
            現在は正式な許可を開始できません。
            認証プロバイダーの設定と本人IDの紐付けが必要です。
            共有の準備設定やコピー機能は引き続き利用できます。
        </p>
    @endif
</details>
