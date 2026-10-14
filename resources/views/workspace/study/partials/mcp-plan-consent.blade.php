@php
    $studyMcp = app(\App\Services\McpExplicitPlanConsentService::class);
    $studyMcpReady = auth()->check()
        && $studyMcp->isEligibleMcpPlanOwner(auth()->user(), $plan)
        && $studyMcp->isEnabled()
        && $studyMcp->hasActiveLinkedIdentity(auth()->user());
@endphp
<details class="page-card p-4 sm:p-5" data-mcp-study-plan-consent>
    <summary class="cursor-pointer text-sm font-bold text-slate-100">
        ChatGPTとこの学習Planを連携する
        <span class="ml-1 text-xs font-normal text-amber-200">
            {{ $studyMcpReady ? '共有範囲を選択' : '本人確認の準備が必要' }}
        </span>
    </summary>
    <p class="mt-2 text-xs leading-5 text-slate-400">
        AP学習などの支援用に、計画名・登録した期限・Taskの進捗を必要な範囲だけChatGPTに共有できます。
        演習問題・回答・採点詳細やプロフィールは共有しません。演習の集計は別途明示的に選んだ場合だけ共有できます。
        共有には外部サービスでの本人再確認と最終承認が必要です。
    </p>
    @if ($studyMcpReady)
        <form method="POST"
              action="{{ route('auth.account.mcp_plan_consent.start', ['plan' => $plan->id]) }}"
              class="mt-3 space-y-3" data-mcp-study-plan-consent-start>
            @csrf
            <p class="text-xs font-semibold text-slate-200">対象：{{ $plan->title }}</p>
            <label class="block text-xs text-slate-300">
                共有する内容
                <select class="form-control mt-1 block w-full" name="scope" required>
                    <option value="overview">計画名・期限のみ</option>
                    <option value="tasks">計画名・期限＋Taskの名前・状態・進捗</option>
                    @if (config('canovia_mcp.study_summary_read_enabled') === true)
                        <option value="study_progress">計画・Task＋反映済み演習の得点・弱点集計</option>
                    @endif
                </select>
            </label>
            <label class="block text-xs text-slate-300">
                有効期限
                <select class="form-control mt-1 block w-full" name="duration_days" required>
                    <option value="1">1日</option>
                    <option value="7" selected>7日</option>
                    <option value="30">30日</option>
                    <option value="until_revoked">取り消すまで</option>
                </select>
            </label>
            <button type="submit" class="btn-secondary min-h-11 px-4 text-xs">
                この学習Planの本人確認へ
            </button>
        </form>
    @else
        <p class="mt-3 text-xs text-amber-200" data-mcp-study-plan-consent-unavailable>
            現在は共有を開始できません。アカウントのChatGPT連携・本人確認の設定を確認してください。
        </p>
    @endif
</details>
