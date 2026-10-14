<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Temporary, log-only diagnosis of an already-authenticated MCP tool read.
 *
 * This has no effect on authorization or the JSON-RPC response. Never log
 * tokens, JWT claims, issuer, subject, hashes, identifiers, Plan data,
 * request arguments, cookie/session values, URLs or exception details.
 */
final class McpStagingPlanReadDenialDiagnostic
{
    private const REASONS = [
        'plan_not_found',
        'policy_precondition',
        'principal_constraints',
        'fingerprint_unavailable',
        'linked_identity_mismatch',
        'plan_owner_or_category',
        'stage_fixture_mismatch',
        'grant_client_binding_mismatch',
        'grant_missing_or_inactive',
        'scope_denied',
    ];

    public static function enabled(): bool
    {
        return config('app.env') === 'staging'
            && config('canovia_staging.isolated') === true
            && config('canovia_staging.synthetic_read_pilot_enabled') === true
            && config('canovia_staging.mcp_explicitly_approved') === true
            && config('canovia_mcp.tools_enabled') === true;
    }

    public static function record(string $reason): void
    {
        if (! self::enabled() || ! in_array($reason, self::REASONS, true)) {
            return;
        }

        try {
            Log::channel('stderr')->warning('mcp_stage_plan_read_denied', [
                'stage_code' => $reason,
            ]);
        } catch (Throwable) {
            // Diagnostics must never alter a security decision.
        }
    }
}
