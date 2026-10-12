<?php

namespace App\Services;

/**
 * Offline, operator-only configuration inventory BEFORE opening OAuth/MCP.
 *
 * This service never makes HTTP requests, queries the database, validates
 * actual tokens, or exposes configured IDs, secrets, URLs or fingerprints.
 * A pass is only permission to attempt a separately approved stage pilot.
 */
final class McpAuth0SealedStagingWiringReadiness
{
    /** @return array<string,mixed> */
    public function check(): array
    {
        $mcp = static fn (string $key): mixed => config('canovia_mcp.'.$key);
        $off = static fn (string $key): bool => $mcp($key) === false;

        $webClosed = config('canovia_staging.web_access_enabled') === false
            && config('canovia_staging.web_access_explicitly_approved') === false;

        $capabilityClosed = $off('discovery_enabled')
            && $off('tools_enabled')
            && $off('account_link_enabled')
            && $off('plan_consent_enabled')
            && $off('delegated_policy_enabled')
            && $off('auth0_jwt_verification_enabled')
            && $off('token_introspection_enabled');

        $chatgptClientId = $mcp('allowed_client_id');
        $linkClientId = $mcp('account_link_client_id');
        $linkSecret = $mcp('account_link_client_secret');
        $fingerprintKey = $mcp('identity_fingerprint_key');

        $checks = [
            'sealed_isolated_stage' =>
                config('app.env') === 'staging'
                && config('canovia_staging.isolated') === true
                && config('app.url') === 'https://canovia-mcp-staging.onrender.com'
                && $webClosed,
            'pinned_staging_postgres' =>
                config('canovia_staging.database_mode') === 'render_postgres'
                && config('canovia_staging.postgres_id') === 'dpg-db43rbbncjis73bmigi0-a'
                && config('database.default') === 'pgsql'
                && config('database.connections.pgsql.database') === 'canovia_mcp_staging_db',
            'oauth_mcp_capabilities_disabled' => $capabilityClosed,
            'exclusive_jwt_candidate' =>
                $mcp('token_verification_driver') === 'auth0_jwt'
                && $off('token_introspection_enabled')
                && $off('auth0_jwt_verification_enabled'),
            'trusted_issuer_and_resource' =>
                $mcp('oauth_issuer') === 'https://canovia-mcp-dev.jp.auth0.com/'
                && $mcp('resource_url') === 'https://canovia-mcp-staging.onrender.com/api/mcp'
                && $mcp('read_scope') === McpProtectedResourceConfiguration::READ_SCOPE,
            'issued_chatgpt_third_party_client' =>
                is_string($chatgptClientId)
                && preg_match('/\Atpc_[A-Za-z0-9_-]{8,191}\z/D', $chatgptClientId) === 1,
            'separate_confidential_link_client' =>
                is_string($linkClientId) && strlen($linkClientId) >= 8
                && strlen($linkClientId) <= 191
                && preg_match('/\A[A-Za-z0-9_-]+\z/D', $linkClientId) === 1
                && ! str_starts_with($linkClientId, 'tpc_')
                && $linkClientId !== $chatgptClientId
                && is_string($linkSecret) && strlen($linkSecret) >= 16
                && strlen($linkSecret) <= 512,
            'pinned_account_link_endpoints' =>
                $mcp('account_link_metadata_url') ===
                    'https://canovia-mcp-dev.jp.auth0.com/.well-known/openid-configuration'
                && $mcp('account_link_authorization_endpoint') ===
                    'https://canovia-mcp-dev.jp.auth0.com/authorize'
                && $mcp('account_link_token_endpoint') ===
                    'https://canovia-mcp-dev.jp.auth0.com/oauth/token'
                && $mcp('account_link_redirect_uri') ===
                    'https://canovia-mcp-staging.onrender.com/account/mcp/link/callback',
            'fingerprint_key_configured' =>
                is_string($fingerprintKey) && strlen($fingerprintKey) >= 32
                && strlen($fingerprintKey) <= 512,
        ];

        $namedStatuses = [];
        foreach ($checks as $key => $ok) {
            $namedStatuses[$key] = $ok ? 'pass' : 'blocked';
        }

        return [
            'schema' => 'canovia.mcp.auth0_sealed_stage_wiring.v1',
            'checks' => $namedStatuses,
            'configuration_ready' => ! in_array('blocked', $namedStatuses, true),
            'live_oauth_verified' => false,
            'client_grants_verified' => false,
            'issued_jwt_verified' => false,
            'stage_activation_authorized' => false,
            'production_authorized' => false,
        ];
    }
}
