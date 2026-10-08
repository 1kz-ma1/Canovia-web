@php
    $surface = $instantSurface ?? match (true) {
        request()->routeIs('map.*') => 'map',
        request()->routeIs('inbox.*') => 'inbox',
        request()->routeIs('roadmap.*') => 'roadmap',
        request()->routeIs('timeline.*') => 'timeline',
        request()->routeIs('calendar.*') => 'calendar',
        default => 'home',
    };

    $routeName = match ($surface) {
        'map' => 'map.index',
        'inbox' => 'inbox.index',
        'roadmap' => 'roadmap.index',
        'timeline' => 'timeline.index',
        'calendar' => 'calendar.index',
        default => 'home',
    };

    $mobileSection = match ($surface) {
        'map' => 'Explore',
        'inbox' => 'Inbox',
        'roadmap' => '星座',
        'timeline' => 'タイムライン',
        'calendar' => 'カレンダー',
        default => 'ホーム',
    };

    $desktopHomeActive = in_array($surface, ['home', 'calendar'], true);
    $desktopTimelineActive = $surface === 'timeline';

    $mobileHomeActive = in_array($surface, ['home', 'calendar'], true);
    $mobileTimelineActive = $surface === 'timeline';

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
    $companionRoadmapPlan = $surface === 'roadmap' && (($plan ?? null) instanceof \App\Models\Plan)
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
    $companionSourcePath = $instantPath ?? request()->getRequestUri();
    $feedbackPath = ltrim(parse_url($instantPath ?? request()->getRequestUri(), PHP_URL_PATH) ?: '/', '/');

    $workspaceModeRegistry = app(\App\Services\WorkspaceModeRegistry::class);
    $workspaceModeContext = app(\App\Services\WorkspaceModeResolver::class)
        ->resolve(request());
    $workspaceModeDefinition = $workspaceModeRegistry->definition(
        $workspaceModeContext->mode,
    );

    $instantMeta = [
        'mobileSection' => $mobileSection,
        'workspaceMode' => [
            'key' => $workspaceModeDefinition->mode->value,
            'label' => $workspaceModeDefinition->label,
            'source' => $workspaceModeContext->source->value,
        ],
        'nav' => [
            'desktop-home' => [
                'className' => 'nav-link pk-nav-link whitespace-nowrap'.($desktopHomeActive ? ' nav-link-active' : ''),
                'ariaCurrent' => null,
            ],
            'desktop-workspace' => [
                'className' => 'nav-link pk-nav-link whitespace-nowrap',
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
            'mobile-workspace' => [
                'className' => 'mobile-tabbar-link',
                'ariaCurrent' => 'false',
            ],
            'mobile-timeline' => [
                'className' => 'mobile-tabbar-link'.($mobileTimelineActive ? ' is-active' : ''),
                'ariaCurrent' => $mobileTimelineActive ? 'page' : 'false',
            ],
        ],
        'feedbackContext' => [
            'page' => $feedbackPath,
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
<body data-route-name="{{ $routeName }}" data-workspace-mode="{{ $workspaceModeDefinition->mode->value }}">
    <script type="application/json" id="canovia-instant-meta">{!! json_encode($instantMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

    <div data-canovia-page data-canovia-route="{{ $routeName }}" data-workspace-mode="{{ $workspaceModeDefinition->mode->value }}" data-workspace-mode-label="{{ $workspaceModeDefinition->label }}">
        @if (session('status'))
            <div class="assistant-notice assistant-notice-info mb-6" data-auto-toast>{{ session('status') }}</div>
        @endif

        @guest
            @if ($surface === 'home')
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
                @include('layouts.partials.companion-palette')
            @endif
        @endauth
    </div>
</body>
</html>
