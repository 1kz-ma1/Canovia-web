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

            if (! $response->successful()
                || ! str_contains(strtolower((string) $response->header('Content-Type')), 'application/json')
                || strlen($response->body()) > 12288) {
                return false;
            }
            $meta = $response->json();
        } catch (Throwable) {
            // Exceptions can carry provider credentials or URLs. Never log.
            return false;
        }

        return is_array($meta)
            && ! array_is_list($meta)
            && ($meta['issuer'] ?? null) === $settings['issuer']
            && ($meta['authorization_endpoint'] ?? null) === $settings['authorize']
            && ($meta['token_endpoint'] ?? null) === $settings['token']
            && ($meta['authorization_response_iss_parameter_supported'] ?? null) === true
            && is_array($meta['code_challenge_methods_supported'] ?? null)
            && in_array('S256', $meta['code_challenge_methods_supported'], true)
            && is_array($meta['token_endpoint_auth_methods_supported'] ?? null)
            && in_array('client_secret_basic', $meta['token_endpoint_auth_methods_supported'], true)
            // Auth0 discovery lists OIDC scopes, not our custom API permission.
            // Signed JWT validation still requires exact API scope and audience.
            && ($this->verifier->mode() === 'auth0_jwt'
                || (is_array($meta['scopes_supported'] ?? null)
                    && in_array(McpProtectedResourceConfiguration::READ_SCOPE, $meta['scopes_supported'], true)));
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
        if ($this->verifier->mode() === 'auth0_jwt') {
            // Auth0 custom API token audience. Also retain the pinned resource.
            $params['audience'] = $settings['resource'];
        }
        if ($this->configuration->githubOnlyEnabled()) {
            $params['connection'] = 'github';
            $params['prompt'] = 'login';
        }
        return $settings['authorize'].'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeAndVerify(string $code, string $verifier): ?McpVerifiedTokenPrincipal
    {
        $settings = $this->configuration->settings();
        if ($settings === null || strlen($code) < 16 || strlen($code) > 2048
            || preg_match('/\A[\x21-\x7e]+\z/D', $code) !== 1
            || ! preg_match('/\A[A-Za-z0-9_-]{43,128}\z/D', $verifier)) {
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

            if (! $response->successful()
                || ! str_contains(strtolower((string) $response->header('Content-Type')), 'application/json')
                || strlen($response->body()) > 12288) {
                return null;
            }
            $body = $response->json();
        } catch (Throwable) {
            return null;
        }

        if (! is_array($body) || array_is_list($body)
            || ($body['token_type'] ?? null) !== 'Bearer'
            || ! is_int($body['expires_in'] ?? null)
            || $body['expires_in'] < 1 || $body['expires_in'] > 3600
            || ! is_string($body['access_token'] ?? null)
            || strlen($body['access_token']) > 8192) {
            return null;
        }

        // Only a cryptographically verified first-party token may bind the
        // Canovia session; GitHub selection in the URL is not subject proof.
        $principal = $this->verifier->verifyAccountLink($body['access_token']);
        if ($principal === null
            || ($this->configuration->githubOnlyEnabled()
                && preg_match('/\\Agithub\\|[0-9]{1,20}\\z/D', $principal->subject) !== 1)) {
            return null;
        }
        return $principal;
    }
}
