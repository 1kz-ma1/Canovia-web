<?php

namespace Tests\Feature;

use Tests\TestCase;

class RenderStartupMigrationRetryV569Test extends TestCase
{
    public function test_render_start_retries_only_known_transient_database_failures(): void
    {
        $script = file_get_contents(base_path('docker/render-start.sh'));

        $this->assertIsString($script);
        $this->assertStringContainsString('CANOVIA_MIGRATION_MAX_ATTEMPTS:-5', $script);
        $this->assertStringContainsString('CANOVIA_MIGRATION_RETRY_DELAY_SECONDS:-8', $script);
        $this->assertStringContainsString('SQLSTATE[08S01]', $script);
        $this->assertStringContainsString('SQLSTATE[HY000] [2002]', $script);
        $this->assertStringContainsString('SQLSTATE[HY000] [2006]', $script);
        $this->assertStringContainsString('SQLSTATE[HY000] [2013]', $script);
        $this->assertStringContainsString('Got timeout reading communication packets', $script);
        $this->assertStringContainsString('exit "$migration_status"', $script);
    }

    public function test_render_start_keeps_migrations_before_production_caches_and_server_start(): void
    {
        $script = file_get_contents(base_path('docker/render-start.sh'));

        $migration = strpos($script, 'php artisan migrate --force');
        $cache = strpos($script, 'php artisan config:cache');
        $server = strpos($script, 'exec /usr/bin/supervisord');

        $this->assertNotFalse($migration);
        $this->assertNotFalse($cache);
        $this->assertNotFalse($server);
        $this->assertLessThan($cache, $migration);
        $this->assertLessThan($server, $cache);
    }
}
