<?php

return [
    /*
     * MCP discovery, IdP introspection, Plan grants and tool execution each
     * require separately enabled switches. All are OFF by default; no
     * real ChatGPT client or OAuth IdP is pre-connected.
     */
    'discovery_enabled' => env('CANOVIA_MCP_DISCOVERY_ENABLED', false),

    // Exact canonical HTTPS MCP resource identifier including /api/mcp.
    'resource_url' => env('CANOVIA_MCP_RESOURCE_URL', ''),

    // Exact HTTPS issuer URL of a REAL OAuth 2.1 provider. Not Canovia login.
    'oauth_issuer' => env('CANOVIA_MCP_OAUTH_ISSUER', ''),

    'read_scope' => 'canovia.development.read',

    // Additional independent last-mile switch. OFF unless real IdP,
    // ChatGPT client registration, individual account links and Plan consent
    // have been security-reviewed in staging. Existing discovery alone cannot
    // activate a private-data MCP endpoint.
    'tools_enabled' => env('CANOVIA_MCP_TOOLS_ENABLED', false),

    // Separate opt-in for personally consented learning Plan reads. Disabled
    // everywhere by default and ALWAYS refused in synthetic-only staging.
    // No practice evidence, answer history or mutations are exposed.
    'study_read_enabled' => env('CANOVIA_MCP_STUDY_READ_ENABLED', false),

    // Private AP pilot ONLY: exactly one independently identified owner ID.
    // Empty/invalid values deny all real study reads and result proposals.
    // This is not a feature open to every Canovia account.
    'ap_pilot_owner_id' => env('CANOVIA_MCP_AP_PILOT_OWNER_ID', ''),

    // Explicit additional per-Plan opt-in: bounded, aggregated results of
    // OWNER-APPLIED study attempts only. Distinct from Tasks consent and
    // never exposed by the isolated synthetic-only staging service.
    'study_summary_read_enabled' => env('CANOVIA_MCP_STUDY_SUMMARY_READ_ENABLED', false),

    // Distinct per-Plan learning_context consent; no existing study_progress
    // grant silently permits the richer derived Context Snapshot.
    // Requires Study read + applied-practice aggregate and owner allowlist.
    'learning_context_snapshot_enabled' => env('CANOVIA_MCP_LEARNING_CONTEXT_SNAPSHOT_ENABLED', false),

    // Additional OAuth write scope + per-item Canovia owner confirmation.
    // This NEVER updates native questions, attempts or Task progress by itself.
    'study_result_proposals_enabled' => env('CANOVIA_MCP_STUDY_RESULT_PROPOSALS_ENABLED', false),

    // Exact HTTPS origins permitted when a browser supplies Origin.
    // An absent Origin from a server-side ChatGPT client is allowed.
    'allowed_origins' => env('CANOVIA_MCP_ALLOWED_ORIGINS', ''),


    // Explicit mutually exclusive token verifier: legacy introspection or
    // approved Auth0 JWT. Unknown values and simultaneous flags fail closed.
    // Mode alone does NOT turn on any MCP/OAuth/Plan endpoints.
    'token_verification_driver' => env('CANOVIA_MCP_TOKEN_VERIFICATION_DRIVER', 'introspection'),

    // RFC 7662 introspection is a separate disabled component. The real IdP
    // MUST support introspection and return strict iss/aud/sub/client_id/exp
    // claims. Enabling it does not enable the MCP endpoint or grant consent.
    'token_introspection_enabled' => env('CANOVIA_MCP_TOKEN_INTROSPECTION_ENABLED', false),
    'introspection_url' => env('CANOVIA_MCP_INTROSPECTION_URL', ''),
    'introspection_client_id' => env('CANOVIA_MCP_INTROSPECTION_CLIENT_ID', ''),
    'introspection_client_secret' => env('CANOVIA_MCP_INTROSPECTION_CLIENT_SECRET', ''),

    // Auth0 signed JWT verifier is an independent OFF-by-default candidate.
    // This does not switch existing RFC7662 MCP authorization or account linking.
    // Real ChatGPT registration, actual RS256 token inspection and Plan consent
    // must be accepted before any staging routing change.
    'auth0_jwt_verification_enabled' => env('CANOVIA_MCP_AUTH0_JWT_VERIFICATION_ENABLED', false),

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
    // Separate safety switch: neither a linked subject nor a 'prepared'
    // preference can grant access. Explicit per-Plan consent requires a
    // fresh PKCE IdP verification and a second user confirmation.
    'plan_consent_enabled' => env('CANOVIA_MCP_PLAN_CONSENT_ENABLED', false),

    'account_link_enabled' => env('CANOVIA_MCP_ACCOUNT_LINK_ENABLED', false),

    // Auth0-only explicit GitHub identity route. In OFF mode, preserve the
    // historical generic OAuth flow. When ON, every signed link/consent
    // token must identify a GitHub primary subject; linked Auth0 secondary
    // identities are deliberately rejected until independently reviewed.
    // Requires an approved GitHub social connection in the Auth0 tenant.
    'account_link_github_only_enabled' => env('CANOVIA_MCP_ACCOUNT_LINK_GITHUB_ONLY_ENABLED', false),
    'account_link_client_id' => env('CANOVIA_MCP_ACCOUNT_LINK_CLIENT_ID', ''),
    'account_link_client_secret' => env('CANOVIA_MCP_ACCOUNT_LINK_CLIENT_SECRET', ''),
    'account_link_metadata_url' => env('CANOVIA_MCP_ACCOUNT_LINK_METADATA_URL', ''),
    'account_link_authorization_endpoint' => env('CANOVIA_MCP_ACCOUNT_LINK_AUTHORIZATION_ENDPOINT', ''),
    'account_link_token_endpoint' => env('CANOVIA_MCP_ACCOUNT_LINK_TOKEN_ENDPOINT', ''),
    'account_link_redirect_uri' => env('CANOVIA_MCP_ACCOUNT_LINK_REDIRECT_URI', ''),



];
