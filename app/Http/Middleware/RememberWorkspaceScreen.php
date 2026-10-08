<?php
namespace App\Http\Middleware;

use App\Services\WorkspaceNavigationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RememberWorkspaceScreen
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            app(WorkspaceNavigationService::class)->remember($request);
        }
        return $response;
    }
}
