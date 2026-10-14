<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * A narrow, stage-only OAuth failure signal: no request fields, response
 * bodies, token/header/claims, identifiers, URLs, or exception strings.
 *
 * Literal enum codes only. Do not log even this marker for production or
 * unrelated staging services/pilots.
 */
final class McpStagingOAuthFailureDiagnostic
{
    private const CODES = [
        'link_start_provider_preflight',
        'link_start_authorization_url',
        'link_callback_configuration',
        'link_callback_session',
        'link_callback_state',
        'link_callback_issuer',
        'link_callback_idp_error',
        'link_callback_code',
        'link_callback_token',
        'link_callback_subject',
        'link_callback_plan_scope',
        // Diagnostic-only split of the same fail-closed Plan consent gates.
        // Values are never interpolated; stage-only, fixed categories.
        'link_callback_plan_fixture_missing',
        'link_callback_plan_stage_guard',
        'link_callback_plan_consent_disabled',
        'link_callback_plan_scope_invalid',
        'link_callback_plan_owner',
        'link_callback_plan_link_mismatch',
        'link_callback_unexpected_purpose',
        'link_confirm_rejected',
        'link_provider_metadata_unreachable',
        'link_provider_metadata_invalid',
        'link_provider_token_request_invalid',
        'link_provider_token_unreachable',
        'link_provider_token_http_rejected',
        'link_provider_token_payload_invalid',
        'link_provider_jwt_rejected',
    ];

    public function record(string $code): void
    {
        if (config('app.env') !== 'staging'
            || config('canovia_staging.isolated') !== true
            || config('canovia_staging.login_pilot_enabled') !== true
            || config('canovia_staging.account_link_pilot_enabled') !== true
            || ! in_array($code, self::CODES, true)) {
            return;
        }

        Log::warning('Canovia MCP staging OAuth failure', ['stage_code' => $code]);
    }
}
