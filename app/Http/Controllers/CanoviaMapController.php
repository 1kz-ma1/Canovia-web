<?php

namespace App\Http\Controllers;

use App\Enums\MapLevel;
use App\Services\MapProjectionService;
use Illuminate\Http\Request;

final class CanoviaMapController extends Controller
{
    public function index(Request $request, MapProjectionService $projection)
    {
        $level = MapLevel::tryFrom((string) $request->query('level', MapLevel::Intent->value))
            ?? MapLevel::Intent;

        return view('map.index', [
            'graph' => $projection->project($request, $level),
        ]);
    }
}
