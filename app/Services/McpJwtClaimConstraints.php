<?php

namespace App\Services;

/**
 * Claim-policy layer for Auth0 access tokens after independent RS256/JWKS
 * signature verification. NEVER authorize decoded but unverified claims.
 *
 * Signature, trusted issuer, exact MCP resource, approved OAuth client, scopes,
 * token lifetime, linked identity and fresh per-Plan consent remain mandatory.
 */
final class McpJwtClaimConstraints
{
    public const MAX_LIFETIME_SECONDS = 3600;

    /**
     * Check cryptographically VERIFIED token claims against exact constraints.
     *
     * Auth0 default API access tokens identify the client with azp, while the
     * RFC 9068 profile uses client_id. Require at least one claim to match
     * the exact independently confirmed OAuth client. If both are present,
     * accept only when BOTH independently match that SAME allowlisted client;
     * conflicting claims still fail closed.
     *
     * @param array<string, mixed> $claims Claims from trusted verified JWT parser only.
     */
    public function matches(
        array $claims,
        string $expectedIssuer,
        string $expectedResource,
        string $expectedClientId,
        int $now,
    ): bool {
        if ($expectedIssuer === '' || $expectedResource === '' || $expectedClientId === ''
            || strlen($expectedClientId) > 255
            || ($claims['iss'] ?? null) !== $expectedIssuer) {
            return false;
        }

        $subject = $claims['sub'] ?? null;
        if (! is_string($subject)
            || $subject === ''
            || strlen($subject) > 255
            || trim($subject) !== $subject
            || preg_match('/[\x00-\x1f\x7f]/', $subject) === 1) {
            return false;
        }

        // Auth0 can issue an RS256 API token for BOTH the requested custom API
        // and its issuer-owned /userinfo endpoint when OpenID Connect is used.
        // Accept only that exact documented pair (in either order), never an
        // arbitrary audience list or a /userinfo-only token. This is called
        // ONLY after signature and issuer verification by the trusted verifier.
        $audience = $claims['aud'] ?? null;
        $userinfoAudience = $expectedIssuer.'userinfo';
        $singleResource = $audience === $expectedResource
            || $audience === [$expectedResource];
        $auth0OpenIdPair = is_array($audience)
            && ($audience === [$expectedResource, $userinfoAudience]
                || $audience === [$userinfoAudience, $expectedResource])
            && is_string($claims['scope'] ?? null)
            && in_array('openid', explode(' ', $claims['scope']), true);
        if (! $singleResource && ! $auth0OpenIdPair) {
            return false;
        }

        $hasAzp = array_key_exists('azp', $claims);
        $hasClientId = array_key_exists('client_id', $claims);
        if (! $hasAzp && ! $hasClientId) {
            return false;
        }
        // Both claims are allowed only when they are identical AND both are
        // the configured OAuth client. A conflicting second claim can never
        // override or supplement the first.
        if (($hasAzp && $claims['azp'] !== $expectedClientId)
            || ($hasClientId && $claims['client_id'] !== $expectedClientId)) {
            return false;
        }

        $exp = $claims['exp'] ?? null;
        $iat = $claims['iat'] ?? null;
        if (! is_int($exp)
            || ! is_int($iat)
            || $iat > $now
            || $iat > $exp
            || $exp <= $now
            || $exp > $now + self::MAX_LIFETIME_SECONDS
            || $exp - $iat > self::MAX_LIFETIME_SECONDS) {
            return false;
        }

        if (array_key_exists('nbf', $claims)
            && (! is_int($claims['nbf'])
                || $claims['nbf'] > $now
                || $claims['nbf'] > $exp)) {
            return false;
        }

        $scope = $claims['scope'] ?? null;
        if (! is_string($scope)
            || $scope === ''
            || strlen($scope) > 512
            || trim($scope) !== $scope
            || preg_match('/\A[A-Za-z0-9._:\/\-]+(?: [A-Za-z0-9._:\/\-]+)*\z/D', $scope) !== 1) {
            return false;
        }

        return in_array(McpProtectedResourceConfiguration::READ_SCOPE, explode(' ', $scope), true);
    }
}
