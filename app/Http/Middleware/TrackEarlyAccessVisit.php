<?php

namespace App\Http\Middleware;

use App\Services\EarlyAccessTelemetryService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TrackEarlyAccessVisit
{
    public function __construct(
        private readonly EarlyAccessTelemetryService $telemetry,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $this->telemetry->recordDailyVisit($request);

        return $next($request);
    }
}
