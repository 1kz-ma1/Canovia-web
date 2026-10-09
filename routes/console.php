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

/**
 * Inspect only the exact isolated synthetic fixture without revealing IDs,
 * counts, credentials or plan content. Does not create or mutate anything.
 */
Artisan::command(
    'canovia:mcp-staging-verify-synthetic-plan
        {--json : Return only safe readiness codes}',
    function (\App\Services\McpStagingSyntheticPlanFixtureBootstrap $fixture): int {
        $state = $fixture->verify(app(\App\Services\McpStagingSyntheticActorBootstrap::class));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'schema' => 'canovia.mcp.staging_synthetic_plan_readiness.v1',
                'status' => $state,
                'production_authorized' => false,
            ], JSON_THROW_ON_ERROR));
        } else {
            $this->line('Synthetic staging Plan readiness: '.$state);
        }

        return $state === 'ready' ? 0 : 1;
    },
)->purpose('Read-only verify isolated synthetic Plan fixture without exposing its contents');

/**
 * One-shot and stage-only. No route, email, password or token appears in output.
 * Requires two explicit OFF-by-default environment approvals and a freshly
 * supplied private secret in the isolated Render staging environment.
 */
Artisan::command(
    'canovia:mcp-staging-rotate-synthetic-password
        {--json : Print only one safe status code}',
    function (\App\Services\McpStagingSyntheticCredentialRotator $rotator): int {
        $result = $rotator->rotate(
            app(\App\Services\McpStagingSyntheticActorBootstrap::class),
            app(\App\Services\McpStagingSyntheticPlanFixtureBootstrap::class),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'schema' => 'canovia.mcp.staging_synthetic_password_rotation.v1',
                'status' => $result,
                'production_authorized' => false,
            ], JSON_THROW_ON_ERROR));
        } else {
            $this->line('Synthetic staging credential rotation: '.$result);
        }

        return $result === 'blocked' ? 1 : 0;
    },
)->purpose('Replace the fixed synthetic MCP owner password only in an isolated closed stage');
