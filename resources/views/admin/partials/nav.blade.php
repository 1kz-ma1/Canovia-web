<nav class="mb-6 flex flex-wrap items-center gap-2 rounded-2xl border border-slate-800/90 bg-slate-950/55 p-2" aria-label="管理画面">
    <a
        href="{{ route('admin.dashboard') }}"
        class="{{ request()->routeIs('admin.dashboard') ? 'btn-primary' : 'btn-secondary' }} px-3 py-2 text-xs"
    >概要</a>
    <a
        href="{{ route('admin.feedback.index') }}"
        class="{{ request()->routeIs('admin.feedback.*') && ! request()->routeIs('admin.feedback.login') ? 'btn-primary' : 'btn-secondary' }} px-3 py-2 text-xs"
    >フィードバック</a>
    <a
        href="{{ route('admin.telemetry.index') }}"
        class="{{ request()->routeIs('admin.telemetry.*') ? 'btn-primary' : 'btn-secondary' }} px-3 py-2 text-xs"
    >計画作成・更新の診断</a>
    <a
        href="{{ route('admin.github.index') }}"
        class="{{ request()->routeIs('admin.github.*') ? 'btn-primary' : 'btn-secondary' }} px-3 py-2 text-xs"
    >GitHub連携</a>
    <a
        href="{{ route('admin.question_packs.index') }}"
        class="{{ request()->routeIs('admin.question_packs.*') ? 'btn-primary' : 'btn-secondary' }} px-3 py-2 text-xs"
    >問題集</a>
    <a
        href="{{ route('admin.practice_demand.index') }}"
        class="{{ request()->routeIs('admin.practice_demand.*') ? 'btn-primary' : 'btn-secondary' }} px-3 py-2 text-xs"
    >演習需要</a>
    <a
        href="{{ route('admin.goal_pattern_demand.index') }}"
        class="{{ request()->routeIs('admin.goal_pattern_demand.*') ? 'btn-primary' : 'btn-secondary' }} px-3 py-2 text-xs"
    >Goal需要</a>
    @if (config('canovia.study_scenario_lab_enabled'))
        <a
            href="{{ route('admin.study_scenarios.index') }}"
            class="{{ request()->routeIs('admin.study_scenarios.*') ? 'btn-primary' : 'btn-secondary' }} px-3 py-2 text-xs"
        >Study Lab</a>
    @endif
    <a
        href="{{ route('admin.economy.index') }}"
        class="{{ request()->routeIs('admin.economy.*') ? 'btn-primary' : 'btn-secondary' }} px-3 py-2 text-xs"
    >Economy</a>
    <span class="ml-auto hidden text-[11px] font-semibold text-slate-500 sm:inline">CANOVIA ADMIN</span>
</nav>
