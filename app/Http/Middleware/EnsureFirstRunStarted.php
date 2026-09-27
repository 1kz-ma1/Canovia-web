<?php

namespace App\Http\Middleware;

use App\Services\FirstRunService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFirstRunStarted
{
    public function __construct(private readonly FirstRunService $firstRun) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $routeName = (string) ($request->route()?->getName() ?? '');
        if (! $this->isGatedRoute($routeName)) {
            return $next($request);
        }

        if (! $this->firstRun->requiresGate($request)) {
            return $next($request);
        }

        return redirect()->route('first_run.show');
    }

    private function isGatedRoute(string $routeName): bool
    {
        return Str::is([
            'home',
            'inbox.*',
            'roadmap.*',
            'timeline.*',
            'calendar.*',
            'navigation.*',
            'my_plans.*',
            'plans.create',
            'plans.create.manual',
            'goal_discovery.*',
        ], $routeName);
    }
}
