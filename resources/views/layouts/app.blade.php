@php
    $focusMode = request()->routeIs('work_sessions.active');
    $isCoreScreen = request()->routeIs('home') || request()->routeIs('map.index') || request()->routeIs('inbox.index') || request()->routeIs('navigation.index') || request()->routeIs('roadmap.index') || request()->routeIs('timeline.index') || request()->routeIs('calendar.index');
    $mobileSection = match (true) {
        request()->routeIs('map.*') => 'Explore',
        request()->routeIs('inbox.*') => 'Inbox',
        request()->routeIs('navigation.*'),
        request()->routeIs('work_sessions.*'),
        request()->routeIs('plans.tasks.guided_execution.*'),
        request()->routeIs('plans.tasks.execution_orchestration.*'),
        request()->routeIs('plans.tasks.study_*') => '実行',
        request()->routeIs('roadmap.*') => '星座',
        request()->routeIs('timeline.*'), request()->routeIs('achievements.*') => 'タイムライン',
        request()->routeIs('calendar.*') => 'カレンダー',
        request()->routeIs('future_memos.*') => '未来メモ',
        request()->routeIs('feedback.*') => 'Canovia Future',
        request()->routeIs('legal.privacy') => 'プライバシー',
        request()->routeIs('legal.support') => 'サポート',
        request()->routeIs('github_workflow.*') => 'GitHub',
        request()->routeIs('companion.*') => 'Companion',
        request()->routeIs('chat.*'), request()->routeIs('plans.review_assistant.*') => '計画を更新',
        request()->routeIs('public_plans.*') => '共有プラン',
        request()->routeIs('plans.*'), request()->routeIs('tasks.*'), request()->routeIs('my_plans.*') => '計画',
        default => 'ホーム',
    };

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
        $companionEntryTask && request()->routeIs('plans.tasks.guided_execution.*') => 'guided_execution',
        $companionEntryTask !== null => 'task',
        $companionEntryPlan !== null => 'plan',
        default => 'global',
    };
    $companionSourceRoute = request()->route()?->getName();
    $companionSourcePath = request()->getRequestUri();

    $onboardingVersion = (int) config('canovia.onboarding_version', 1);
    $isPublicLegalSurface = request()->routeIs('legal.*');
    // Early Access first use is owned by FirstRun + Personalization, not the
    // legacy seven-step conversation tour. Explicit replay stays available.
    $usesPersonalizationFirstRun = app(\App\Services\ReleaseLevelService::class)
        ->allowsMinimum(\App\Enums\ReleaseLevel::EarlyAccessCore, auth()->user(), request());
    $onboardingAuto = ! $focusMode
        && ! $isPublicLegalSurface
        && ! $usesPersonalizationFirstRun
        && (! auth()->check() || (int) auth()->user()->onboarding_version < $onboardingVersion);
    $releaseNotes = \App\Support\ReleaseNotes::all();
    $latestReleaseKey = (string) data_get($releaseNotes->first(), 'key', '');
    $currentUser = auth()->user();
    $isSuperAdmin = $currentUser
        ? app(\App\Services\AdminAccessService::class)->isSuperAdmin($currentUser)
        : false;
    $adminPreviewMode = $isSuperAdmin
        ? app(\App\Services\AdminPreviewContext::class)->mode($currentUser)
        : null;
    $releaseLevelService = app(\App\Services\ReleaseLevelService::class);
    $currentReleaseLevel = $releaseLevelService->levelFor($currentUser, request());
    $publicReleaseLevel = $releaseLevelService->publicLevel();
    $adminReleasePreviewLevel = $isSuperAdmin
        ? $releaseLevelService->adminPreview($currentUser, request())
        : null;
    $earlyAccessActive = ! $focusMode
        && ! request()->routeIs('admin.*')
        && ! request()->routeIs('legal.*')
        && app(\App\Services\EarlyAccessService::class)
            ->activeFor($currentUser, request());
    $earlyAccessProductPreviewAvailable = $currentReleaseLevel
        ->isAtLeast(\App\Enums\ReleaseLevel::ProductPreview);
    $hasPremiumCore = $currentUser && ! $isSuperAdmin
        ? app(\App\Services\ProductGrantService::class)->hasEffectiveProduct($currentUser, \App\Enums\ProductKey::PremiumCore)
        : false;
    $currentAccessLabel = $isSuperAdmin
        ? match ($adminPreviewMode) {
            'free' => 'Free プレビュー',
            'premium' => 'Premium プレビュー',
            default => 'Super Admin',
        }
        : ($hasPremiumCore ? 'Premium' : 'Free');
    $workspaceModeRegistry = app(\App\Services\WorkspaceModeRegistry::class);
    $workspaceModeContext = app(\App\Services\WorkspaceModeResolver::class)
        ->resolve(request());
    $workspaceModeDefinition = $workspaceModeRegistry->definition(
        $workspaceModeContext->mode,
    );
    $workspaceModeOptions = $workspaceModeRegistry->available();
@endphp
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="canovia-client-performance-url" content="{{ route('performance.client') }}">
    <meta name="canovia-native-bridge-version" content="1">
    <meta name="canovia-native-session-mode" content="web_cookie">
    <meta name="canovia-native-deep-link-origin" content="{{ url('/') }}">
    <meta name="theme-color" content="#0A0F1E">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Canovia">
    <link rel="manifest" href="{{ route('pwa.manifest') }}" crossorigin="use-credentials">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" sizes="180x180" href="/icons/icon-180.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Klee+One:wght@400;600&family=Zen+Kurenaido&display=swap" rel="stylesheet">
    <title>@yield('title', 'Canovia')</title>

    <script>
        (() => {
            try {
                const root = document.documentElement;
                // Canovia v33: Dark is the single official theme for now.
                // Keep the legacy storage key so previously installed PWAs migrate cleanly.
                localStorage.setItem('pacekeeper.ui.theme', 'dark');
                const storedAccent = localStorage.getItem('pacekeeper.ui.accent') || 'sky';
                const storedDensity = localStorage.getItem('pacekeeper.ui.density');
                const isMobile = window.matchMedia('(max-width: 767px)').matches;
                const density = storedDensity || (isMobile ? 'standard' : 'compact');
                root.dataset.uiTheme = 'dark';
                root.dataset.themeResolved = 'dark';
                root.dataset.uiAccent = storedAccent;
                root.dataset.uiDensity = density;
            } catch (_) {}
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-release-level="{{ $currentReleaseLevel->value }}" data-release-level-label="{{ $currentReleaseLevel->label() }}" data-focus-mode="{{ $focusMode ? '1' : '0' }}" data-workspace-mode="{{ $workspaceModeDefinition->mode->value }}" data-workspace-mode-source="{{ $workspaceModeContext->source->value }}" data-onboarding-version="{{ $onboardingVersion }}" data-onboarding-auto="{{ $onboardingAuto ? '1' : '0' }}" data-onboarding-authenticated="{{ auth()->check() ? '1' : '0' }}" data-pwa-install-url="{{ route('pwa.install.prepare') }}" data-route-name="{{ request()->route()?->getName() }}" data-canovia-surface="{{ request()->routeIs('map.*') ? 'explore' : (request()->routeIs('home') ? 'home' : 'app') }}" class="pk-cosmic-shell min-h-screen bg-slate-950 text-slate-100 antialiased {{ $focusMode ? 'pace-focus-mode' : '' }}">
    <div class="pk-cosmic-backdrop pointer-events-none fixed inset-0 -z-10 overflow-hidden" aria-hidden="true">
        <span class="pk-space-glow pk-space-glow-a"></span>
        <span class="pk-space-glow pk-space-glow-b"></span>
        <span class="pk-space-star pk-space-star-a"></span>
        <span class="pk-space-star pk-space-star-b"></span>
        <span class="pk-space-star pk-space-star-c"></span>
    </div>

    @unless ($focusMode)
        <header class="desktop-app-header sticky top-0 z-50 hidden border-b border-slate-800/90 bg-slate-950/90 backdrop-blur-xl md:block">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-6 py-2.5">
                <div class="flex min-w-0 items-center gap-2.5">
                    <a href="{{ route('home') }}" class="pk-brand-lockup pk-canovia-header-lockup group inline-flex min-w-0 items-center gap-3" aria-label="Canovia ホーム">
                        <img src="/brand/canovia-wordmark.png" alt="Canovia カノーヴィア" class="pk-canovia-header-wordmark">
                        <span class="sr-only">Canovia - 未来までの航路を、一緒に。</span>
                    </a>

                    @include('layouts.partials.workspace-mode-bar', [
                        'workspaceModeRegistry' => $workspaceModeRegistry,
                        'workspaceModeContext' => $workspaceModeContext,
                        'workspaceModeDefinition' => $workspaceModeDefinition,
                        'workspaceModeOptions' => $workspaceModeOptions,
                        'workspaceModeInline' => true,
                    ])
                </div>

                <nav class="pk-desktop-nav flex flex-wrap items-center gap-1 rounded-2xl border border-slate-800 bg-slate-900/75 p-1 text-sm shadow-lg shadow-slate-950/20" aria-label="メインナビゲーション">
                    <a href="{{ route('home') }}" data-canovia-nav-key="desktop-home" class="nav-link pk-nav-link whitespace-nowrap {{ request()->routeIs('home') || request()->routeIs('calendar.*') || request()->routeIs('my_plans.*') || request()->routeIs('plans.show') || request()->routeIs('plans.dashboard') || request()->routeIs('plans.edit') || request()->routeIs('tasks.*') ? 'nav-link-active' : '' }}"><span class="pk-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6.8 15.7c-1.8 1.7-2.7 3.4-2.5 4.9 1.5.2 3.2-.7 4.9-2.5M14.5 4.2c2.8-.9 5.2-.9 5.3-.8.1.1.1 2.5-.8 5.3-1 3.2-3.5 6.1-7.2 7.8L7.9 12.6c1.7-3.7 4.6-6.2 6.6-8.4Z"/><path d="m9.1 15 4 4M7.4 12.1l-2.7.6-1.5 2.6 4.2.8M14.8 16.3l.8 4.2 2.6-1.5.6-2.7"/><circle cx="15.2" cy="8.8" r="1.6"/></svg><i></i></span><span>ホーム</span></a>
                    <a href="{{ route('roadmap.index') }}" data-canovia-nav-key="desktop-constellation" data-onboarding-target="roadmap-nav" class="nav-link pk-nav-link whitespace-nowrap {{ request()->routeIs('roadmap.*') ? 'nav-link-active' : '' }}"><span class="pk-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="6" cy="15" r="1.6"/><circle cx="11.5" cy="6.5" r="1.8"/><circle cx="18" cy="11" r="1.7"/><circle cx="15.8" cy="19" r="1.5"/><path d="m7.2 13.8 3.1-5.6m2.8-.7 3.5 2.4m.9 2.7-1.1 4.8m-9-.9 7 2.1"/></svg><i></i></span><span>星座</span></a>
                    <a href="{{ route('navigation.index') }}" data-canovia-nav-key="desktop-execution" data-onboarding-target="execution-nav" class="nav-link pk-nav-link whitespace-nowrap {{ request()->routeIs('navigation.*') || request()->routeIs('work_sessions.*') || request()->routeIs('plans.tasks.guided_execution.*') || request()->routeIs('plans.tasks.execution_orchestration.*') || request()->routeIs('plans.tasks.study_*') ? 'nav-link-active' : '' }}"><span class="pk-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12h10"/><path d="m12 8 4 4-4 4"/><circle cx="5" cy="12" r="2"/><path d="m18.5 6.5 1.8-1.8m-1.8 12.8 1.8 1.8"/></svg><i></i></span><span>実行</span></a>
                    <a href="{{ route('timeline.index') }}" data-canovia-nav-key="desktop-timeline" class="nav-link pk-nav-link whitespace-nowrap {{ request()->routeIs('timeline.*') || request()->routeIs('achievements.*') ? 'nav-link-active' : '' }}"><span class="pk-nav-icon pk-nav-icon-timeline" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="10" cy="10" r="6"/><path d="M10 7v3.5l2.4 1.5M14.8 15.5h4.4a1.8 1.8 0 0 1 1.8 1.8v1.8a1.8 1.8 0 0 1-1.8 1.8h-1.7l-1.8 1.4.2-1.4h-1.1a1.8 1.8 0 0 1-1.8-1.8v-1.8"/></svg><i></i></span><span>タイムライン</span></a>
                </nav>

                <div class="hidden items-center gap-2 lg:flex">
                    <a href="{{ route('calendar.index') }}" data-canovia-nav-key="desktop-calendar" class="header-secondary-link">カレンダー</a>
                    <a href="{{ route('github_workflow.index') }}" data-canovia-nav-key="desktop-github" class="header-secondary-link {{ request()->routeIs('github_workflow.*') ? 'is-active' : '' }}">GitHub</a>
                    <button type="button" class="header-secondary-link canovia-guide-desktop-trigger" data-guide-open aria-label="Canovia Guideを開く">ガイド</button>
                    <button type="button" class="header-secondary-link release-notes-desktop-trigger" data-release-notes-open aria-label="Canoviaの更新情報を見る">更新情報<span class="release-notes-new-dot" data-release-notes-new aria-hidden="true"></span></button>
                    <button type="button" class="ui-settings-trigger" data-ui-settings-open aria-label="設定を開く">設定</button>
                    @auth
                        <span class="max-w-36 truncate text-xs font-semibold text-slate-400">{{ auth()->user()->name }}</span>
                        <form method="POST" action="{{ route('auth.logout') }}" data-clear-offline-state>
                            @csrf
                            <button type="submit" class="btn-secondary px-3 py-2 text-xs">ログアウト</button>
                        </form>
                    @else
                        <a href="{{ route('auth.login.form') }}" class="btn-secondary px-3 py-2 text-xs">ログイン</a>
                        <a href="{{ route('auth.register.form') }}" class="btn-primary px-3 py-2 text-xs">アカウント作成</a>
                    @endauth
                </div>
            </div>
        </header>

        <header class="mobile-app-header md:hidden">
            <div class="mobile-app-header-inner">
                @unless ($isCoreScreen)
                    <button type="button" class="mobile-back-button" data-mobile-back aria-label="前の画面に戻る">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                @else
                    <a href="{{ route('home') }}" class="mobile-brand-mark pk-mobile-brand-mark" aria-label="Canovia ホーム"><img src="/brand/logo-mark.svg" alt="" width="32" height="32"></a>
                @endunless
                <div class="min-w-0 flex-1">
                    <div class="flex min-w-0 items-center gap-1.5">
                        <p class="truncate text-[11px] font-semibold uppercase tracking-[0.18em] text-sky-300">CANOVIA</p>
                        @include('layouts.partials.workspace-mode-bar', [
                            'workspaceModeRegistry' => $workspaceModeRegistry,
                            'workspaceModeContext' => $workspaceModeContext,
                            'workspaceModeDefinition' => $workspaceModeDefinition,
                            'workspaceModeOptions' => $workspaceModeOptions,
                            'workspaceModeCompact' => true,
                        ])
                    </div>
                    <p class="truncate text-sm font-bold text-slate-50" data-mobile-section-label>{{ $mobileSection }}</p>
                </div>
                <button type="button" class="mobile-guide-action" data-guide-open aria-label="Canovia Guideを開く" title="ガイド">
                    <span class="mobile-guide-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="8.5"/>
                            <path d="m14.8 9.2-2 5.6-5.6 2 2-5.6 5.6-2Z"/>
                            <circle cx="12" cy="12" r="1.1"/>
                        </svg>
                    </span>
                    <span>ガイド</span>
                </button>
                <button type="button" class="mobile-release-action" data-release-notes-open aria-label="Canoviaの更新情報を見る" title="更新情報">
                    <span class="mobile-release-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 3.5a8.5 8.5 0 1 0 8.1 5.9"/>
                            <path d="M20.5 3.5v5.2h-5.2"/>
                            <path d="M12 7.5v5l3.2 1.9"/>
                        </svg>
                        <i class="release-notes-new-dot" data-release-notes-new></i>
                    </span>
                    <span>更新情報</span>
                </button>
                <a href="{{ route('feedback.index') }}" class="mobile-feedback-action" aria-label="Canovia Futureを開く" title="フィードバック">
                    <span class="mobile-feedback-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <path d="M5.2 5.7h13.6a2.2 2.2 0 0 1 2.2 2.2v7.2a2.2 2.2 0 0 1-2.2 2.2h-7.1L7.3 20l.9-2.7h-3A2.2 2.2 0 0 1 3 15.1V7.9a2.2 2.2 0 0 1 2.2-2.2Z"/>
                            <path d="M8 11.5h.01M12 11.5h.01M16 11.5h.01"/>
                        </svg>
                        <i></i>
                    </span>
                    <span>フィードバック</span>
                </a>
                <button type="button" class="mobile-utility-button" data-ui-settings-open aria-label="設定を開く" title="設定">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.2a3.8 3.8 0 1 0 0 7.6 3.8 3.8 0 0 0 0-7.6Z"/><path d="M19.2 13.3a7.6 7.6 0 0 0 .1-1.3 7.6 7.6 0 0 0-.1-1.3l2-1.5-2-3.4-2.4 1a7.8 7.8 0 0 0-2.2-1.3L14.3 3h-4.6l-.4 2.5a7.8 7.8 0 0 0-2.2 1.3l-2.4-1-2 3.4 2 1.5a7.6 7.6 0 0 0-.1 1.3 7.6 7.6 0 0 0 .1 1.3l-2 1.5 2 3.4 2.4-1a7.8 7.8 0 0 0 2.2 1.3l.4 2.5h4.6l.4-2.5a7.8 7.8 0 0 0 2.2-1.3l2.4 1 2-3.4-2-1.5Z"/></svg>
                </button>
                @auth
                    <a href="{{ route('auth.account') }}" class="account-state-dot is-protected" title="アカウント保護済み" aria-label="アカウント設定"></a>
                @else
                    <a href="{{ route('auth.register.form') }}" class="account-state-dot" title="この端末だけに保存中" aria-label="アカウントを作る"></a>
                @endauth
            </div>
        </header>
    @endunless

    <main class="app-main mx-auto min-h-[calc(100vh-120px)] max-w-7xl px-4 py-5 sm:px-5 md:px-6 md:py-10 {{ $focusMode ? 'focus-main' : '' }}" data-canovia-main>
        <div data-canovia-page data-canovia-route="{{ request()->route()?->getName() }}" data-workspace-mode="{{ $workspaceModeDefinition->mode->value }}" data-workspace-mode-label="{{ $workspaceModeDefinition->label }}">
        @if (session('status'))
            <div class="assistant-notice assistant-notice-info mb-6" data-auto-toast>{{ session('status') }}</div>
        @endif

        @if ($earlyAccessActive)
            <section
                class="mb-4 flex flex-col gap-3 rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.035] px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                data-early-access-disclosure
            >
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full border border-cyan-300/20 bg-cyan-300/10 px-2 py-0.5 text-[9px] font-black uppercase tracking-[.16em] text-cyan-200">Early Access</span>
                        <span class="text-xs font-bold text-slate-200">Canoviaは先行公開中です</span>
                    </div>
                    <p class="mt-1 text-[11px] leading-5 text-slate-500">
                        機能や仕様は改善に伴って変わる場合があります。使いにくさ・不具合・欲しい機能があれば、その場で教えてください。
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    @if ($earlyAccessProductPreviewAvailable && ! request()->routeIs('product.preview.index'))
                        <a href="{{ route('product.preview.index') }}" class="btn-secondary px-3 py-2 text-xs">今後の機能を見る</a>
                    @endif
                    <button type="button" class="btn-secondary px-3 py-2 text-xs" data-feedback-open>フィードバック</button>
                </div>
            </section>
        @endif

        @guest
            @if (request()->routeIs('home') || request()->routeIs('plans.*') || request()->routeIs('navigation.*') || request()->routeIs('work_sessions.*') || request()->routeIs('my_plans.*'))
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
    </main>

    <div data-canovia-companion-slot>
        @auth
            @if (! $focusMode && ! request()->routeIs('companion.*') && (bool) data_get(config('features.flags.'.\App\Enums\FeatureKey::CanoviaCompanion->value), 'enabled', false))
                @include('layouts.partials.companion-palette')
            @endif
        @endauth
    </div>

    @unless ($focusMode)
        <footer class="mt-12 hidden border-t border-slate-800 bg-slate-950/70 md:block">
            <div class="mx-auto flex max-w-7xl flex-col gap-3 px-6 py-8 text-sm text-slate-500 md:flex-row md:items-center md:justify-between">
                <p>Canovia — 自分のペースで、前へ。</p>
                <div class="flex flex-wrap gap-4">
                    <a href="{{ route('legal.privacy') }}" class="hover:text-slate-300">プライバシー</a>
                    <a href="{{ route('legal.support') }}" class="hover:text-slate-300">サポート</a>
                </div>
            </div>
        </footer>

        <div class="md:hidden">
            @include('layouts.partials.mobile-nav')
        </div>
    @endunless

    <div class="route-loading-overlay" data-route-loading aria-hidden="true">
        <div class="route-loading-card">
            <span class="route-loading-spinner" aria-hidden="true"></span>
            <span>読み込み中…</span>
        </div>
    </div>

    @unless ($focusMode)
        <dialog class="ui-settings-dialog" data-ui-settings-dialog aria-labelledby="ui-settings-title">
            <div class="ui-settings-card">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">SETTINGS</p>
                        <h2 id="ui-settings-title" class="mt-1 text-xl font-bold text-slate-50">設定</h2>
                    </div>
                    <button type="button" class="feedback-close" data-ui-settings-close aria-label="閉じる">×</button>
                </div>

                <section class="mt-5 rounded-2xl border border-slate-800 bg-slate-950/35 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-bold text-slate-100">アカウント</p>
                            <p class="mt-1 text-xs text-slate-500">{{ $currentUser ? ($currentUser->name ?: $currentUser->email) : 'Guest' }}</p>
                        </div>
                        @auth
                            <a href="{{ route('auth.account') }}" class="btn-secondary px-3 py-2 text-xs">アカウント設定</a>
                        @else
                            <a href="{{ route('auth.login.form') }}" class="btn-secondary px-3 py-2 text-xs">ログイン</a>
                        @endauth
                    </div>
                </section>

                <section class="mt-3 rounded-2xl border border-slate-800 bg-slate-950/35 p-4">
                    <p class="text-sm font-bold text-slate-100">利用プラン</p>
                    <div class="mt-2 flex items-center justify-between gap-3">
                        <span class="badge {{ $currentAccessLabel === 'Free' ? 'badge-slate' : 'badge-green' }}">{{ $currentAccessLabel }}</span>
                        <span class="text-[11px] text-slate-500">課金処理はまだ未導入です</span>
                    </div>
                </section>

                @if ($isSuperAdmin)
                    <section class="mt-3 rounded-2xl border border-amber-300/20 bg-amber-300/[0.04] p-4">
                        <p class="text-[10px] font-black uppercase tracking-[.16em] text-amber-300">SUPER ADMIN</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <a href="{{ route('admin.dashboard') }}" class="btn-secondary px-3 py-2 text-xs">管理者メニュー</a>
                            <a href="{{ route('admin.economy.index') }}" class="btn-secondary px-3 py-2 text-xs">ユーザー・権利管理</a>
                        </div>
                        <p class="mt-3 text-xs font-bold text-slate-300">表示プレビュー</p>
                        <div class="mt-2 grid grid-cols-3 gap-2">
                            @foreach (['admin' => 'Admin', 'free' => 'Free', 'premium' => 'Premium'] as $mode => $label)
                                <form method="POST" action="{{ route('admin.preview.update') }}">
                                    @csrf
                                    <input type="hidden" name="mode" value="{{ $mode }}">
                                    <button type="submit" class="{{ (($mode === 'admin' && ! $adminPreviewMode) || $adminPreviewMode === $mode) ? 'btn-primary' : 'btn-secondary' }} w-full justify-center px-2 py-2 text-xs">{{ $label }}</button>
                                </form>
                            @endforeach
                        </div>
                        <p class="mt-2 text-[11px] leading-5 text-slate-500">プレビュー中も管理者権限自体は維持され、設定からAdmin表示へ戻せます。</p>

                        <p class="mt-4 text-xs font-bold text-slate-300">Release Preview</p>
                        <div class="mt-2 grid grid-cols-3 gap-2">
                            <form method="POST" action="{{ route('admin.release_level.preview.update') }}">
                                @csrf
                                <input type="hidden" name="level" value="public">
                                <button type="submit" class="{{ $adminReleasePreviewLevel === null ? 'btn-primary' : 'btn-secondary' }} w-full justify-center px-2 py-2 text-xs">Public</button>
                            </form>
                            @foreach (\App\Enums\ReleaseLevel::cases() as $releaseLevel)
                                <form method="POST" action="{{ route('admin.release_level.preview.update') }}">
                                    @csrf
                                    <input type="hidden" name="level" value="{{ $releaseLevel->value }}">
                                    <button type="submit" class="{{ $adminReleasePreviewLevel === $releaseLevel ? 'btn-primary' : 'btn-secondary' }} w-full justify-center px-2 py-2 text-xs">
                                        L{{ $releaseLevel->value }}
                                    </button>
                                </form>
                            @endforeach
                        </div>
                        <p class="mt-2 text-[11px] leading-5 text-slate-500">
                            現在: L{{ $currentReleaseLevel->value }} {{ $currentReleaseLevel->label() }}
                            · Public: L{{ $publicReleaseLevel->value }}
                        </p>
                    </section>
                @endif

                <details class="pk-action-details mt-4" open>
                    <summary>表示</summary>
                    <fieldset class="mt-3">
                        <legend class="text-sm font-bold text-slate-200">テーマ</legend>
                        <div class="canovia-theme-official mt-3" aria-label="Canoviaの正式テーマはダークです">
                            <span class="canovia-theme-official-mark" aria-hidden="true">●</span>
                            <div>
                                <strong>ダーク</strong>
                                <small>Canoviaの世界観に合わせた標準テーマ</small>
                            </div>
                        </div>
                        <p class="mt-2 text-xs leading-5 text-slate-500">ライトテーマはデザインを再設計するまで一時的に提供を停止しています。</p>
                    </fieldset>

                    <fieldset class="mt-5">
                        <legend class="text-sm font-bold text-slate-200">アクセント</legend>
                        <div class="ui-accent-options mt-3" data-ui-accent-options>
                            @foreach (\App\Models\Plan::ACCENT_LABELS as $accentKey => $accentLabel)
                                <button type="button" class="ui-accent-swatch" data-ui-accent-value="{{ $accentKey }}" data-accent="{{ $accentKey }}" aria-label="{{ $accentLabel }}"></button>
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset class="mt-5">
                        <legend class="text-sm font-bold text-slate-200">表示密度</legend>
                        <div class="ui-choice-grid mt-3" data-ui-density-options>
                            <button type="button" class="ui-choice" data-ui-density-value="compact"><strong>コンパクト</strong><small>多めに表示</small></button>
                            <button type="button" class="ui-choice" data-ui-density-value="standard"><strong>標準</strong><small>ちょうどよく表示</small></button>
                            <button type="button" class="ui-choice" data-ui-density-value="comfortable"><strong>ゆったり</strong><small>余白を広めに</small></button>
                        </div>
                    </fieldset>

                </details>

                <div class="mt-5 grid gap-2 sm:grid-cols-2">
                    <button type="button" class="btn-secondary w-full justify-center" data-onboarding-restart>使い方を見る</button>
                    <button type="button" class="btn-secondary w-full justify-center" data-install-guide-open>ホーム画面に追加</button>
                </div>

                <div class="mt-4 flex flex-wrap justify-center gap-4 text-xs">
                    <a href="{{ route('legal.privacy') }}" class="font-semibold text-sky-300 hover:text-sky-200">プライバシー</a>
                    <a href="{{ route('legal.support') }}" class="font-semibold text-sky-300 hover:text-sky-200">サポート</a>
                </div>

                <button type="button" class="btn-primary mt-3 w-full" data-ui-settings-close>閉じる</button>
            </div>
        </dialog>

        <dialog class="release-notes-dialog" data-release-notes-dialog data-latest-release-key="{{ $latestReleaseKey }}" aria-labelledby="release-notes-title">
            <div class="release-notes-card">
                <div class="release-notes-header">
                    <div>
                        <p class="release-notes-kicker">WHAT'S NEW</p>
                        <h2 id="release-notes-title">Canoviaの更新情報</h2>
                        <p>最近追加された機能や改善点を、使う人の目線でまとめています。</p>
                    </div>
                    <button type="button" class="feedback-close" data-release-notes-close aria-label="更新情報を閉じる">×</button>
                </div>

                <div class="release-notes-list" data-release-notes-list>
                    @forelse ($releaseNotes as $note)
                        <button type="button" class="release-note-item" data-release-note-open="{{ $note['key'] }}">
                            <span class="release-note-item-meta">
                                <time datetime="{{ $note['date'] }}">{{ \Carbon\Carbon::parse($note['date'])->format('Y/m/d') }}</time>
                                <span>{{ strtoupper($note['version']) }}</span>
                                @if (! empty($note['feedback_linked']))<span class="release-note-feedback-badge">声から改善</span>@endif
                            </span>
                            <strong>{{ $note['title'] }}</strong>
                            <span class="release-note-item-summary">{{ $note['summary'] }}</span>
                            <span class="release-note-item-more">詳しく見る <span aria-hidden="true">→</span></span>
                        </button>
                    @empty
                        <div class="release-notes-empty">まだ更新情報はありません。</div>
                    @endforelse
                </div>
            </div>
        </dialog>

        <dialog class="release-note-detail-dialog" data-release-note-detail-dialog aria-labelledby="release-note-detail-dialog-title">
            <div class="release-note-detail-card" data-release-note-detail-card>
                <button type="button" class="release-note-detail-close" data-release-note-detail-close aria-label="更新内容を閉じる">×</button>
                <p id="release-note-detail-dialog-title" class="sr-only">更新内容の詳細</p>

                @foreach ($releaseNotes as $note)
                    <section class="release-note-detail hidden" data-release-note-detail="{{ $note['key'] }}" aria-labelledby="release-note-title-{{ md5($note['key']) }}">
                        <div class="release-note-detail-meta">
                            <time datetime="{{ $note['date'] }}">{{ \Carbon\Carbon::parse($note['date'])->format('Y/m/d') }}</time>
                            <span>{{ strtoupper($note['version']) }}</span>
                            @if (! empty($note['feedback_linked']))<span class="release-note-feedback-badge">ユーザーの声から改善</span>@endif
                        </div>
                        <h3 id="release-note-title-{{ md5($note['key']) }}">{{ $note['title'] }}</h3>
                        <p class="release-note-detail-summary">{{ $note['summary'] }}</p>

                        @if (! empty($note['user_voice']))
                            <div class="release-note-voice-box">
                                <p class="release-note-section-title">ユーザーの声</p>
                                <p>{{ $note['user_voice'] }}</p>
                            </div>
                        @endif

                        <div class="release-note-highlight-box">
                            <p class="release-note-section-title">今回の改善</p>
                            <ul>
                                @foreach ($note['highlights'] as $highlight)
                                    <li>{{ $highlight }}</li>
                                @endforeach
                            </ul>
                        </div>

                        @if (! empty($note['tip']))
                            <div class="release-note-tip">
                                <span aria-hidden="true">✦</span>
                                <p><strong>使い方のヒント</strong>{{ $note['tip'] }}</p>
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>
        </dialog>

        <a href="{{ route('feedback.index') }}" class="feedback-fab hidden md:inline-flex" aria-label="Canovia Futureを開く">意見</a>
        <dialog class="feedback-dialog" data-feedback-dialog aria-labelledby="feedback-title">
            <form method="POST" action="{{ route('feedback.store') }}" class="feedback-dialog-card">
                @csrf
                <input type="hidden" name="page" value="{{ request()->path() }}">
                @if ($feedbackPlanId)<input type="hidden" name="plan_id" value="{{ $feedbackPlanId }}">@endif
                @if ($feedbackTaskId)<input type="hidden" name="task_id" value="{{ $feedbackTaskId }}">@endif
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 id="feedback-title" class="text-xl font-bold text-slate-50">気づいたことを教えてください</h2>
                    </div>
                    <button type="button" class="feedback-close" data-feedback-close aria-label="閉じる">×</button>
                </div>
                <fieldset class="mt-5">
                    <legend class="text-sm font-semibold text-slate-300">Canoviaの総合評価</legend>
                    <input type="hidden" name="rating" value="" data-feedback-rating-input>
                    <div class="feedback-star-row mt-2" role="group" aria-label="5段階評価">
                        @for ($rating = 1; $rating <= 5; $rating++)
                            <button type="button" class="feedback-star" data-feedback-rating-value="{{ $rating }}" aria-label="{{ $rating }}点" aria-pressed="false">★</button>
                        @endfor
                    </div>
                    <p class="mt-1 text-xs text-slate-500" data-feedback-rating-label>未評価</p>
                </fieldset>

                <label class="mt-5 block">
                    <span class="text-sm font-semibold text-slate-300">どんな内容？</span>
                    <select name="type" class="form-control mt-2" required>
                        <option value="usability">使いにくい</option>
                        <option value="bug">バグ</option>
                        <option value="request">欲しい機能</option>
                        <option value="positive">よかった点</option>
                    </select>
                </label>
                <label class="mt-4 block">
                    <span class="text-sm font-semibold text-slate-300">ひとこと</span>
                    <textarea name="message" rows="5" maxlength="4000" class="form-control mt-2" placeholder="気になったことがあれば教えてください"></textarea>
                </label>
                <p class="mt-3 text-xs leading-5 text-slate-500">画面の情報は自動で添えます。個人情報は書かないでください。</p>
                <div class="mt-5 flex gap-2">
                    <button type="button" class="btn-secondary flex-1" data-feedback-close>キャンセル</button>
                    <button type="submit" class="btn-primary flex-1">送信</button>
                </div>
            </form>
        </dialog>
    @endunless

    @include('layouts.partials.guide')
    @include('layouts.partials.onboarding')
</body>
</html>
