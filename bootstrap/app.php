<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureFeatureAccess;
use App\Http\Middleware\NormalizeAiJsonInput;
use App\Http\Middleware\RedirectLegacyCanoviaHost;
use App\Http\Middleware\TrackAiPlanFunnel;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => route('auth.login.form'));
        $middleware->redirectUsersTo(fn (Request $request) => route('home'));
        $middleware->alias([
            'admin.access' => EnsureAdminAccess::class,
            'feature.access' => EnsureFeatureAccess::class,
        ]);
        $middleware->appendToGroup('web', RedirectLegacyCanoviaHost::class);
        // Track the AI plan funnel before JSON normalization so even parser
        // failures are visible in diagnostics.
        $middleware->appendToGroup('web', TrackAiPlanFunnel::class);
        $middleware->appendToGroup('web', NormalizeAiJsonInput::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
