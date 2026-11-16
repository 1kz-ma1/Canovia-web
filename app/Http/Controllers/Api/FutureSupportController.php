<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FutureSupport\SupportAuthority;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;

final class FutureSupportController extends Controller
{
    public function actors(SupportAuthority $authority)
    {
        return $this->storage(fn () => $authority->issue(), 201);
    }

    public function revoke(Request $request, SupportAuthority $authority)
    {
        return $this->storage(function () use ($authority, $request) {
            $authority->revoke($request);

            return ['revoked' => true];
        });
    }

    public function index(Request $request, SupportAuthority $authority)
    {
        return $this->storage(fn () => ['features' => $authority->features($request->attributes->get('future_actor')),
            'fetched_at' => now()->toISOString(), 'request_id' => $request->attributes->get('future_request_id')]);
    }

    public function support(Request $request, string $featureKey, SupportAuthority $authority)
    {
        return $this->storage(fn () => ['feature' => $authority->set($request, $featureKey, true), 'request_id' => $request->attributes->get('future_request_id')]);
    }

    public function unsupport(Request $request, string $featureKey, SupportAuthority $authority)
    {
        return $this->storage(fn () => ['feature' => $authority->set($request, $featureKey, false), 'request_id' => $request->attributes->get('future_request_id')]);
    }
    private function storage(callable $operation, int $status = 200)
    {
        // Catch before Laravel Routing Pipeline renders/reports controller exceptions.
        try {
            return response()->json($operation(), $status);
        } catch (QueryException $error) {
            return response()->json(['code' => 'FUTURE_STORAGE_UNAVAILABLE'], 503);
        }
    }
}
