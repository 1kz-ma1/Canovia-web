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
