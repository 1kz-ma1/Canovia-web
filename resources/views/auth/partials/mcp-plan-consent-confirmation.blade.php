@php
    $mcpVerifiedGrant = session(\App\Http\Controllers\McpOAuthPlanConsentController::VERIFIED_SESSION);
    $mcpConsentService = app(\App\Services\McpExplicitPlanConsentService::class);
    $mcpConsentPlan = is_array($mcpVerifiedGrant)
        && is_int($mcpVerifiedGrant['plan_id'] ?? null)
        ? \App\Models\Plan::query()->find($mcpVerifiedGrant['plan_id'])
        : null;
    $mcpGrantCanConfirm = $mcpConsentService->isEnabled()
        && is_array($mcpVerifiedGrant)
        && (int) ($mcpVerifiedGrant['actor_id'] ?? 0) === (int) $user->id
        && is_int($mcpVerifiedGrant['verified_at'] ?? null)
        && $mcpVerifiedGrant['verified_at'] <= now()->timestamp
        && now()->timestamp - $mcpVerifiedGrant['verified_at'] <= 300
        && $mcpConsentPlan !== null
        && $mcpConsentService->isPersonalDevelopmentOwner($user, $mcpConsentPlan)
        && is_string($mcpVerifiedGrant['identity_fingerprint'] ?? null)
        && $mcpConsentService->matchesLinkedIdentity($user, $mcpVerifiedGrant['identity_fingerprint'])
        && is_string($mcpVerifiedGrant['scope'] ?? null)
        && is_int($mcpVerifiedGrant['duration_days'] ?? null)
        && $mcpConsentService->isScopeAndDurationAllowed(
            $mcpVerifiedGrant['scope'], $mcpVerifiedGrant['duration_days'],
        );
@endphp
@if ($mcpGrantCanConfirm)
    <section class="page-card min-w-0 p-6 sm:p-8" data-mcp-plan-consent-confirm>
        <p class="text-[10px] font-black uppercase tracking-[.14em] text-cyan-300">
            OAUTH VERIFIED · FINAL CONSENT
        </p>
        <h2 class="mt-2 text-lg font-bold text-slate-50">Plan共有の最終確認</h2>
        <p class="mt-2 text-xs leading-5 text-slate-400">
            認証プロバイダーで本人IDを再確認しました。
            以下の内容をCanoviaに保存するには、別途「許可を確定」を押してください。
            保存してもMCPの直接読み取り機能はまだ有効になりません。
        </p>
        <div class="mt-3 min-w-0 rounded-xl border border-white/10 bg-slate-950/25 p-3 text-xs leading-6 text-slate-200">
            <p class="break-words">対象Plan：{{ $mcpConsentPlan->title }}</p>
            <p>接続先：ChatGPT（承認対象クライアント）</p>
            <p>共有範囲：{{ $mcpVerifiedGrant['scope'] === 'tasks' ? '概要＋進行中Task' : '概要のみ' }}</p>
            <p>有効期間：承認から{{ $mcpVerifiedGrant['duration_days'] }}日</p>
            <p class="text-amber-200">Task説明・証拠・認証情報は共有対象外です。</p>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('auth.account.mcp_plan_consent.confirm') }}">
                @csrf
                <button type="submit" class="btn-secondary min-h-11 px-4 text-xs">
                    このPlanの共有許可を確定
                </button>
            </form>
            <form method="POST" action="{{ route('auth.account.mcp_plan_consent.cancel') }}">
                @csrf
                <button type="submit" class="btn-secondary min-h-11 px-4 text-xs">
                    許可せずに中止
                </button>
            </form>
        </div>
    </section>
@elseif (is_array($mcpVerifiedGrant))
    <section class="page-card min-w-0 p-4 text-xs text-amber-200" data-mcp-plan-consent-invalid>
        Planの共有許可は確定できなくなりました。権限・期限が変更されている可能性があります。
        <form method="POST" action="{{ route('auth.account.mcp_plan_consent.cancel') }}" class="mt-3">
            @csrf
            <button type="submit" class="btn-secondary min-h-11 px-4 text-xs">未完了の承認を破棄</button>
        </form>
    </section>
@endif
