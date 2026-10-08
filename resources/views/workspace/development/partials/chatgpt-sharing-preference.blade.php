<details class="page-card min-w-0 p-4 sm:p-5" data-development-chatgpt-sharing-preference>
    <summary class="cursor-pointer text-sm font-black text-slate-100">
        ChatGPT共有の準備設定 <span class="ml-1 text-xs font-normal text-amber-200">未接続</span>
    </summary>
    <p class="mt-3 text-xs leading-5 text-slate-400">
        将来ChatGPTと接続する際に希望する共有範囲を保存できます。
        <strong class="font-bold text-slate-200">保存してもChatGPTには接続されず、外部からの閲覧権限も発生しません。</strong>
        実際の接続時には改めて本人確認と許可が必要です。
    </p>

    @if ($sharingPreference)
        @php
            $isRevoked = $sharingPreference->status === \App\Models\DevelopmentAiSharingPreference::STATUS_REVOKED;
            $isExpired = ! $isRevoked && ! $sharingPreference->isPrepared();
        @endphp
        <div class="mt-3 rounded-xl border border-white/10 bg-slate-950/20 p-3 text-xs leading-5 text-slate-300" data-chatgpt-preference-summary>
            <p>接続先：ChatGPT（準備情報のみ）</p>
            <p>保存範囲：{{ $sharingPreference->scope === 'tasks' ? 'Plan概要＋進行中Task' : 'Plan概要のみ' }}</p>
            <p>期限：{{ $sharingPreference->expires_at?->format('Y-m-d H:i') ?? '不明' }}</p>
            <p>
                設定状態：
                @if ($isRevoked)
                    <span class="text-slate-400">取り消し済み</span>
                @elseif ($isExpired)
                    <span class="text-amber-200">期限切れ（接続なし）</span>
                @else
                    <span class="text-cyan-300">準備保存済み（接続なし）</span>
                @endif
            </p>
        </div>
    @endif

    @if ($sharingEligible)
        <form method="POST"
            action="{{ route('workspace.development.sharing_preference.store', ['plan' => $plan->id]) }}"
            class="mt-4 space-y-3" data-chatgpt-preference-form>
            @csrf
            <div class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="block min-w-0 text-xs text-slate-300">
                    将来の共有範囲
                    <select name="scope" required class="form-control mt-1 block w-full min-w-0 max-w-full">
                        <option value="overview" @selected(($sharingPreference?->scope ?? 'overview') === 'overview')>Plan概要のみ（初期値）</option>
                        <option value="tasks" @selected(($sharingPreference?->scope ?? 'overview') === 'tasks')>概要＋進行中Task</option>
                    </select>
                </label>
                <label class="block min-w-0 text-xs text-slate-300">
                    準備設定の有効期間
                    <select name="duration_days" required class="form-control mt-1 block w-full min-w-0 max-w-full">
                        <option value="1">1日</option>
                        <option value="7" selected>7日</option>
                        <option value="30">30日</option>
                    </select>
                </label>
            </div>
            <p class="text-[11px] leading-5 text-slate-500">
                この期間は準備設定の期限です。将来のアクセストークンの有効期限ではありません。
                Task内容を共有する場合も、実際のOAuth連携時に改めて許可を求めます。
            </p>
            <button type="submit" class="btn-secondary min-h-11 px-4 text-xs">
                準備設定を保存（まだ接続しない）
            </button>
        </form>
    @else
        <p class="mt-3 text-xs text-amber-200">
            このPlanは現在、個人開発の共有条件を満たさないため準備設定を保存できません。
            既存の準備設定の取り消しは可能です。
        </p>
    @endif

    @if ($sharingPreference && $sharingPreference->status !== \App\Models\DevelopmentAiSharingPreference::STATUS_REVOKED)
        <form method="POST"
            action="{{ route('workspace.development.sharing_preference.destroy', ['plan' => $plan->id]) }}"
            class="mt-3" data-chatgpt-preference-revoke>
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-secondary min-h-11 px-4 text-xs">
                準備設定を取り消す
            </button>
        </form>
    @endif
</details>
