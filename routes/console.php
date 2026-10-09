<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


/**
 * Read-only STAGING OAuth provider compatibility checks.
 *
 * No OAuth token request, linked subject, Plan read, DB mutation, or
 * production setting change. HTTPS discovery is fetched only with the
 * explicit --probe-metadata option from the operator-pinned IdP URL.
 */
Artisan::command(
    'canovia:mcp-staging-preflight
        {--probe-metadata : GET the pinned IdP OAuth discovery document}
        {--json : Emit only safe structured status codes}',
    function (\App\Services\McpStagingProviderPreflightService $preflight): int {
        $result = $preflight->check((bool) $this->option('probe-metadata'));

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        } else {
            $this->info('Canovia MCP staging preflight (NO production authorization)');
            foreach (['local_checks', 'provider_checks'] as $group) {
                $this->line($group.':');
                foreach ($result[$group] as $name => $status) {
                    $this->line('  '.$name.': '.$status);
                }
            }
            $this->line('supported_client_modes: '.implode(',', $result['supported_client_modes']));
            $this->line('preflight_passed: '.($result['preflight_passed'] ? 'yes' : 'no'));
            $this->line('production_authorized: no');
            $this->warn('Manual IdP token, ChatGPT callback, isolated staging, '
                .'Plan consent and security checks remain REQUIRED.');
        }

        return $result['preflight_passed'] ? 0 : 1;
    },
)->purpose('Inspect MCP staging OAuth settings and optionally probe IdP metadata, without exposing secrets');


/**
 * Private, one-time synthetic OAuth actor provisioning only. No password,
 * external IdP subject or Plan information appears in command arguments,
 * return values or logs. Disabled except in the isolated, closed stage.
 */
Artisan::command(
    'canovia:mcp-staging-create-synthetic-owner
        {--json : Return status only, never the synthetic password}',
    function (\App\Services\McpStagingSyntheticActorBootstrap $bootstrap): int {
        $outcome = $bootstrap->provision();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'schema' => 'canovia.mcp.staging_synthetic_actor.v1',
                'status' => $outcome,
                'production_authorized' => false,
            ], JSON_THROW_ON_ERROR));
        } else {
            $this->line('Synthetic staging actor bootstrap: '.$outcome);
        }

        return $outcome === 'blocked' ? 1 : 0;
    },
)->purpose('Create one fixed synthetic Canovia user only in closed isolated staging with a private test password');

/**
 * Deliberately separate from owner creation. Run only after the isolated
 * synthetic owner exists; no external IdP identities or grants are created.
 */
Artisan::command(
    'canovia:mcp-staging-create-synthetic-plan
        {--json : Emit only a safe status code, never account or Plan details}',
    function (\App\Services\McpStagingSyntheticPlanFixtureBootstrap $bootstrap): int {
        $outcome = $bootstrap->provision(app(\App\Services\McpStagingSyntheticActorBootstrap::class));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'schema' => 'canovia.mcp.staging_synthetic_plan.v1',
                'status' => $outcome,
                'production_authorized' => false,
            ], JSON_THROW_ON_ERROR));
        } else {
            $this->line('Synthetic staging Plan fixture: '.$outcome);
        }

        return $outcome === 'blocked' ? 1 : 0;
    },
)->purpose('Prepare a fixed private synthetic Plan and Tasks only in a closed isolated stage');
