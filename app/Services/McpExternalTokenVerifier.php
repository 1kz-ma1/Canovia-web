<?php

namespace App\Services;

/**
 * Exact, explicit and fail-closed MCP access-token verification selection.
 *
 * Legacy RFC 7662 remains the default. Auth0 JWT can only be selected after
 * its own independent OFF-by-default flag and trusted issuer are configured.
 * No automatic fallback, guessing token format, or accepting either provider
 * during a migration. Account-link identity must use the same selected
 * verification mode with its own distinct registered OAuth client.
 */
final class McpExternalTokenVerifier
{
    public function __construct(
        private readonly McpAccessTokenIntrospector $introspector,
        private readonly McpAuth0JwtTokenVerifier $auth0Jwt,
    ) {}

    public function isConfigured(): bool
    {
        return match ($this->mode()) {
            'introspection' => $this->introspector->isConfigured(),
            'auth0_jwt' => $this->auth0Jwt->isConfigured(),
            default => false,
        };
    }

    public function verify(string $accessToken): ?McpVerifiedTokenPrincipal
    {
        return match ($this->mode()) {
            'introspection' => $this->introspector->verify($accessToken),
            'auth0_jwt' => $this->auth0Jwt->verify($accessToken),
            default => null,
        };
    }

    public function verifyAccountLink(string $accessToken): ?McpVerifiedTokenPrincipal
    {
        return match ($this->mode()) {
            'introspection' => $this->introspector->verifyAccountLink($accessToken),
            'auth0_jwt' => $this->auth0Jwt->verifyAccountLink($accessToken),
            default => null,
        };
    }

    public function mode(): ?string
    {
        $mode = config('canovia_mcp.token_verification_driver', 'introspection');
        $introspection = config('canovia_mcp.token_introspection_enabled') === true;
        $jwt = config('canovia_mcp.auth0_jwt_verification_enabled') === true;

        // Never run two trust paths side by side; reject mismatched switch
        // combinations and unrecognized mode names, including null values.
        if ($introspection === $jwt) {
            return null;
        }

        if ($mode === 'introspection' && $introspection) {
            return 'introspection';
        }

        if ($mode === 'auth0_jwt' && $jwt) {
            return 'auth0_jwt';
        }

        return null;
    }
}
