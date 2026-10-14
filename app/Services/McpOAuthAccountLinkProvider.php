<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A separately registered confidential Authorization Code + PKCE S256
 * client for proving a Canovia session actor's immutable IdP subject.
 * Strictly no JWT decoding, email-based identity links or token persistence.
 */
final class McpOAuthAccountLinkProvider
{
    public function __construct(
        private readonly McpOAuthAccountLinkConfiguration $configuration,
        private readonly McpExternalTokenVerifier $verifier,
    ) {}

    public function isProviderVerified(): bool
    {
        $settings = $this->configuration->settings();
        if ($settings === null) {
            return false;
        }

        try {
            $response = Http::acceptJson()
                ->withoutRedirecting()->connectTimeout(2)->timeout(5)
                ->get($settings['metadata']);

            if ($response->status() === 429) {
                // Only the trusted provider metadata request is known to
                // have returned 429. No body/Retry-After/issuer URL is logged.
                app(McpStagingOAuthFailureDiagnostic::class)->record('link_provider_metadata_rate_limited');
                return false;
            }
            if (! $response->successful()
                || ! str_contains(strtolower((string) $response->header('Content-Type')), 'application/json')
                || strlen($response->body()) > 12288) {
                app(McpStagingOAuthFailureDiagnostic::class)->record('link_provider_metadata_invalid');
                return false;
            }
            $meta = $response->json();
        } catch (Throwable) {
            // Never record exception text: it can carry request URLs or secrets.
            app(McpStagingOAuthFailureDiagnostic::class)->record('link_provider_metadata_unreachable');
            return false;
        }

        $validMetadata = is_array($meta)
            && ! array_is_list($meta)
            && ($meta['issuer'] ?? null) === $settings['issuer']
            && ($meta['authorization_endpoint'] ?? null) === $settings['authorize']
            && ($meta['token_endpoint'] ?? null) === $settings['token']
            && ($meta['authorization_response_iss_parameter_supported'] ?? null) === true
            && is_array($meta['code_challenge_methods_supported'] ?? null)
            && in_array('S256', $meta['code_challenge_methods_supported'], true)
            && is_array($meta['token_endpoint_auth_methods_supported'] ?? null)
            && in_array('client_secret_basic', $meta['token_endpoint_auth_methods_supported'], true)
            // Auth0's general OIDC metadata omits custom API permissions,
            // so require metadata scope only in the legacy introspection
            // path. Signed JWT verification still MUST enforce the exact
            // custom scope and resource audience on every real token.
            && ($this->verifier->mode() === 'auth0_jwt'
                || (is_array($meta['scopes_supported'] ?? null)
                    && in_array(McpProtectedResourceConfiguration::READ_SCOPE, $meta['scopes_supported'], true)));

        if (! $validMetadata) {
            app(McpStagingOAuthFailureDiagnostic::class)->record('link_provider_metadata_invalid');
        }

        return $validMetadata;
    }

    public function authorizationUrl(string $state, string $challenge): ?string
    {
        $settings = $this->configuration->settings();
        if ($settings === null
            || ! preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $state)
            || ! preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $challenge)) {
            return null;
        }

        $params = [
            'response_type' => 'code',
            'client_id' => $settings['client_id'],
            'redirect_uri' => $settings['redirect'],
            'scope' => McpProtectedResourceConfiguration::READ_SCOPE,
            'resource' => $settings['resource'],
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
        // Auth0's default profile uses "audience" for custom API tokens.
        // The RFC 8707 "resource" is retained for protocol compatibility;
        // both values are always the SAME pinned MCP resource identifier.
        // Do not change the legacy/non-Auth0 introspection request.
        if ($this->verifier->mode() === 'auth0_jwt') {
            $params['audience'] = $settings['resource'];
        }
        if ($this->configuration->githubOnlyEnabled()) {
            // Auth0 documents this exact social connection selector. Server
            // controlled, never copied from untrusted form/query parameters.
            $params['connection'] = 'github';
            $params['prompt'] = 'login';
        }

        return $settings['authorize'].'?'.http_build_query(
            $params, '', '&', PHP_QUERY_RFC3986,
        );
    }

    public function exchangeAndVerify(string $code, string $verifier): ?McpVerifiedTokenPrincipal
    {
        $settings = $this->configuration->settings();
        if ($settings === null || strlen($code) < 16 || strlen($code) > 2048
            || preg_match('/\A[\x21-\x7e]+\z/D', $code) !== 1
            || ! preg_match('/\A[A-Za-z0-9_-]{43,128}\z/D', $verifier)) {
            app(McpStagingOAuthFailureDiagnostic::class)->record('link_provider_token_request_invalid');
            return null;
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->withBasicAuth($settings['client_id'], $settings['client_secret'])
                ->withoutRedirecting()->connectTimeout(2)->timeout(5)
                ->post($settings['token'], [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $settings['redirect'],
                    'code_verifier' => $verifier,
                    'resource' => $settings['resource'],
                ]);

            if (! $response->successful()) {
                app(McpStagingOAuthFailureDiagnostic::class)->record(
                    $response->status() === 429
                        ? 'link_provider_token_rate_limited'
                        : 'link_provider_token_http_rejected',
                );
                return null;
            }
            if (! str_contains(strtolower((string) $response->header('Content-Type')), 'application/json')
                || strlen($response->body()) > 12288) {
                app(McpStagingOAuthFailureDiagnostic::class)->record('link_provider_token_payload_invalid');
                return null;
            }
            $body = $response->json();
        } catch (Throwable) {
            app(McpStagingOAuthFailureDiagnostic::class)->record('link_provider_token_unreachable');
            return null;
        }

        if (! is_array($body) || array_is_list($body)
            || ($body['token_type'] ?? null) !== 'Bearer'
            || ! is_int($body['expires_in'] ?? null)
            || $body['expires_in'] < 1 || $body['expires_in'] > 3600
            || ! is_string($body['access_token'] ?? null)
            || strlen($body['access_token']) > 8192) {
            app(McpStagingOAuthFailureDiagnostic::class)->record('link_provider_token_payload_invalid');
            return null;
        }

        // The introspection endpoint independently checks active, issuer,
        // subject, exact resource audience, linking-client ID and read scope.
        // The token is never stored or returned to a browser.
        $principal = $this->verifier->verifyAccountLink($body['access_token']);
        if ($principal === null) {
            app(McpStagingOAuthFailureDiagnostic::class)->record('link_provider_jwt_rejected');
            return null;
        }
        if ($this->configuration->githubOnlyEnabled()
            && preg_match('/\Agithub\|[0-9]{1,20}\z/D', $principal->subject) !== 1) {
            // Auth0 may return another primary subject after identity
            // account-linking, even if connection=github was requested.
            // Never treat the redirect itself as GitHub identity proof.
            app(McpStagingOAuthFailureDiagnostic::class)->record('link_provider_github_subject_rejected');
            return null;
        }
        return $principal;
    }
}
