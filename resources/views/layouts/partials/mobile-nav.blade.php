<nav class="mobile-tabbar pk-mobile-tabbar" aria-label="モバイルナビゲーション">
    <a href="{{ route('home') }}" data-canovia-nav-key="mobile-home"
       class="mobile-tabbar-link {{ request()->routeIs('home') || request()->routeIs('calendar.*') || request()->routeIs('my_plans.*') || request()->routeIs('plans.show') || request()->routeIs('plans.edit') || request()->routeIs('tasks.*') || request()->routeIs('work_sessions.*') || request()->routeIs('navigation.*') ? 'is-active' : '' }}"
       aria-current="{{ request()->routeIs('home') || request()->routeIs('calendar.*') || request()->routeIs('my_plans.*') || request()->routeIs('plans.show') || request()->routeIs('plans.edit') || request()->routeIs('tasks.*') || request()->routeIs('work_sessions.*') ? 'page' : 'false' }}">
        <span class="pk-tab-icon pk-tab-icon-home" aria-hidden="true">
            <svg viewBox="0 0 32 32"><path d="M8.2 20.6c-2.1 2-3.2 4-3 5.8 1.8.2 3.8-.9 5.8-3M18.2 5.7c3.4-1.1 6.2-1 6.4-.8.2.2.3 3-.8 6.4-1.2 3.8-4.2 7.3-8.7 9.4l-4.6-4.6c2.1-4.5 5.6-7.5 7.7-10.4Z"/><path d="m11.1 19.2 4.7 4.7M9 15.7l-3.3.8L4 19.6l5 .9M17.7 20.8l1 5 3.1-1.7.8-3.3"/><circle cx="19" cy="11" r="1.9"/></svg>
            <i></i><b></b>
        </span>
        <span>ホーム</span>
    </a>

    <a href="{{ route('inbox.index') }}" data-canovia-nav-key="mobile-inbox"
       data-onboarding-target="inbox-nav"
       class="mobile-tabbar-link mobile-tabbar-primary {{ request()->routeIs('inbox.*') ? 'is-active' : '' }}"
       aria-current="{{ request()->routeIs('inbox.*') ? 'page' : 'false' }}">
        <span class="pk-tab-icon pk-tab-icon-today" aria-hidden="true">
            <svg viewBox="0 0 32 32"><path d="M6 7h20v18H6V7Z"/><path d="M6 18h6l2.5 3h3L20 18h6"/></svg>
            <i></i><b></b>
        </span>
        <span>Inbox</span>
    </a>

    <a href="{{ route('roadmap.index') }}" data-canovia-nav-key="mobile-roadmap"
       data-onboarding-target="roadmap-nav"
       class="mobile-tabbar-link {{ request()->routeIs('roadmap.*') || request()->routeIs('chat.*') || request()->routeIs('achievements.*') || request()->routeIs('plans.review_assistant.*') ? 'is-active' : '' }}"
       aria-current="{{ request()->routeIs('roadmap.*') || request()->routeIs('chat.*') || request()->routeIs('achievements.*') || request()->routeIs('plans.review_assistant.*') ? 'page' : 'false' }}">
        <span class="pk-tab-icon pk-tab-icon-roadmap" aria-hidden="true">
            <svg viewBox="0 0 32 32"><path d="M6 8 12 5l8 3 6-3v19l-6 3-8-3-6 3V8Zm6-3v19M20 8v19"/></svg>
            <i></i><b></b>
        </span>
        <span>ロードマップ</span>
    </a>

    <a href="{{ route('timeline.index') }}" data-canovia-nav-key="mobile-timeline"
       class="mobile-tabbar-link {{ request()->routeIs('timeline.*') ? 'is-active' : '' }}"
       aria-current="{{ request()->routeIs('timeline.*') ? 'page' : 'false' }}">
        <span class="pk-tab-icon pk-tab-icon-timeline" aria-hidden="true">
            <svg viewBox="0 0 32 32"><circle cx="13" cy="13" r="8"/><path d="M13 9v4.7l3 1.8M19.3 20h5.1a2 2 0 0 1 2 2v1.8a2 2 0 0 1-2 2h-1.9l-2.2 1.7.3-1.7h-1.3a2 2 0 0 1-2-2V22"/></svg>
            <i></i><b></b>
        </span>
        <span>タイムライン</span>
    </a>
</nav>
