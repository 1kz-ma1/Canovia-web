<?php

namespace App\Http\Middleware;

use App\Services\ReleaseLevelService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureReleaseLevel
{
    public function __construct(
        private readonly ReleaseLevelService $levels,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
        string $minimum,
    ): Response {
        if ($this->levels->allowsMinimum($minimum, $request->user(), $request)) {
            return $next($request);
        }

        if ($request->isMethodSafe()) {
            return redirect()
                ->route('workspace.overview.index')
                ->with(
                    'status',
                    'この機能は現在の公開レベルでは利用できません。Beta公開後に利用できます。',
                );
        }

        abort(404);
    }
}
