<?php

namespace App\Services;

/**
 * Security-policy half of a FUTURE Auth0 JWT/JWKS access-token verifier.
 *
 * IMPORTANT: These are ONLY the claim checks to perform AFTER a trusted JWT
 * library has verified the RS256 signature, trusted JWKS key ID, and token
 * header/profile. NEVER pass decoded/unverified JWT payloads to authorization.
 * This class is intentionally not connected to any HTTP route or Plan policy.
 *
 * A reviewed cryptographic verifier and independent identity/Plan grants are
 * required before any verified principal may be constructed.
 */
final class McpJwtClaimConstraints
{
    public const MAX_LIFETIME_SECONDS = 3600;

    /**
     * Check cryptographically VERIFIED token claims against exact constraints.
     *
     * Auth0 default API access tokens identify the client with azp, while the
     * RFC 9068 profile uses client_id. Fail closed on missing/both and only
     * allow an independently confirmed issued OAuth client ID.
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

        // A multi-element audience (including Auth0 /userinfo) is not
        // permitted by the existing one-target-resource policy.
        $audience = $claims['aud'] ?? null;
        if ($audience !== $expectedResource
            && $audience !== [$expectedResource]) {
            return false;
        }

        $hasAzp = array_key_exists('azp', $claims);
        $hasClientId = array_key_exists('client_id', $claims);
        if ($hasAzp === $hasClientId) {
            return false;
        }
        if (($hasAzp ? $claims['azp'] : $claims['client_id']) !== $expectedClientId) {
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
