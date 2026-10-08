<?php

namespace App\Services;

/**
 * All endpoints and client credentials are server-owned. The Canovia
 * identity-link client is separate from ChatGPT's own OAuth client.
 */
final class McpOAuthAccountLinkConfiguration
{
    public const CALLBACK_PATH = '/account/mcp/link/callback';

    public function __construct(
        private readonly McpProtectedResourceConfiguration $resource,
        private readonly McpDelegatedIdentityFingerprintService $fingerprints,
    ) {}

    /**
     * @return array{issuer:string,resource:string,metadata:string,authorize:string,token:string,redirect:string,client_id:string,client_secret:string}|null
     */
    public function settings(): ?array
    {
        if (config('canovia_mcp.account_link_enabled') !== true
            || config('canovia_mcp.token_introspection_enabled') !== true
            || ! $this->resource->isReady()) {
            return null;
        }

        $keys = [
            'issuer' => 'oauth_issuer',
            'resource' => 'resource_url',
            'metadata' => 'account_link_metadata_url',
            'authorize' => 'account_link_authorization_endpoint',
            'token' => 'account_link_token_endpoint',
            'redirect' => 'account_link_redirect_uri',
            'client_id' => 'account_link_client_id',
            'client_secret' => 'account_link_client_secret',
        ];
        $settings = [];
        foreach ($keys as $name => $key) {
            $value = config('canovia_mcp.'.$key);
            if (! is_string($value) || $value === '' || strlen($value) > 512
                || trim($value) !== $value
                || preg_match('/[\x00-\x20\x7f]/', $value)) {
                return null;
            }
            $settings[$name] = $value;
        }

        if (strlen($settings['client_id']) > 191
            || strlen($settings['client_secret']) < 16
            || str_contains($settings['client_id'], ':')
            || $settings['client_id'] === config('canovia_mcp.allowed_client_id')
            || $this->fingerprints->subject($settings['issuer'], 'test-subject') === null) {
            return null;
        }

        foreach (['metadata', 'authorize', 'token', 'redirect'] as $key) {
            if (! $this->trustedUrl($settings[$key])) {
                return null;
            }
        }

        $issuerHost = parse_url($settings['issuer'], PHP_URL_HOST);
        $resourceHost = parse_url($settings['resource'], PHP_URL_HOST);
        foreach (['metadata', 'authorize', 'token'] as $key) {
            if (strcasecmp((string) parse_url($settings[$key], PHP_URL_HOST), (string) $issuerHost) !== 0) {
                return null;
            }
        }
        if (strcasecmp((string) parse_url($settings['redirect'], PHP_URL_HOST), (string) $resourceHost) !== 0
            || parse_url($settings['redirect'], PHP_URL_PATH) !== self::CALLBACK_PATH
            || $settings['redirect'] !== substr($settings['resource'], 0, -strlen(McpProtectedResourceConfiguration::RESOURCE_PATH)).self::CALLBACK_PATH) {
            return null;
        }

        return $settings;
    }

    private function trustedUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || ! preg_match('/^[a-z0-9.-]+$/iD', $parts['host'])
            || strtolower($parts['host']) === 'localhost'
            || filter_var($parts['host'], FILTER_VALIDATE_IP) !== false
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! isset($parts['path']) || $parts['path'] === '/') {
            return false;
        }
        return true;
    }
}
