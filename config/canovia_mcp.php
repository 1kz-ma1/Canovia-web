<?php

return [
    /*
     * No MCP tool or delegated OAuth token is accepted by this implementation.
     * The discovery + challenge probe is opt-in to avoid advertising a
     * non-existent authorization server to ChatGPT.
     */
    'discovery_enabled' => env('CANOVIA_MCP_DISCOVERY_ENABLED', false),

    // Exact canonical HTTPS MCP resource identifier including /api/mcp.
    'resource_url' => env('CANOVIA_MCP_RESOURCE_URL', ''),

    // Exact HTTPS issuer URL of a REAL OAuth 2.1 provider. Not Canovia login.
    'oauth_issuer' => env('CANOVIA_MCP_OAUTH_ISSUER', ''),

    'read_scope' => 'canovia.development.read',

    // RFC 7662 introspection is a separate disabled component. The real IdP
    // MUST support introspection and return strict iss/aud/sub/client_id/exp
    // claims. Enabling it does not enable the MCP endpoint or grant consent.
    'token_introspection_enabled' => env('CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED', false),
    'introspection_url' => env('CANOVIA_MCP_INTROSPECTION_URL', ''),
    'introspection_client_id' => env('CANOVIA_MCP_INTROSPECTION_CLIENT_ID', ''),
    'introspection_client_secret' => env('CANOVIA_MCP_INTROSPECTION_CLIENT_SECRET', ''),

    // Exact OAuth client identifier approved at the IdP for this connection,
    // including CIMD URL when used. Never infer identity from email.
    'allowed_client_id' => env('CANOVIA_MCP_ALLOWED_CLIENT_ID', ''),

    // Separate from the OAuth verifier and OFF until verified dual-identity
    // linking, formal OAuth consent and reviewed MCP handler are completed.
    'delegated_policy_enabled' => env('CANOVIA_MCP_DELEGATED_POLICY_ENABLED', false),

    // Dedicated high-entropy HMAC key, not the Laravel APP_KEY. Rotation will
    // invalidate all subject/client fingerprints until users re-link.
    'identity_fingerprint_key' => env('CANOVIA_MCP_IDENTITY_FINGERPRINT_KEY', ''),

    // Canovia's own confidential OAuth client for linking its logged-in
    // actor with the IdP subject. NEVER reuse ChatGPT's OAuth client ID.
    // This independent flow is OFF until an audited IdP is configured.
    'account_link_enabled' => env('CANOVIA_MCP_ACCOUNT_LINK_ENABLED', false),
    'account_link_client_id' => env('CANOVIA_MCP_ACCOUNT_LINK_CLIENT_ID', ''),
    'account_link_client_secret' => env('CANOVIA_MCP_ACCOUNT_LINK_CLIENT_SECRET', ''),
    'account_link_metadata_url' => env('CANOVIA_MCP_ACCOUNT_LINK_METADATA_URL', ''),
    'account_link_authorization_endpoint' => env('CANOVIA_MCP_ACCOUNT_LINK_AUTHORIZATION_ENDPOINT', ''),
    'account_link_token_endpoint' => env('CANOVIA_MCP_ACCOUNT_LINK_TOKEN_ENDPOINT', ''),
    'account_link_redirect_uri' => env('CANOVIA_MCP_ACCOUNT_LINK_REDIRECT_URI', ''),



];
