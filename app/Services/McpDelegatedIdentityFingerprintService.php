<?php

namespace App\Services;

/**
 * Domain-separated HMACs prevent storing raw issuer+subject or client IDs
 * alongside private Plan grant records.
 */
final class McpDelegatedIdentityFingerprintService
{
    public function subject(string $issuer, string $subject): ?string
    {
        if ($issuer === '' || $subject === '' || strlen($issuer) > 512
            || strlen($subject) > 255) {
            return null;
        }

        return $this->digest('subject', $issuer."\0".$subject);
    }

    public function clientResource(string $clientId, string $resource): ?string
    {
        if ($clientId === '' || $resource === ''
            || strlen($clientId) > 512 || strlen($resource) > 512) {
            return null;
        }

        return $this->digest('client_resource', $clientId."\0".$resource);
    }

    private function digest(string $domain, string $value): ?string
    {
        $key = config('canovia_mcp.identity_fingerprint_key');
        if (! is_string($key) || strlen($key) < 32 || strlen($key) > 512) {
            return null;
        }

        return hash_hmac('sha256', 'canovia.mcp.v1'."\0".$domain."\0".$value, $key);
    }
}
