<nav class="mobile-tabbar pk-mobile-tabbar" aria-label="モバイルナビゲーション">
    <a href="{{ route('home') }}" data-canovia-nav-key="mobile-home"
       class="mobile-tabbar-link {{ request()->routeIs('home') || request()->routeIs('calendar.*') || request()->routeIs('my_plans.*') || request()->routeIs('plans.show') || request()->routeIs('plans.edit') || request()->routeIs('tasks.*') ? 'is-active' : '' }}"
       aria-current="{{ request()->routeIs('home') ? 'page' : 'false' }}">
        <span class="pk-tab-icon pk-tab-icon-home" aria-hidden="true">
            <svg viewBox="0 0 32 32"><path d="M8.2 20.6c-2.1 2-3.2 4-3 5.8 1.8.2 3.8-.9 5.8-3M18.2 5.7c3.4-1.1 6.2-1 6.4-.8.2.2.3 3-.8 6.4-1.2 3.8-4.2 7.3-8.7 9.4l-4.6-4.6c2.1-4.5 5.6-7.5 7.7-10.4Z"/><path d="m11.1 19.2 4.7 4.7M9 15.7l-3.3.8L4 19.6l5 .9M17.7 20.8l1 5 3.1-1.7.8-3.3"/><circle cx="19" cy="11" r="1.9"/></svg>
            <i></i><b></b>
        </span>
        <span>ホーム</span>
    </a>

    <a href="{{ route('roadmap.index') }}" data-canovia-nav-key="mobile-constellation"
       data-onboarding-target="roadmap-nav"
       class="mobile-tabbar-link {{ request()->routeIs('roadmap.*') ? 'is-active' : '' }}"
       aria-current="{{ request()->routeIs('roadmap.*') ? 'page' : 'false' }}">
        <span class="pk-tab-icon pk-tab-icon-roadmap" aria-hidden="true">
            <svg viewBox="0 0 32 32"><circle cx="8" cy="20" r="2.3"/><circle cx="15.5" cy="9" r="2.6"/><circle cx="24" cy="15.5" r="2.4"/><circle cx="21" cy="25" r="2"/><path d="M9.7 18.3 14 11.2M18 10.2l4.3 3.7M23.2 17.6l-1.5 5.3M10.2 20.8l8.9 3.5"/></svg>
            <i></i><b></b>
        </span>
        <span>星座</span>
    </a>

    <a href="{{ route('navigation.index') }}" data-canovia-nav-key="mobile-execution"
       data-onboarding-target="execution-nav"
       class="mobile-tabbar-link mobile-tabbar-primary {{ request()->routeIs('navigation.*') || request()->routeIs('work_sessions.*') || request()->routeIs('plans.tasks.guided_execution.*') || request()->routeIs('plans.tasks.execution_orchestration.*') || request()->routeIs('plans.tasks.study_*') ? 'is-active' : '' }}"
       aria-current="{{ request()->routeIs('navigation.*') || request()->routeIs('work_sessions.*') || request()->routeIs('plans.tasks.guided_execution.*') || request()->routeIs('plans.tasks.execution_orchestration.*') || request()->routeIs('plans.tasks.study_*') ? 'page' : 'false' }}">
        <span class="pk-tab-icon pk-tab-icon-today" aria-hidden="true">
            <svg viewBox="0 0 32 32"><path d="M7 16h13"/><path d="m16 11 5 5-5 5"/><circle cx="8" cy="16" r="3"/><path d="M23.5 8.5 26 6m-2.5 17.5L26 26"/></svg>
            <i></i><b></b>
        </span>
        <span>実行</span>
    </a>

    <a href="{{ route('timeline.index') }}" data-canovia-nav-key="mobile-timeline"
       class="mobile-tabbar-link {{ request()->routeIs('timeline.*') || request()->routeIs('achievements.*') ? 'is-active' : '' }}"
       aria-current="{{ request()->routeIs('timeline.*') || request()->routeIs('achievements.*') ? 'page' : 'false' }}">
        <span class="pk-tab-icon pk-tab-icon-timeline" aria-hidden="true">
            <svg viewBox="0 0 32 32"><circle cx="13" cy="13" r="8"/><path d="M13 9v4.7l3 1.8M19.3 20h5.1a2 2 0 0 1 2 2v1.8a2 2 0 0 1-2 2h-1.9l-2.2 1.7.3-1.7h-1.3a2 2 0 0 1-2-2V22"/></svg>
            <i></i><b></b>
        </span>
        <span>タイムライン</span>
    </a>
</nav>
