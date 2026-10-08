<?php

namespace App\Services;

/**
 * An IdP-introspected external subject, NOT an authenticated Canovia User.
 *
 * Never authorize a private Plan from this object alone. Later integration
 * must additionally resolve a proven identity link, a fresh OAuth consent
 * grant, and current owner/Plan/domain/scope permissions on every request.
 */
final readonly class McpVerifiedTokenPrincipal
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $issuer,
        public string $subject,
        public string $clientId,
        public string $audience,
        public array $scopes,
        public int $expiresAt,
    ) {}
}
