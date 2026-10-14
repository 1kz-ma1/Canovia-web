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


/**
 * Inspect only pinned configuration shape while external OAuth/MCP remain
 * disabled. No credentials, real tokens, network or database access.
 */
Artisan::command(
    'canovia:mcp-auth0-sealed-wiring-check
        {--json : Emit only named boolean status codes}
        {--blocked-codes : Emit ONLY allowlisted failed check identifiers}',
    function (\App\Services\McpAuth0SealedStagingWiringReadiness $readiness): int {
        $report = $readiness->check();
        if ($this->option('blocked-codes')) {
            // Keys are fixed literals from our own code, never values from
            // Auth0, Render or the user. Only allow known identifiers.
            $allowedCodes = [
                'sealed_isolated_stage',
                'pinned_staging_postgres',
                'oauth_mcp_capabilities_disabled',
                'exclusive_jwt_candidate',
                'trusted_issuer_and_resource',
                'issued_chatgpt_third_party_client',
                'separate_confidential_link_client',
                'pinned_account_link_endpoints',
                'fingerprint_key_configured',
            ];
            $blockedCodes = [];
            foreach ($allowedCodes as $code) {
                if (($report['checks'][$code] ?? null) === 'blocked') {
                    $blockedCodes[] = $code;
                }
            }
            $this->line($blockedCodes === [] ? 'none' : implode(',', $blockedCodes));
        } elseif ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $this->info('Auth0 sealed-stage configuration inventory (no OAuth proof)');
            foreach ($report['checks'] as $key => $value) {
                $this->line('  '.$key.': '.$value);
            }
            $this->warn('Live issued tokens, API grants, user consent and stage activation remain unverified.');
        }

        return $report['configuration_ready'] ? 0 : 1;
    },
)->purpose('Check stage-only Auth0 wiring without revealing credentials or enabling external access');


/**
 * Explicit operator retry ONLY. No scheduler is enabled by this definition.
 * Database migration and storage/release approval are separate production gates.
 * Never print an encrypted/raw storage key or private account identifier.
 */
Artisan::command(
    'canovia:retry-private-file-cleanup
        {--limit=50 : Maximum pending cleanup entries (1..200)}
        {--dry-run : Print aggregate pending/quarantined counts without deleting}',
    function (\App\Services\PrivateFileLifecycleService $files): int {
        if ($this->option('dry-run')) {
            $counts = $files->statusCounts();
            $this->line('private_file_cleanup_pending: '.$counts['pending']);
            $this->line('private_file_cleanup_quarantined: '.$counts['quarantined']);

            return 0;
        }

        $rawLimit = (string) $this->option('limit');
        if (! ctype_digit($rawLimit) || (int) $rawLimit < 1 || (int) $rawLimit > 200) {
            $this->error('Invalid limit: expected an integer between 1 and 200.');

            return 1;
        }

        $result = $files->retryDue((int) $rawLimit);
        foreach ($result as $key => $value) {
            $this->line('private_file_cleanup_'.$key.': '.$value);
        }

        return 0;
    },
)->purpose('Retry bounded verified private file cleanup after committed deletes; operator-only, no automatic production scheduling');


/**
 * Aggregate, read-only cleanup health check. Does not scan object storage,
 * decrypt any private paths, run a retry, or configure a scheduled task.
 *
 * --strict returns 2 for attention and 1 for unavailable schema/DB.
 * Default exit 0 for attention (human operator inspection without a failing
 * shell session); unavailable schema/DB always fails closed with exit 1.
 */
Artisan::command(
    'canovia:private-file-cleanup-health
        {--json : Print only aggregate JSON without private paths or IDs}
        {--strict : Return exit 2 when quarantine or stale work needs attention}',
    function (\App\Services\PrivateFileCleanupHealthService $health): int {
        $report = $health->report();
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $this->line('Private file cleanup health: '.$report['health']);
            $this->line('schema: '.$report['schema']);
            foreach ([
                'pending', 'pending_due', 'pending_stale_24h',
                'pending_high_retry_5', 'pending_without_retry_date',
                'quarantined', 'unknown_status',
            ] as $metric) {
                $this->line($metric.': '.$report[$metric]);
            }
            foreach ($report['quarantined_reasons'] as $reason => $count) {
                $this->line('quarantine_'. $reason .': '.$count);
            }
        }

        if ($report['health'] === 'unavailable') {
            return 1;
        }
        return $report['health'] === 'attention' && $this->option('strict')
            ? 2 : 0;
    },
)->purpose('Read-only private deletion health: overdue work, high retries, quarantine and safe reason-code counts');


/**
 * G3-R13: aggregate, READ-ONLY recovery ledger visibility. Neither object
 * storage nor the encrypted path value is inspected. No cleanup is scheduled
 * or performed by this command. An intent is NOT proof of an orphan.
 */
Artisan::command(
    'canovia:private-upload-intent-health
        {--json : Print safe aggregate statuses only}
        {--strict : Exit 2 for stale, failed or unknown intents}',
    function (\App\Services\PrivateFileUploadIntentService $journal): int {
        $report = $journal->report();
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $this->line('Private upload intent health: '.$report['health']);
            $this->line('schema: '.$report['schema']);
            foreach ([
                'prepared', 'prepared_stale_24h', 'needs_review',
                'committed', 'unknown_status',
            ] as $field) {
                $this->line($field.': '.$report[$field]);
            }
        }
        if ($report['health'] === 'unavailable') {
            return 1;
        }

        return $report['health'] === 'attention' && $this->option('strict')
            ? 2 : 0;
    },
)->purpose('Read-only, redacted counts of committed pre-upload intents; never scans or deletes storage');


/**
 * G3-R14: explicitly opt-in, non-production read-only PREVIEW.
 * No object listing, byte downloads, path/ID output, lock, ledger update,
 * database owner mutation, or storage deletion. Not an orphan classifier.
 */
Artisan::command(
    'canovia:private-upload-reconcile-preview
        {--limit=10 : Maximum candidate intents to observe (1..20)}
        {--json : Return fixed, aggregate observation categories only}',
    function (\App\Services\PrivateFileUploadReconciliationPreviewService $preview): int {
        $rawLimit = (string) $this->option('limit');
        if (! ctype_digit($rawLimit)
            || (int) $rawLimit < 1 || (int) $rawLimit > 20) {
            $this->error('Invalid limit: expected 1..20.');
            return 1;
        }

        $report = $preview->preview((int) $rawLimit);
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $this->line('Private upload reconcile preview: '.$report['outcome']);
            $this->line('schema: '.$report['schema']);
            foreach (['eligible', 'observed', 'fence_row_observed',
                'fence_row_not_observed', 'destructive_actions'] as $metric) {
                $this->line($metric.': '.$report[$metric]);
            }
            foreach ($report['categories'] as $name => $count) {
                $this->line($name.': '.$count);
            }
            $this->warn('Observation only. NOT an orphan finding or deletion approval.');
        }

        return in_array($report['outcome'], ['blocked', 'unavailable'], true) ? 1 : 0;
    },
)->purpose('G3 R14 opt-in non-production aggregate preview; never lists or deletes private storage');


/**
 * G3-R16: operator-facing DESIGN CONTRACT ONLY.
 * Config-only, read-only; absolutely no object/credential lookup, storage
 * mutation, conditional deletion, or production enablement.
 */
Artisan::command(
    'canovia:private-file-generation-contract
        {--json : Print only fixed non-secret capability labels}',
    function (\App\Services\PrivateFileGenerationContractService $contract): int {
        $report = $contract->report();
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $this->line('Private file generation contract: '.$report['assessment']);
            foreach ([
                'driver', 'provider_generation', 'write_time_version_binding',
                'conditional_delete_enforcement', 'bucket_versioning',
            ] as $field) {
                $this->line($field.': '.$report[$field]);
            }
            $this->warn('This is configuration inventory, NOT provider proof or permission to delete.');
        }

        return $report['assessment'] === 'blocked' ? 1 : 0;
    },
)->purpose('Non-production, offline private object-generation readiness inventory; never grants deletion');


/**
 * G3-R17: no private path/version/owner details and no provider operations.
 * Only fixed aggregate labels for disposable synthetic-evidence fixtures.
 */
Artisan::command(
    'canovia:private-file-generation-evidence-health
        {--json : Print redacted synthetic-only evidence counts}',
    function (\App\Services\PrivateFileGenerationEvidenceService $service): int {
        $report = $service->report();
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $this->line('Private generation evidence: '.$report['status']);
            $this->line('schema: '.$report['schema']);
            $this->line('synthetic_count: '.$report['synthetic_count']);
            $this->warn('Synthetic fixtures are not provider attestations or deletion approval.');
        }
        return $report['status'] === 'synthetic_only' ? 0 : 1;
    },
)->purpose('Read-only, aggregate synthetic generation evidence health; no storage access or deletion');


/**
 * G3-R20: test-only write-ahead attempt health. No file/network I/O,
 * attempt identifiers, private paths, request tokens or automatic replay.
 */
Artisan::command(
    'canovia:private-file-write-attempt-health
        {--json : Only redacted aggregate synthetic attempt counts}',
    function (\App\Services\PrivateFileWriteAttemptService $attempts): int {
        $report = $attempts->report();
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_THROW_ON_ERROR));
        } else {
            $this->line('Private file write attempts: '.$report['status']);
            $this->line('schema: '.$report['schema']);
            $this->line('reserved: '.$report['reserved']);
            $this->line('synthetic_ack_recorded: '.$report['synthetic_ack_recorded']);
            $this->warn('Never replay/delete an ambiguous request; synthetic receipts are not provider attestations.');
        }
        return $report['status'] === 'synthetic_only' ? 0 : 1;
    },
)->purpose('R20 synthetic-only read-only write-attempt health; always denies replay/deletion');
