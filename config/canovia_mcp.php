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
];
