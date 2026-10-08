<nav class="mobile-tabbar pk-mobile-tabbar" aria-label="{{ $workspaceNavigationMode ? 'Workspace切り替え' : 'モバイルナビゲーション' }}" data-navigation-shell="{{ $workspaceNavigationMode ? 'workspace' : 'global' }}">
@if ($workspaceNavigationMode)
    @foreach ($workspaceNavigationOptions as $option)
        <a href="{{ route('workspace_modes.enter', ['workspaceMode' => $option->mode->value]) }}"
           data-canovia-nav-key="mobile-workspace-{{ $option->mode->value }}"
           class="mobile-tabbar-link {{ $workspaceNavigationMode === $option->mode ? 'is-active' : '' }}"
           aria-current="{{ $workspaceNavigationMode === $option->mode ? 'page' : 'false' }}">
            <span class="pk-tab-icon" aria-hidden="true"><svg viewBox="0 0 32 32"><circle cx="16" cy="16" r="10"/><path d="M11 16h10M16 11v10"/></svg></span>
            <span>{{ $option->label }}</span>
        </a>
    @endforeach
@else
    <a href="{{ route('home') }}" data-canovia-nav-key="mobile-home"
       class="mobile-tabbar-link {{ request()->routeIs('home') ? 'is-active' : '' }}"
       aria-current="{{ request()->routeIs('home') ? 'page' : 'false' }}">
        <span class="pk-tab-icon pk-tab-icon-home" aria-hidden="true"><svg viewBox="0 0 32 32"><path d="m4 15 12-10 12 10M8 13v14h16V13M13 27v-9h6v9"/></svg></span>
        <span>ホーム</span>
    </a>
    <a href="{{ route('workspace_modes.resume') }}" data-canovia-nav-key="mobile-workspace" class="mobile-tabbar-link" aria-current="false">
        <span class="pk-tab-icon" aria-hidden="true"><svg viewBox="0 0 32 32"><rect x="5" y="6" width="22" height="20" rx="3"/><path d="M5 13h22M13 13v13"/></svg></span>
        <span>Workspace</span>
    </a>
    <a href="{{ route('timeline.index') }}" data-canovia-nav-key="mobile-timeline"
       class="mobile-tabbar-link {{ request()->routeIs('timeline.*') || request()->routeIs('achievements.*') ? 'is-active' : '' }}"
       aria-current="{{ request()->routeIs('timeline.*') || request()->routeIs('achievements.*') ? 'page' : 'false' }}">
        <span class="pk-tab-icon pk-tab-icon-timeline" aria-hidden="true"><svg viewBox="0 0 32 32"><circle cx="16" cy="16" r="11"/><path d="M16 9v8l5 3"/></svg></span>
        <span>履歴</span>
    </a>
    <details class="mobile-tabbar-link relative" data-canovia-more>
        <summary class="flex min-h-full cursor-pointer list-none flex-col items-center justify-center" aria-label="その他の機能">
            <span class="pk-tab-icon" aria-hidden="true"><svg viewBox="0 0 32 32"><circle cx="7" cy="16" r="2"/><circle cx="16" cy="16" r="2"/><circle cx="25" cy="16" r="2"/></svg></span>
            <span>その他</span>
        </summary>
        <div class="fixed bottom-[calc(5.8rem+env(safe-area-inset-bottom))] right-3 z-[90] w-52 rounded-xl border border-slate-700 bg-slate-900 p-2 text-left shadow-2xl">
            <a href="{{ route('roadmap.index') }}" class="block rounded-lg px-3 py-3 text-sm hover:bg-slate-800">星座・全体俯瞰</a>
            <a href="{{ route('navigation.index') }}" class="block rounded-lg px-3 py-3 text-sm hover:bg-slate-800">従来の実行</a>
            <a href="{{ route('calendar.index') }}" class="block rounded-lg px-3 py-3 text-sm hover:bg-slate-800">カレンダー</a>
        </div>
    </details>
@endif
</nav>
