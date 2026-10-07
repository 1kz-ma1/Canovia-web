<?php

namespace App\Http\Middleware;

use App\Services\EarlyAccessTelemetryService;
use App\Services\ReturnAfterAbsencePersonalizationAdapter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TrackEarlyAccessVisit
{
    public function __construct(
        private readonly EarlyAccessTelemetryService $telemetry,
        private readonly ReturnAfterAbsencePersonalizationAdapter $returnAfterAbsence,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $this->returnAfterAbsence->observeVisit($request);
        $this->telemetry->recordDailyVisit($request);

        return $next($request);
    }
}
