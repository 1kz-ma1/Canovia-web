<?php

namespace App\Http\Middleware;

use App\Services\FutureSupport\SupportAuthority;
use App\Services\ReleaseLevelService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class FutureSupportLabBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestID = (string) Str::uuid();
        $request->attributes->set('future_request_id', $requestID);
        try {
            if (! app()->environment('local', 'testing') || config('future_support.enabled') !== true
                || app(ReleaseLevelService::class)->preparationOnly()) {
                SupportAuthority::reject('FUTURE_UNAVAILABLE', 404);
            }
            $loopback = config('future_support.allow_loopback_http') === true
                && in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true);
            if (! $request->isSecure() && ! $loopback) {
                SupportAuthority::reject('FUTURE_UNAVAILABLE', 404);
            }
            $issue = $request->routeIs('future.native.actors');
            $ip = hash('sha256', $request->ip() ?? 'unknown');
            $this->limit('ip:'.$ip, 120, 60);
            if ($issue) {
                $this->limit('issue-minute:'.$ip, 3, 60);
                $this->limit('issue-day:'.$ip, 20, 86400);
            }
            $body = trim($request->getContent());
            $json = $body === '' ? (object) [] : json_decode($body);
            if ($request->query->count() !== 0 || ! is_object($json) || count(get_object_vars($json)) !== 0) {
                SupportAuthority::reject('FUTURE_INVALID_REQUEST', 422);
            }
            if ($issue && $request->hasHeader('Authorization')) {
                SupportAuthority::reject('FUTURE_INVALID_REQUEST', 422);
            }
            if (! $issue) {
                $actor = app(SupportAuthority::class)->actor($request, ! $request->isMethod('GET'));
                $request->attributes->set('future_actor', $actor);
                if ($actor) {
                    $this->limit('actor:'.$actor->id, $request->isMethod('GET') ? 60 : 30, 60);
                }
            }
            $response = $next($request);
        } catch (HttpResponseException $error) {
            $response = $error->getResponse();
        } catch (QueryException $error) {
            // Do not expose SQL, credential hashes or bound parameters to client/log middleware.
            $response = response()->json(['code' => 'FUTURE_STORAGE_UNAVAILABLE'], 503);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Vary', 'Authorization');
        $response->headers->set('X-Request-ID', $requestID);

        return $response;
    }

    private function limit(string $key, int $count, int $seconds): void
    {
        $key = 'future-support:'.$key;
        if (RateLimiter::tooManyAttempts($key, $count)) {
            throw new HttpResponseException(response()->json(['code' => 'FUTURE_RATE_LIMITED'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key)));
        }
        RateLimiter::hit($key, $seconds);
    }
}
