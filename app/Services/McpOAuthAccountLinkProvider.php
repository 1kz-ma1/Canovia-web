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
        private readonly McpAccessTokenIntrospector $introspector,
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
            && is_array($meta['scopes_supported'] ?? null)
            && in_array(McpProtectedResourceConfiguration::READ_SCOPE, $meta['scopes_supported'], true);
    }

    public function authorizationUrl(string $state, string $challenge): ?string
    {
        $settings = $this->configuration->settings();
        if ($settings === null
            || ! preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $state)
            || ! preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $challenge)) {
            return null;
        }

        return $settings['authorize'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $settings['client_id'],
            'redirect_uri' => $settings['redirect'],
            'scope' => McpProtectedResourceConfiguration::READ_SCOPE,
            'resource' => $settings['resource'],
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
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

        // The introspection endpoint independently checks active, issuer,
        // subject, exact resource audience, linking-client ID and read scope.
        // The token is never stored or returned to a browser.
        return $this->introspector->verifyAccountLink($body['access_token']);
    }
}
