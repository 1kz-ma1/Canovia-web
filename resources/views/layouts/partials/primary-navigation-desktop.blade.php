@if ($workspaceNavigationMode)
    <nav class="pk-desktop-nav flex items-center gap-1 rounded-2xl border border-slate-800 bg-slate-900/75 p-1 text-sm" aria-label="Workspace切り替え" data-navigation-shell="workspace">
        @foreach ($workspaceNavigationOptions as $option)
            <a href="{{ route('workspace_modes.enter', ['workspaceMode' => $option->mode->value]) }}"
               data-canovia-nav-key="desktop-workspace-{{ $option->mode->value }}"
               class="nav-link pk-nav-link whitespace-nowrap {{ $workspaceNavigationMode === $option->mode ? 'nav-link-active' : '' }}"
               @if ($workspaceNavigationMode === $option->mode) aria-current="page" @endif>{{ $option->label }}</a>
        @endforeach
    </nav>
@else
    <nav class="pk-desktop-nav flex items-center gap-1 rounded-2xl border border-slate-800 bg-slate-900/75 p-1 text-sm" aria-label="メインナビゲーション" data-navigation-shell="global">
        <a href="{{ route('home') }}" data-canovia-nav-key="desktop-home" class="nav-link pk-nav-link whitespace-nowrap {{ request()->routeIs('home') ? 'nav-link-active' : '' }}">ホーム</a>
        <a href="{{ route('workspace_modes.resume') }}" data-canovia-nav-key="desktop-workspace" class="nav-link pk-nav-link whitespace-nowrap">Workspace</a>
        <a href="{{ route('timeline.index') }}" data-canovia-nav-key="desktop-timeline" class="nav-link pk-nav-link whitespace-nowrap {{ request()->routeIs('timeline.*') || request()->routeIs('achievements.*') ? 'nav-link-active' : '' }}">タイムライン</a>
        <details class="relative" data-canovia-more>
            <summary class="nav-link pk-nav-link cursor-pointer list-none whitespace-nowrap">その他 ▾</summary>
            <div class="absolute right-0 z-[60] mt-2 min-w-48 rounded-xl border border-slate-700 bg-slate-900 p-2 shadow-xl">
                <a href="{{ route('roadmap.index') }}" class="block rounded-lg px-3 py-2 text-sm hover:bg-slate-800">星座・全体俯瞰</a>
                <a href="{{ route('navigation.index') }}" class="block rounded-lg px-3 py-2 text-sm hover:bg-slate-800">従来の実行</a>
                <a href="{{ route('calendar.index') }}" class="block rounded-lg px-3 py-2 text-sm hover:bg-slate-800">カレンダー</a>
            </div>
        </details>
    </nav>
@endif
