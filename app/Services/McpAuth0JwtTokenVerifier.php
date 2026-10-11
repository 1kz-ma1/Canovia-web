<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Isolated, OFF-by-default Auth0 RS256 access-token signature verifier.
 *
 * No controller or account-link path calls this class yet. Only a verified
 * RS256 signature over the exact trusted issuer's JWKS can yield a principal,
 * and the existing separate delegated Plan grant must still authorize access.
 *
 * Never follow JKU/X5U/JWK URLs in token headers. Issuer is operator-owned,
 * not derived from token content or the HTTP request host.
 */
final class McpAuth0JwtTokenVerifier
{
    private const MAX_TOKEN_BYTES = 8192;
    private const MAX_HEADER_BYTES = 1024;
    private const MAX_JWKS_BYTES = 12288;
    private const CACHE_SECONDS = 300;
    private const ROTATION_BACKOFF_SECONDS = 60;
    private const MAX_KEYS = 8;

    public function __construct(
        private readonly McpProtectedResourceConfiguration $resource,
        private readonly McpJwtClaimConstraints $claimsPolicy,
    ) {}

    public function isConfigured(): bool
    {
        return $this->settings() !== null;
    }

    public function verify(string $accessToken): ?McpVerifiedTokenPrincipal
    {
        $settings = $this->settings();
        if ($settings === null || strlen($accessToken) < 32
            || strlen($accessToken) > self::MAX_TOKEN_BYTES
            || preg_match('/\A[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\z/D', $accessToken) !== 1) {
            return null;
        }

        try {
            // Header is UNTRUSTED. Its only permitted influence is selecting
            // an exact kid from the preconfigured issuer's pinned JWKS.
            $segments = explode('.', $accessToken);
            if (strlen($segments[0]) > 1500) {
                return null;
            }
            $headerJson = base64_decode(strtr($segments[0], '-_', '+/'), true);
            if ($headerJson === false || strlen($headerJson) > self::MAX_HEADER_BYTES) {
                return null;
            }
            $header = json_decode($headerJson, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($header) || array_is_list($header)
                || array_diff(array_keys($header), ['alg', 'kid', 'typ']) !== []
                || ($header['alg'] ?? null) !== 'RS256'
                || (isset($header['typ']) && ! in_array($header['typ'], ['JWT', 'at+jwt'], true))
                || ! is_string($header['kid'] ?? null)
                || preg_match('/\A[A-Za-z0-9_.\-]{1,128}\z/D', $header['kid']) !== 1) {
                return null;
            }

            $cacheKey = 'canovia:mcp:auth0:jwks:'.hash('sha256', $settings['issuer']);
            $jwks = Cache::get($cacheKey);
            $wasCached = is_array($jwks);
            if (! $wasCached) {
                $jwks = $this->fetchJwks($settings['jwks']);
                if ($jwks === null) {
                    return null;
                }
                Cache::put($cacheKey, $jwks, self::CACHE_SECONDS);
            }

            $jwk = $this->findKey($jwks, $header['kid']);
            if ($jwk === null && $wasCached) {
                // A recently rotated kid gets at most one bounded refresh
                // per issuer per minute; a spray of unknown kids is denied.
                if (! Cache::add($cacheKey.':refresh', true, self::ROTATION_BACKOFF_SECONDS)) {
                    return null;
                }
                $next = $this->fetchJwks($settings['jwks']);
                if ($next === null) {
                    return null;
                }
                Cache::put($cacheKey, $next, self::CACHE_SECONDS);
                $jwk = $this->findKey($next, $header['kid']);
            }
            if ($jwk === null) {
                return null;
            }

            // Maintained library validates the RS256 signature and token
            // timestamps. Do not decode payload or use token claims before.
            $trustedClaims = (array) JWT::decode(
                $accessToken,
                JWK::parseKeySet(['keys' => [$jwk]], 'RS256'),
            );

            $now = now()->getTimestamp();
            if (! $this->claimsPolicy->matches(
                $trustedClaims,
                $settings['issuer'],
                $settings['resource'],
                $settings['client'],
                $now,
            )) {
                return null;
            }

            return new McpVerifiedTokenPrincipal(
                issuer: $settings['issuer'],
                subject: $trustedClaims['sub'],
                clientId: $settings['client'],
                audience: $settings['resource'],
                scopes: array_values(array_unique(explode(' ', $trustedClaims['scope']))),
                expiresAt: $trustedClaims['exp'],
            );
        } catch (Throwable) {
            // No logging of token content, header, claims, JWKS body or
            // exception messages: such values may contain sensitive context.
            return null;
        }
    }

    /**
     * @return array{issuer:string,resource:string,client:string,jwks:string}|null
     */
    private function settings(): ?array
    {
        if (config('canovia_mcp.auth0_jwt_verification_enabled') !== true
            || ! $this->resource->isReady()) {
            return null;
        }

        $issuer = config('canovia_mcp.oauth_issuer');
        $resource = config('canovia_mcp.resource_url');
        $client = config('canovia_mcp.allowed_client_id');
        if (! is_string($issuer) || ! is_string($resource) || ! is_string($client)
            || $client === '' || strlen($client) > 255 || trim($client) !== $client
            || preg_match('/[\x00-\x20\x7f]/', $client) === 1) {
            return null;
        }

        $host = parse_url($issuer, PHP_URL_HOST);
        // Fixed issuer format excludes paths, extra port, query, credentials
        // and IPs; never trust an arbitrary host from an unverified JWT.
        if (! is_string($host)
            || preg_match('/\A[a-z0-9](?:[a-z0-9\-\.]*[a-z0-9])?\.auth0\.com\z/D', $host) !== 1
            || $issuer !== 'https://'.$host.'/') {
            return null;
        }

        return [
            'issuer' => $issuer,
            'resource' => $resource,
            'client' => $client,
            'jwks' => $issuer.'.well-known/jwks.json',
        ];
    }

    /** @return list<array<string, mixed>>|null */
    private function fetchJwks(string $url): ?array
    {
        try {
            $response = Http::acceptJson()
                ->withoutRedirecting()
                ->connectTimeout(2)
                ->timeout(5)
                ->get($url);

            if (! $response->successful()
                || ! str_contains(strtolower((string) $response->header('Content-Type')), 'application/json')
                || strlen($response->body()) > self::MAX_JWKS_BYTES) {
                return null;
            }

            $json = $response->json();
            if (! is_array($json) || array_is_list($json)
                || ! isset($json['keys']) || ! is_array($json['keys'])
                || ! array_is_list($json['keys'])
                || count($json['keys']) < 1 || count($json['keys']) > self::MAX_KEYS) {
                return null;
            }

            $filtered = [];
            $seen = [];
            foreach ($json['keys'] as $key) {
                if (! is_array($key) || array_is_list($key)
                    || ! is_string($key['kid'] ?? null)
                    || preg_match('/\A[A-Za-z0-9_.\-]{1,128}\z/D', $key['kid']) !== 1
                    || isset($seen[$key['kid']])) {
                    return null;
                }
                $seen[$key['kid']] = true;
                if ($this->validSigningKey($key)) {
                    // Cache only required public parameters, not arbitrary
                    // metadata or IdP-provided URLs.
                    $filtered[] = [
                        'kid' => $key['kid'],
                        'kty' => 'RSA',
                        'alg' => 'RS256',
                        'n' => $key['n'],
                        'e' => $key['e'],
                    ];
                }
            }

            return $filtered === [] ? null : $filtered;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $key */
    private function validSigningKey(array $key): bool
    {
        if (($key['kty'] ?? null) !== 'RSA'
            || (isset($key['alg']) && $key['alg'] !== 'RS256')
            || (isset($key['use']) && $key['use'] !== 'sig')
            || (isset($key['key_ops']) && $key['key_ops'] !== ['verify'])
            || ! is_string($key['n'] ?? null)
            || ! is_string($key['e'] ?? null)
            || strlen($key['n']) > 750
            || preg_match('/\A[A-Za-z0-9_-]+\z/D', $key['n']) !== 1
            || preg_match('/\A[A-Za-z0-9_-]+\z/D', $key['e']) !== 1) {
            return false;
        }

        $modulus = base64_decode(strtr($key['n'], '-_', '+/'), true);
        $exponent = base64_decode(strtr($key['e'], '-_', '+/'), true);

        return $modulus !== false
            && strlen($modulus) >= 256
            && strlen($modulus) <= 512
            && $exponent !== false
            && strlen($exponent) >= 1
            && strlen($exponent) <= 8;
    }

    /** @param list<array<string,mixed>> $keys @return array<string,mixed>|null */
    private function findKey(array $keys, string $kid): ?array
    {
        foreach ($keys as $key) {
            if (($key['kid'] ?? null) === $kid && $this->validSigningKey($key)) {
                return $key;
            }
        }

        return null;
    }
}
