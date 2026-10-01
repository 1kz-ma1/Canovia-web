<?php

namespace App\Http\Controllers;

use App\Enums\MapLevel;
use App\Services\MapComplexitySnapshotService;
use App\Services\MapProjectionService;
use App\Services\MapSurfaceContextService;
use Illuminate\Http\Request;

final class CanoviaMapController extends Controller
{
    public function index(
        Request $request,
        MapProjectionService $projection,
        MapSurfaceContextService $surfaceContext,
        MapComplexitySnapshotService $complexity,
    ) {
        $level = MapLevel::tryFrom((string) $request->query('level', MapLevel::Intent->value))
            ?? MapLevel::Intent;

        $graph = $projection->project($request, $level);

        return view('map.index', [
            'graph' => $graph,
            'mapSurfaceContext' => $surfaceContext->resolve($graph),
            'mapComplexitySnapshot' => $complexity->fromGraph($graph),
        ]);
    }
}
