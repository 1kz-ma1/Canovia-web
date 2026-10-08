<?php

namespace App\Services;

/**
 * Discovery configuration for a future external OAuth 2.1 authorization
 * server. Not a token verifier, identity provider, or active MCP tool.
 *
 * All values come from server-owned config. Never derive metadata/header
 * URLs from an untrusted HTTP Host or forwarded header.
 */
final class McpProtectedResourceConfiguration
{
    public const RESOURCE_PATH = '/api/mcp';
    public const METADATA_PATH = '/.well-known/oauth-protected-resource';
    public const READ_SCOPE = 'canovia.development.read';

    public function isReady(): bool
    {
        if (config('canovia_mcp.discovery_enabled') !== true) {
            return false;
        }

        $resource = config('canovia_mcp.resource_url');
        $issuer = config('canovia_mcp.oauth_issuer');

        return is_string($resource)
            && is_string($issuer)
            && $this->validHttpsUrl($resource, self::RESOURCE_PATH)
            && $this->validHttpsUrl($issuer)
            && $resource !== $issuer
            && config('canovia_mcp.read_scope') === self::READ_SCOPE;
    }

    /** @return array{resource:string,authorization_servers:array<int,string>,scopes_supported:array<int,string>} */
    public function metadata(): array
    {
        if (! $this->isReady()) {
            throw new \LogicException('MCP discovery is not configured.');
        }

        return [
            'resource' => (string) config('canovia_mcp.resource_url'),
            'authorization_servers' => [(string) config('canovia_mcp.oauth_issuer')],
            'scopes_supported' => [self::READ_SCOPE],
        ];
    }

    public function metadataUrl(): string
    {
        if (! $this->isReady()) {
            throw new \LogicException('MCP discovery is not configured.');
        }

        // Explicit, exact suffix; no rtrim character-mask mistakes.
        $resource = (string) config('canovia_mcp.resource_url');

        return substr($resource, 0, -strlen(self::RESOURCE_PATH))
            .self::METADATA_PATH;
    }

    public function challenge(bool $tokenPresent): string
    {
        $challenge = 'Bearer resource_metadata="'.$this->metadataUrl().'"'
            .', scope="'.self::READ_SCOPE.'"';

        // Only when a token was actually presented; never echo token content.
        if ($tokenPresent) {
            $challenge .= ', error="invalid_token"';
        }

        return $challenge;
    }

    private function validHttpsUrl(string $value, ?string $requiredPath = null): bool
    {
        if ($value === '' || strlen($value) > 512
            || trim($value) !== $value
            || str_contains($value, '"')
            || preg_match('/[\\x00-\\x20\\x7f]/', $value)
            || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($value);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        $host = (string) $parts['host'];
        if (! preg_match('/^[A-Za-z0-9.-]+$/D', $host)
            || strtolower($host) === 'localhost'
            || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return $requiredPath === null
            || ($parts['path'] ?? '') === $requiredPath;
    }
}
