<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Temporary, isolated staging-only diagnostic for MCP authorization failures.
 *
 * The only emitted value is a hard-coded reason CATEGORY; never log an OAuth
 * code, Authorization header, JWT, token hash, claim value, client identity,
 * subject, request body, URL/query or exception text. Does not affect auth.
 */
final class McpStagingAuthFailureDiagnostic
{
    private const REASONS = [
        'bearer_syntax',
        'jwt_config_rejected',
        'jwt_not_compact',
        'jwt_header_rejected',
        'jwks_unavailable',
        'jwt_kid_rejected',
        'jwks_refresh_deferred',
        'jwt_signature_or_time_rejected',
        'jwt_claims_issuer',
        'jwt_claims_audience',
        'jwt_claims_client',
        'jwt_claims_scope',
        'jwt_claims_time',
        'jwt_claims_subject',
        'jwt_claims_other',
    ];

    public static function record(string $reason): void
    {
        if (config('app.env') !== 'staging'
            || config('canovia_staging.isolated') !== true
            || config('canovia_staging.synthetic_read_pilot_enabled') !== true
            || config('canovia_staging.mcp_explicitly_approved') !== true
            || ! in_array($reason, self::REASONS, true)) {
            return;
        }

        // Bypass LOG_CHANNEL=single when Render receives stderr from FPM.
        // No attacker-controlled values are passed through.
        try {
            Log::channel('stderr')->warning('mcp_stage_auth_failure', ['category' => $reason]);
        } catch (Throwable) {
            // Diagnostic failure can never change auth rejection or response.
        }
    }

    /**
     * Diagnostic classification ONLY, applied to already RS256-verified
     * claims AFTER normal claim policy has rejected them. Never authorizes.
     *
     * @param array<string,mixed> $claims
     */
    public static function classifyVerifiedClaims(
        array $claims,
        string $issuer,
        string $resource,
        string $clientId,
        int $now,
    ): string {
        if (($claims['iss'] ?? null) !== $issuer) {
            return 'jwt_claims_issuer';
        }

        $scope = $claims['scope'] ?? null;
        $scopes = is_string($scope) ? explode(' ', $scope) : [];
        $aud = $claims['aud'] ?? null;
        $openidPair = is_array($aud)
            && ($aud === [$resource, $issuer.'userinfo']
                || $aud === [$issuer.'userinfo', $resource])
            && in_array('openid', $scopes, true);
        if ($aud !== $resource && $aud !== [$resource] && ! $openidPair) {
            return 'jwt_claims_audience';
        }

        $hasAzp = array_key_exists('azp', $claims);
        $hasClientId = array_key_exists('client_id', $claims);
        if ($hasAzp === $hasClientId
            || ($hasAzp ? $claims['azp'] : $claims['client_id']) !== $clientId) {
            return 'jwt_claims_client';
        }

        if (! in_array(McpProtectedResourceConfiguration::READ_SCOPE, $scopes, true)) {
            return 'jwt_claims_scope';
        }

        $exp = $claims['exp'] ?? null;
        $iat = $claims['iat'] ?? null;
        $nbf = $claims['nbf'] ?? null;
        if (! is_int($exp) || ! is_int($iat)
            || $exp <= $now || $iat > $now
            || $iat > $exp || $exp > $now + 3600
            || $exp - $iat > 3600
            || (array_key_exists('nbf', $claims)
                && (! is_int($nbf) || $nbf > $now || $nbf > $exp))) {
            return 'jwt_claims_time';
        }

        $subject = $claims['sub'] ?? null;
        if (! is_string($subject) || $subject === '' || strlen($subject) > 255
            || trim($subject) !== $subject) {
            return 'jwt_claims_subject';
        }

        return 'jwt_claims_other';
    }
}
