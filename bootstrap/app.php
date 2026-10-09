<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureFeatureAccess;
use App\Http\Middleware\EnsureReleaseLevel;
use App\Http\Middleware\EnsureFirstRunStarted;
use App\Http\Middleware\MeasurePagePerformance;
use App\Http\Middleware\NormalizeAiJsonInput;
use App\Http\Middleware\RedirectLegacyCanoviaHost;
use App\Http\Middleware\TrackAiPlanFunnel;
use App\Http\Middleware\TrackEarlyAccessVisit;
use App\Http\Middleware\RememberWorkspaceScreen;
use App\Http\Middleware\CloseUnopenedMcpStaging;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Measure before the web middleware group so database-backed session
        // reads/writes are included in the request total.
        $middleware->prepend(MeasurePagePerformance::class);
        // Staging always starts inaccessible except /up; no personal data
        // or OAuth endpoints are reachable before explicit access review.
        $middleware->prepend(CloseUnopenedMcpStaging::class);
        $middleware->encryptCookies(except: [\App\Services\HomeSurfacePreference::COOKIE]);
        $middleware->redirectGuestsTo(fn (Request $request) => route('auth.login.form'));
        $middleware->redirectUsersTo(fn (Request $request) => app(\App\Services\HomeSurfacePreference::class)->url($request));

        // GitHub file content is source text, not ordinary form prose.
        // Preserve leading/trailing whitespace and final newlines exactly on
        // the two routes that accept a file body for review-only GitHub write.
        $middleware->trimStrings(except: [
            fn (Request $request) => $request->is(
                'github-workflow/artifacts/*/repository-change',
            ),
            fn (Request $request) => $request->is(
                'plans/*/tasks/*/execution-orchestration/github/prepare',
            ),
        ]);

        $middleware->alias([
            'admin.access' => EnsureAdminAccess::class,
            'feature.access' => EnsureFeatureAccess::class,
            'release.level' => EnsureReleaseLevel::class,
            'early_access.visit' => TrackEarlyAccessVisit::class,
        ]);
        $middleware->appendToGroup('web', RedirectLegacyCanoviaHost::class);
        // Track the AI plan funnel before JSON normalization so even parser
        // failures are visible in diagnostics.
        $middleware->appendToGroup('web', TrackAiPlanFunnel::class);
        $middleware->appendToGroup('web', NormalizeAiJsonInput::class);
        $middleware->appendToGroup('web', EnsureFirstRunStarted::class);
        $middleware->appendToGroup('web', RememberWorkspaceScreen::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
