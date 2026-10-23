<?php

namespace App\Http\Middleware;

use App\Services\ReleaseLevelService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Additional restrictive publication boundary, never a substitute for auth. */
final class EnsurePreparationSurface
{
    private const PUBLIC_ROUTES = [
        'home', 'health', 'legal.privacy', 'legal.support', 'feedback.index',
        'auth.login.form', 'auth.login', 'auth.register.form', 'auth.register',
        'password.request', 'password.email', 'password.reset', 'password.update',
    ];

    private const ACCOUNT_ROUTES = [
        'auth.account', 'auth.logout', 'auth.account.deletion.prepare',
        'auth.account.deletion.review', 'auth.account.deletion.cancel', 'auth.account.destroy',
        'personalization.show', 'personalization.store', 'personalization.skip',
        'personalization.result', 'personalization.seed.accept',
        'plans.create.manual', 'plans.store', 'plans.show', 'plans.edit', 'plans.update',
        'feedback.store',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! app(ReleaseLevelService::class)->preparationOnly()) {
            return $next($request);
        }

        $response = $this->withinPreparation($request, $next);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function withinPreparation(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();
        if (in_array($name, ['first_run.show', 'first_run.start', 'workspace.overview.index', 'my_plans.index'], true)) {
            return redirect()->route('home');
        }
        if ($name === 'plans.create') {
            return redirect()->route('plans.create.manual');
        }
        if (in_array($name, self::ACCOUNT_ROUTES, true)) {
            if (! $request->user()) {
                return $request->expectsJson()
                    ? response()->json(['code' => 'PREPARATION_LOGIN_REQUIRED'], 401)
                    : redirect()->guest(route('auth.login.form'));
            }

            // Shared/public Plan mutations are outside the preparation scope.
            // Do not silently strip them or overwrite a legacy visibility bit.
            abort_if($request->boolean('is_public') || $request->boolean('is_collaborative'), 403);

            return $next($request);
        }
        if (in_array($name, self::PUBLIC_ROUTES, true)) {
            return $next($request);
        }

        // Includes unnamed/new routes and every API; an admin preview cannot
        // widen this whitelist. Deny before resource binding/controller work.
        return response()->json(['code' => 'PREPARATION_SURFACE_CLOSED'], 404);
    }
}
