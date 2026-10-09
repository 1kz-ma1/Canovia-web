<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * RFC 7662 opaque access-token introspection through a separately configured
 * trusted OAuth authorization server. The IdP validates the token/signature.
 *
 * This class never receives an HTTP Request or looks up a Canovia User.
 * The separately disabled MCP resource invokes verify() only after its
 * own security gates pass; this principal alone never grants Plan access.
 */
final class McpAccessTokenIntrospector
{
    private const MAX_TOKEN_BYTES = 8192;
    private const MAX_RESPONSE_BYTES = 12288;
    private const MAX_TOKEN_REMAINING_SECONDS = 3600;

    public function __construct(
        private readonly McpProtectedResourceConfiguration $resource,
    ) {}

    /**
     * Safe diagnostic: validates only server-owned endpoint/credential shape.
     * Does not transmit, persist or inspect any OAuth token.
     */
    public function isConfigured(): bool
    {
        return $this->settings() !== null;
    }

    public function verify(string $accessToken): ?McpVerifiedTokenPrincipal
    {
        return $this->verifyForConfiguredClient(
            $accessToken,
            (string) config('canovia_mcp.allowed_client_id', ''),
        );
    }

    /**
     * Verify tokens minted for Canovia's own account-linking OAuth client.
     * These tokens are NOT accepted by /api/mcp or the delegated Plan policy.
     */
    public function verifyAccountLink(string $accessToken): ?McpVerifiedTokenPrincipal
    {
        if (app(McpOAuthAccountLinkConfiguration::class)->settings() === null) {
            return null;
        }

        return $this->verifyForConfiguredClient(
            $accessToken,
            (string) config('canovia_mcp.account_link_client_id', ''),
        );
    }

    private function verifyForConfiguredClient(
        string $accessToken,
        string $expectedClientId,
    ): ?McpVerifiedTokenPrincipal {
        $settings = $this->settings();
        if ($settings === null
            || $expectedClientId === ''
            || strlen($expectedClientId) > 255
            || strlen($accessToken) < 16
            || strlen($accessToken) > self::MAX_TOKEN_BYTES
            || preg_match('/\A[\x21-\x7e]+\z/D', $accessToken) !== 1) {
            return null;
        }

        // The bearer token is sent only in the POST body to the configured,
        // HTTPS, same-issuer IdP. Never log or cache it or forward redirects.
        try {
            // RFC 6749 §2.3.1: form-encode each credential before
            // constructing Basic. A resource-URL-valued Keycloak client ID
            // contains ':' and '/' and must not be split at its first colon.
            // This preserves the exact single-MCP-resource audience rule.
            $basicCredentials = rawurlencode($settings['credential_id'])
                .':'.rawurlencode($settings['credential_secret']);

            $response = Http::asForm()
                ->acceptJson()
                ->withHeaders([
                    'Authorization' => 'Basic '.base64_encode($basicCredentials),
                ])
                ->withoutRedirecting()
                ->connectTimeout(2)
                ->timeout(5)
                ->post($settings['endpoint'], [
                    'token' => $accessToken,
                    'token_type_hint' => 'access_token',
                ]);
        } catch (Throwable) {
            // Do not log exception messages: they may contain request data.
            return null;
        }

        if (! $response->successful()
            || ! str_contains(strtolower((string) $response->header('Content-Type')), 'application/json')
            || strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            return null;
        }

        $body = $response->json();
        if (! is_array($body) || array_is_list($body)
            || ($body['active'] ?? null) !== true
            || ($body['iss'] ?? null) !== $settings['issuer']
            || ($body['client_id'] ?? null) !== $expectedClientId
            || ! is_string($body['sub'] ?? null)
            || trim($body['sub']) !== $body['sub']
            || strlen($body['sub']) < 1
            || strlen($body['sub']) > 255
            || ($body['token_type'] ?? 'Bearer') !== 'Bearer') {
            return null;
        }

        // This first implementation requires a single exact target resource,
        // rather than accepting a token jointly issued for other audiences.
        $audience = $body['aud'] ?? null;
        if ($audience !== $settings['resource']
            && $audience !== [$settings['resource']]) {
            return null;
        }

        $now = now()->getTimestamp();
        $exp = $body['exp'] ?? null;
        if (! is_int($exp)
            || $exp <= $now
            || $exp > $now + self::MAX_TOKEN_REMAINING_SECONDS
            || (isset($body['nbf']) && (! is_int($body['nbf']) || $body['nbf'] > $now))
            || (isset($body['iat']) && (! is_int($body['iat'])
                || $body['iat'] > $now
                || $body['iat'] > $exp
                || $exp - $body['iat'] > self::MAX_TOKEN_REMAINING_SECONDS))) {
            return null;
        }

        $rawScope = $body['scope'] ?? null;
        if (! is_string($rawScope)
            || strlen($rawScope) > 512
            || trim($rawScope) !== $rawScope
            || preg_match('/\A[A-Za-z0-9._:\/\-]+(?: [A-Za-z0-9._:\/\-]+)*\z/D', $rawScope) !== 1) {
            return null;
        }
        $scopes = array_values(array_unique(explode(' ', $rawScope)));
        if (! in_array(McpProtectedResourceConfiguration::READ_SCOPE, $scopes, true)) {
            return null;
        }

        return new McpVerifiedTokenPrincipal(
            issuer: $settings['issuer'],
            subject: $body['sub'],
            clientId: $expectedClientId,
            audience: $settings['resource'],
            scopes: $scopes,
            expiresAt: $exp,
        );
    }

    /**
     * @return array{issuer:string,resource:string,endpoint:string,credential_id:string,credential_secret:string,allowed_client_id:string}|null
     */
    private function settings(): ?array
    {
        if (config('canovia_mcp.token_introspection_enabled') !== true
            || ! $this->resource->isReady()) {
            return null;
        }

        $issuer = config('canovia_mcp.oauth_issuer');
        $resource = config('canovia_mcp.resource_url');
        $endpoint = config('canovia_mcp.introspection_url');
        $id = config('canovia_mcp.introspection_client_id');
        $secret = config('canovia_mcp.introspection_client_secret');
        $allowedClient = config('canovia_mcp.allowed_client_id');

        foreach ([$issuer, $resource, $endpoint, $id, $secret, $allowedClient] as $field) {
            if (! is_string($field)
                || $field === ''
                || strlen($field) > 512
                || trim($field) !== $field
                || preg_match('/[\x00-\x1f\x7f]/', $field)) {
                return null;
            }
        }

        // Operator-owned URL only. Forbid query, fragment, credentials and
        // redirects, and require introspection to use the exact issuer host.
        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($endpoint);
        $issuerParts = parse_url($issuer);
        if (! is_array($parts) || ! is_array($issuerParts)
            || ($parts['scheme'] ?? '') !== 'https'
            || ! isset($parts['host'])
            || strcasecmp((string) $parts['host'], (string) ($issuerParts['host'] ?? '')) !== 0
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! isset($parts['path']) || $parts['path'] === '/'
            || strtolower((string) $parts['host']) === 'localhost'
            || filter_var((string) $parts['host'], FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        if (strlen($id) > 191 || strlen($secret) < 12 || strlen($allowedClient) > 255
            || str_contains($allowedClient, ' ')) {
            return null;
        }

        return [
            'issuer' => $issuer,
            'resource' => $resource,
            'endpoint' => $endpoint,
            'credential_id' => $id,
            'credential_secret' => $secret,
            'allowed_client_id' => $allowedClient,
        ];
    }
}
