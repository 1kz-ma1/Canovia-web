@php
    $routeName = request()->route()?->getName();
    $mobileSection = match (true) {
        request()->routeIs('inbox.*') => 'Inbox',
        request()->routeIs('roadmap.*') => 'ロードマップ',
        request()->routeIs('timeline.*') => 'タイムライン',
        request()->routeIs('calendar.*') => 'カレンダー',
        default => 'ホーム',
    };

    $desktopHomeActive = request()->routeIs('home') || request()->routeIs('calendar.*');
    $desktopInboxActive = request()->routeIs('inbox.*');
    $desktopRoadmapActive = request()->routeIs('roadmap.*');
    $desktopTimelineActive = request()->routeIs('timeline.*');

    $mobileHomeActive = request()->routeIs('home') || request()->routeIs('calendar.*');
    $mobileInboxActive = request()->routeIs('inbox.*');
    $mobileRoadmapActive = request()->routeIs('roadmap.*');
    $mobileTimelineActive = request()->routeIs('timeline.*');

    $feedbackPlan = request()->route('plan');
    $feedbackTask = request()->route('task');
    $feedbackWorkSession = request()->route('workSession');
    $feedbackPlanId = $feedbackPlan instanceof \App\Models\Plan
        ? $feedbackPlan->id
        : (($feedbackTask instanceof \App\Models\Task) ? $feedbackTask->plan_id : (($feedbackWorkSession instanceof \App\Models\WorkSession) ? $feedbackWorkSession->plan_id : null));
    $feedbackTaskId = $feedbackTask instanceof \App\Models\Task
        ? $feedbackTask->id
        : (($feedbackWorkSession instanceof \App\Models\WorkSession) ? $feedbackWorkSession->task_id : null);

    $companionRoutePlan = request()->route('plan');
    $companionRouteTask = request()->route('task');
    $companionRoadmapPlan = request()->routeIs('roadmap.*') && (($plan ?? null) instanceof \App\Models\Plan)
        ? $plan
        : null;
    $companionEntryPlan = $companionRoutePlan instanceof \App\Models\Plan
        ? $companionRoutePlan
        : $companionRoadmapPlan;
    $companionEntryTask = $companionRouteTask instanceof \App\Models\Task
        ? $companionRouteTask
        : null;
    $companionEntryType = match (true) {
        $companionEntryTask !== null => 'task',
        $companionEntryPlan !== null => 'plan',
        default => 'global',
    };
    $companionSourceRoute = $routeName;
    $companionSourcePath = request()->getRequestUri();

    $instantMeta = [
        'mobileSection' => $mobileSection,
        'nav' => [
            'desktop-home' => [
                'className' => 'nav-link pk-nav-link whitespace-nowrap'.($desktopHomeActive ? ' nav-link-active' : ''),
                'ariaCurrent' => null,
            ],
            'desktop-inbox' => [
                'className' => 'nav-link pk-nav-link whitespace-nowrap'.($desktopInboxActive ? ' nav-link-active' : ''),
                'ariaCurrent' => null,
            ],
            'desktop-roadmap' => [
                'className' => 'nav-link pk-nav-link whitespace-nowrap'.($desktopRoadmapActive ? ' nav-link-active' : ''),
                'ariaCurrent' => null,
            ],
            'desktop-timeline' => [
                'className' => 'nav-link pk-nav-link whitespace-nowrap'.($desktopTimelineActive ? ' nav-link-active' : ''),
                'ariaCurrent' => null,
            ],
            'desktop-calendar' => [
                'className' => 'header-secondary-link',
                'ariaCurrent' => null,
            ],
            'mobile-home' => [
                'className' => 'mobile-tabbar-link'.($mobileHomeActive ? ' is-active' : ''),
                'ariaCurrent' => $mobileHomeActive ? 'page' : 'false',
            ],
            'mobile-inbox' => [
                'className' => 'mobile-tabbar-link mobile-tabbar-primary'.($mobileInboxActive ? ' is-active' : ''),
                'ariaCurrent' => $mobileInboxActive ? 'page' : 'false',
            ],
            'mobile-roadmap' => [
                'className' => 'mobile-tabbar-link'.($mobileRoadmapActive ? ' is-active' : ''),
                'ariaCurrent' => $mobileRoadmapActive ? 'page' : 'false',
            ],
            'mobile-timeline' => [
                'className' => 'mobile-tabbar-link'.($mobileTimelineActive ? ' is-active' : ''),
                'ariaCurrent' => $mobileTimelineActive ? 'page' : 'false',
            ],
        ],
        'feedbackContext' => [
            'page' => request()->path(),
            'planId' => $feedbackPlanId ? (string) $feedbackPlanId : '',
            'taskId' => $feedbackTaskId ? (string) $feedbackTaskId : '',
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Canovia')</title>
</head>
<body data-route-name="{{ $routeName }}">
    <script type="application/json" id="canovia-instant-meta">{!! json_encode($instantMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

    <div data-canovia-page data-canovia-route="{{ $routeName }}">
        @if (session('status'))
            <div class="assistant-notice assistant-notice-info mb-6" data-auto-toast>{{ session('status') }}</div>
        @endif

        @guest
            @if (request()->routeIs('home'))
                <div class="guest-protection-banner mb-4">
                    <span><strong>Guestデータを保護しておくと安心です</strong>。ホーム画面版には専用の引き継ぎを使えますが、アカウントを作れば端末変更やCookie削除にも強くなります。</span>
                    <a href="{{ route('auth.register.form') }}">データを保護する</a>
                </div>
            @endif
        @endguest

        <div class="sync-status-chip is-passive" data-sync-status data-sync-mode="online" aria-live="polite">
            <span class="sync-status-dot"></span><span data-sync-status-label>オンライン</span>
        </div>

        @yield('content')
        @yield('offline_snapshot')
    </div>

    <div data-canovia-companion-slot>
        @auth
            @if ((bool) data_get(config('features.flags.'.\App\Enums\FeatureKey::CanoviaCompanion->value), 'enabled', false))
                <form
                    method="POST"
                    action="{{ route('companion.entry') }}"
                    class="fixed bottom-24 right-4 z-40 md:bottom-6 md:right-6"
                    data-mutation-once
                >
                    @csrf
                    <input type="hidden" name="entry_type" value="{{ $companionEntryType }}">
                    @if ($companionEntryPlan)
                        <input type="hidden" name="plan_id" value="{{ $companionEntryPlan->id }}">
                    @endif
                    @if ($companionEntryTask)
                        <input type="hidden" name="task_id" value="{{ $companionEntryTask->id }}">
                    @endif
                    <input type="hidden" name="source_path" value="{{ $companionSourcePath }}">
                    <input type="hidden" name="source_route" value="{{ $companionSourceRoute }}">
                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 rounded-full border border-violet-300/25 bg-slate-950/95 px-4 py-3 text-xs font-black text-violet-100 shadow-[0_16px_45px_rgba(15,23,42,.55)] backdrop-blur-xl transition hover:border-violet-300/45 hover:bg-violet-300/10"
                        aria-label="Canovia Companionを現在の文脈で開く"
                        title="Companion"
                    >
                        <span aria-hidden="true">✦</span>
                        <span class="hidden sm:inline">Companion</span>
                    </button>
                </form>
            @endif
        @endauth
    </div>
</body>
</html>
