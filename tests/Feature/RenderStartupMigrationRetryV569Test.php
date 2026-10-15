<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class RenderStartupMigrationRetryV569Test extends TestCase
{
    public function test_default_startup_only_checks_ledger_and_preserves_failure_status(): void
    {
        [$code, $calls, $output] = $this->policy(['STUB_SCHEMA_EXIT' => '12']);
        $this->assertSame(12, $code);
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('canovia:release-schema-ready', $calls[0]);
        $this->assertStringNotContainsString('private-fixture-dsn', $output);
    }

    public function test_apply_rejects_missing_short_or_mismatched_sha_before_any_db_command(): void
    {
        foreach (['', 'abcdef0', str_repeat('b', 40)] as $approved) {
            [$code, $calls] = $this->policy(['CANOVIA_STARTUP_MIGRATIONS' => 'apply',
                'CANOVIA_APPROVED_MIGRATION_SHA' => $approved, 'RENDER_GIT_COMMIT' => str_repeat('a', 40)]);
            $this->assertSame(1, $code);
            $this->assertSame([], $calls);
        }
    }

    public function test_approved_apply_executes_once_and_checks_resulting_ledger(): void
    {
        [$code, $calls] = $this->policy(['CANOVIA_STARTUP_MIGRATIONS' => 'apply',
            'CANOVIA_APPROVED_MIGRATION_SHA' => str_repeat('a', 40), 'RENDER_GIT_COMMIT' => str_repeat('a', 40)]);
        $this->assertSame(0, $code);
        $this->assertCount(2, $calls);
        $this->assertStringContainsString('artisan migrate --force', $calls[0]);
        $this->assertStringContainsString('canovia:release-schema-ready', $calls[1]);
    }

    public function test_partial_ddl_failure_is_not_automatically_retried_or_rolled_back(): void
    {
        [$code, $calls, $output] = $this->policy(['CANOVIA_STARTUP_MIGRATIONS' => 'apply',
            'CANOVIA_APPROVED_MIGRATION_SHA' => str_repeat('a', 40), 'RENDER_GIT_COMMIT' => str_repeat('a', 40),
            'STUB_MIGRATION_EXIT' => '23']);
        $this->assertSame(23, $code);
        $this->assertCount(1, $calls);
        $this->assertStringNotContainsString('private-fixture-dsn', $output);
        $this->assertStringContainsString('operator review required', $output);
    }

    public function test_closed_synthetic_staging_preserves_bounded_redacted_retries(): void
    {
        [$code, $calls, $output] = $this->policy(['APP_ENV' => 'staging', 'CANOVIA_STAGING_ISOLATED' => 'true',
            'CANOVIA_MIGRATION_MAX_ATTEMPTS' => '2', 'CANOVIA_MIGRATION_RETRY_DELAY_SECONDS' => '0',
            'STUB_MIGRATION_EXIT' => '23']);
        $this->assertSame(1, $code);
        $this->assertCount(2, $calls);
        $this->assertStringNotContainsString('private-fixture-dsn', $output);
        [$code, $calls] = $this->policy(['CANOVIA_STARTUP_MIGRATIONS' => 'invalid']);
        $this->assertSame(1, $code);
        $this->assertSame([], $calls);
    }

    public function test_render_start_gates_schema_before_production_caches_and_server(): void
    {
        $script = file_get_contents(base_path('docker/render-start.sh'));
        $gate = strpos($script, 'canovia_prepare_startup_schema');
        $this->assertNotFalse($gate);
        $this->assertLessThan(strpos($script, 'php artisan config:cache'), $gate);
        $this->assertLessThan(strpos($script, 'exec /usr/bin/supervisord'), $gate);
    }

    private function policy(array $environment): array
    {
        $root = sys_get_temp_dir().'/canovia-startup-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        file_put_contents($root.'/php', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$CALL_LOG"
echo 'private-fixture-dsn' >&2
if [ "$2" = migrate ]; then exit "${STUB_MIGRATION_EXIT:-0}"; fi
exit "${STUB_SCHEMA_EXIT:-0}"
SH);
        chmod($root.'/php', 0700);
        try {
            $process = new Process(['sh', '-c', '. "$1"; canovia_prepare_startup_schema',
                'fixture', base_path('docker/startup-migration-policy.sh')], base_path(),
                array_merge(['PATH' => $root.':'.getenv('PATH'), 'CALL_LOG' => $root.'/calls',
                    'APP_ENV' => 'production', 'CANOVIA_STARTUP_MIGRATIONS' => '',
                    'CANOVIA_STAGING_ISOLATED' => 'false'], $environment));
            $code = $process->run();
            $calls = file_exists($root.'/calls') ? file($root.'/calls', FILE_IGNORE_NEW_LINES) : [];

            return [$code, $calls, $process->getOutput().$process->getErrorOutput()];
        } finally {
            foreach (glob($root.'/*') as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }
}
