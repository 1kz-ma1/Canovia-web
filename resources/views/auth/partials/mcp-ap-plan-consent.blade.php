@php
    // This UI is deliberately owner-only and OFF until the private AP pilot
    // has been explicitly enabled. Never list or guess another person's Plan.
    $apPilot = config('canovia_mcp.ap_pilot_enabled') === true
        && config('canovia_mcp.study_read_enabled') === true
        && app(\App\Services\McpStudyPilotAccessPolicy::class)
            ->allowsOwnerId((int) $user->id);
    $apConsentService = app(\App\Services\McpExplicitPlanConsentService::class);
    $apConsentReady = $apPilot && $apConsentService->isEnabled()
        && $apConsentService->hasActiveLinkedIdentity($user);
    $apStudyPlans = $apPilot
        ? \App\Models\Plan::query()->where('user_id', $user->id)
            ->where('is_collaborative', false)->orderByDesc('id')
            ->limit(100)->get()->filter(
                fn ($p) => app(\App\Services\PlanCategoryProfileService::class)
                    ->forPlan($p)->key === 'study'
            )
        : collect();
@endphp
@if ($apPilot)
<section class="page-card min-w-0 p-6 sm:p-8" data-mcp-ap-private-consent>
    <p class="text-[10px] font-black uppercase tracking-[.14em] text-cyan-300">AP STUDY · PRIVATE PILOT</p>
    <h2 class="mt-2 text-lg font-bold text-slate-50">自分のAP学習PlanをChatGPTへ共有</h2>
    <p class="mt-2 text-xs leading-6 text-slate-400">
        初回は選択した学習Planの<strong>名前と登録期限だけ</strong>を、1日間共有します。
        Task・学習履歴・問題・回答・成績は対象外です。
        ここでの操作は自動共有ではなく、GitHubで本人再確認後に別途最終承認が必要です。
    </p>
    @if ($apConsentReady && $apStudyPlans->isNotEmpty())
        <form method="POST"
              action="{{ route('auth.account.mcp_plan_consent.start', ['plan' => '__PLAN__']) }}"
              class="mt-4 space-y-3"
              data-mcp-ap-consent-form
              onsubmit="this.action=this.action.replace('__PLAN__',this.elements['ap_plan_id'].value)">
            @csrf
            <label for="ap_plan_id" class="block text-xs font-semibold text-slate-200">本人所有の学習Plan</label>
            <select id="ap_plan_id" name="ap_plan_id" class="form-control w-full" required>
                @foreach ($apStudyPlans as $apPlan)
                    <option value="{{ $apPlan->id }}">{{ $apPlan->title }}</option>
                @endforeach
            </select>
            <input type="hidden" name="scope" value="overview">
            <input type="hidden" name="duration_days" value="1">
            <button type="submit" class="btn-secondary min-h-11 px-4 text-xs">
                このPlanの本人確認へ
            </button>
        </form>
    @elseif (! $apConsentReady)
        <p class="mt-3 text-xs text-amber-200">
            先にこの画面のGitHub本人ID紐付けを完了してください。MCPはまだ読み取りできません。
        </p>
    @else
        <p class="mt-3 text-xs text-amber-200">
            本人所有の学習Planが見つかりません。CanoviaでAP学習Planを確認してください。
        </p>
    @endif
</section>
@endif
