<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FutureSupport\SupportAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class FutureSupportController extends Controller
{
    public function actors(SupportAuthority $authority)
    {
        return response()->json($authority->issue(), 201);
    }

    public function revoke(Request $request, SupportAuthority $authority)
    {
        $authority->revoke($request);

        return response()->json(['revoked' => true]);
    }

    public function index(Request $request, SupportAuthority $authority)
    {
        return response()->json(['features' => $authority->features($request->attributes->get('future_actor')),
            'fetched_at' => now()->toISOString(), 'request_id' => (string) Str::uuid()]);
    }

    public function support(Request $request, string $featureKey, SupportAuthority $authority)
    {
        return response()->json(['feature' => $authority->set($request, $featureKey, true), 'request_id' => (string) Str::uuid()]);
    }

    public function unsupport(Request $request, string $featureKey, SupportAuthority $authority)
    {
        return response()->json(['feature' => $authority->set($request, $featureKey, false), 'request_id' => (string) Str::uuid()]);
    }
}
