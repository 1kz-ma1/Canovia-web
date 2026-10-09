<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class McpIsolatedStagingBootstrapTest extends TestCase
{
    public function test_staging_starts_closed_except_health_route(): void
    {
        $this->withoutVite();
        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.web_access_enabled' => false,
        ]);
        $this->get('/up')->assertOk();
        $this->get('/login')->assertStatus(503);
        $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1,
            'method' => 'tools/list'])->assertStatus(503);
    }

    public function test_stage_postgres_health_fails_closed_without_credentials_or_exact_pin(): void
    {
        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.web_access_enabled' => false,
            'canovia_staging.database_mode' => 'render_postgres',
            'canovia_staging.postgres_id' => 'dpg-db43rbbncjis73bmigi0-a',
            'canovia_staging.postgres_user' => 'canovia_mcp_staging_db_user',
            'database.default' => 'sqlite',
        ]);

        // No DB call is made if the configured runtime is wrong.
        $this->get('/up')->assertStatus(503)
            ->assertSee('Staging service unavailable.')
            ->assertDontSee('postgres')
            ->assertHeader('Cache-Control', 'no-store, private');

        config(['database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'canovia_mcp_staging_db']);
        $this->get('/up')->assertStatus(503);

        config(['canovia_staging.postgres_id' => 'other-resource']);
        $this->get('/up')->assertStatus(503);

        config(['canovia_staging.database_mode' => 'invalid']);
        $this->get('/up')->assertStatus(503);
    }

    public function test_stage_postgres_health_checks_exact_database_and_all_required_tables(): void
    {
        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.web_access_enabled' => false,
            'canovia_staging.database_mode' => 'render_postgres',
            'canovia_staging.postgres_id' => 'dpg-db43rbbncjis73bmigi0-a',
            'canovia_staging.postgres_user' => 'canovia_mcp_staging_db_user',
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'canovia_mcp_staging_db',
            'database.connections.pgsql.url' =>
                'postgresql://canovia_mcp_staging_db_user:synthetic-not-a-real-secret@dpg-db43rbbncjis73bmigi0-a:5432/canovia_mcp_staging_db',
        ]);

        $db = Mockery::mock();
        $db->shouldReceive('selectOne')->twice()->andReturn(
            (object) ['db_name' => 'canovia_mcp_staging_db'],
            (object) ['present_count' => 6],
        );
        DB::shouldReceive('connection')->once()->with('pgsql')->andReturn($db);
        $this->get('/up')->assertOk();
        $this->get('/login')->assertStatus(503);
    }

    public function test_stage_postgres_health_cannot_report_ready_if_schema_is_incomplete(): void
    {
        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.database_mode' => 'render_postgres',
            'canovia_staging.postgres_id' => 'dpg-db43rbbncjis73bmigi0-a',
            'canovia_staging.postgres_user' => 'canovia_mcp_staging_db_user',
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'canovia_mcp_staging_db',
            'database.connections.pgsql.url' =>
                'postgresql://canovia_mcp_staging_db_user:synthetic-not-a-real-secret@dpg-db43rbbncjis73bmigi0-a:5432/canovia_mcp_staging_db',
        ]);

        $db = Mockery::mock();
        $db->shouldReceive('selectOne')->twice()->andReturn(
            (object) ['db_name' => 'canovia_mcp_staging_db'],
            (object) ['present_count' => 5],
        );
        DB::shouldReceive('connection')->once()->with('pgsql')->andReturn($db);
        $this->get('/up')->assertStatus(503)
            ->assertDontSee('synthetic-not-a-real-secret');
    }

    public function test_staging_cannot_open_without_isolation_marker(): void
    {
        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => false,
            'canovia_staging.web_access_enabled' => true,
        ]);
        $this->get('/up')->assertStatus(503);
        $this->get('/login')->assertStatus(503);
    }

    public function test_live_routing_is_unchanged(): void
    {
        $this->withoutVite();
        config(['app.env' => 'production',
            'canovia_staging.isolated' => false,
            'canovia_staging.web_access_enabled' => false]);
        $this->get('/up')->assertOk();
        $this->get('/login')->assertOk();
    }

    public function test_entrypoint_accepts_only_safe_staging_settings(): void
    {
        $check = $this->guard();
        $this->assertTrue($check->isSuccessful(), $check->getErrorOutput());
        $this->assertStringContainsString('PASS', $check->getOutput());

        foreach ([
            ['APP_ENV' => 'production'],
            ['CANOVIA_STAGING_ISOLATED' => 'false'],
            ['APP_DEBUG' => 'true'],
            ['CANOVIA_STAGING_POSTGRES_USER' => 'wrong_staging_user'],
            ['CANOVIA_STAGING_POSTGRES_USER' => ''],
            ['CANOVIA_STAGING_POSTGRES_USER' => 'UPPERCASE'],
            ['DB_CONNECTION' => 'mysql'],
            ['DB_DATABASE' => 'live'],
            ['DB_HOST' => 'production.example.test'],
            ['DB_URL' => 'mysql://bad-host.example.test/live'],
            ['DB_PASSWORD' => 'not-isolated'],
            ['SESSION_DRIVER' => 'database'],
            ['CACHE_STORE' => 'database'],
            ['QUEUE_CONNECTION' => 'database'],
            ['MAIL_MAILER' => 'smtp'],
            ['SESSION_DOMAIN' => '.canovia.com'],
            ['SESSION_COOKIE' => 'pace-keeper-session'],
            ['APP_URL' => 'https://pacekeeper-d3mm.onrender.com'],
            ['APP_URL' => 'https://canovia-mcp-staging.onrender.com/path'],
            ['APP_URL' => 'http://canovia-mcp-staging.onrender.com'],
            ['APP_KEY' => 'not-a-laravel-staging-key'],
            ['GITHUB_APP_ID' => 'production-identifier'],
            ['OPENAI_API_KEY' => 'production-credential'],
            ['STRIPE_SECRET' => 'payment-credential'],
            ['CANOVIA_MCP_TOOLS_ENABLED' => 'true'],
        ] as $unsafe) {
            $process = $this->guard($unsafe);
            $this->assertSame(42, $process->getExitCode(),
                'The staging guard must deny: '.implode(',', array_keys($unsafe)));
            $this->assertStringContainsString('BLOCKED', $process->getErrorOutput());
            $this->assertStringNotContainsString('production-credential',
                $process->getErrorOutput());
        }
    }

    public function test_pinned_staging_postgres_is_the_only_accepted_external_database(): void
    {
        $url = 'postgresql://canovia_mcp_staging_db_user:'
            .'synthetic-do-not-use-this-password@dpg-db43rbbncjis73bmigi0-a:5432/'
            .'canovia_mcp_staging_db';
        $valid = $this->guard([
            'CANOVIA_STAGING_DB_MODE' => 'render_postgres',
            'CANOVIA_STAGING_POSTGRES_ID' => 'dpg-db43rbbncjis73bmigi0-a',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'canovia_mcp_staging_db',
            'CANOVIA_STAGING_POSTGRES_USER' => 'canovia_mcp_staging_db_user',
            'DB_URL' => $url,
        ]);
        $this->assertTrue($valid->isSuccessful(), $valid->getErrorOutput());

        foreach ([
            ['CANOVIA_STAGING_POSTGRES_ID' => 'dpg-other-instance'],
            ['DB_CONNECTION' => 'mysql'],
            ['DB_DATABASE' => 'production'],
            ['DB_URL' => 'postgresql://prod:prod@aiven.example.test/prod'],
            ['DB_URL' => 'postgresql://canovia_mcp_staging_db_user:'
                .'fake-password@dpg-other.render.com:5432/canovia_mcp_staging_db'],
            ['DB_URL' => 'postgresql://canovia_mcp_staging_db_user:'
                .'fake-password@dpg-db43rbbncjis73bmigi0-a:5432/prod'],
            ['DB_URL' => 'postgresql://wrong_user:'
                .'fake-password@dpg-db43rbbncjis73bmigi0-a:5432/canovia_mcp_staging_db'],
            ['DB_URL' => 'postgresql://canovia_mcp_staging_db_user:'
                .'fake-password@dpg-db43rbbncjis73bmigi0-a:6432/canovia_mcp_staging_db'],
            ['DB_URL' => 'postgresql://canovia_mcp_staging_db_user:'
                .'fake-password@dpg-db43rbbncjis73bmigi0-a:5432/canovia_mcp_staging_db?sslmode=disable'],
            ['DB_URL' => 'http://canovia_mcp_staging_db_user:'
                .'fake-password@dpg-db43rbbncjis73bmigi0-a/canovia_mcp_staging_db'],
            ['DATABASE_URL' => 'postgresql://test:secret@example.test/live'],
            ['PGHOST' => 'aiven.example.test'],
            ['DB_HOST' => 'aiven.example.test'],
            ['DB_PASSWORD' => 'live-secret'],
        ] as $unsafe) {
            $out = $this->guard([
                'CANOVIA_STAGING_DB_MODE' => 'render_postgres',
                'CANOVIA_STAGING_POSTGRES_ID' => 'dpg-db43rbbncjis73bmigi0-a',
                'DB_CONNECTION' => 'pgsql',
                'DB_DATABASE' => 'canovia_mcp_staging_db',
                'CANOVIA_STAGING_POSTGRES_USER' => 'canovia_mcp_staging_db_user',
                'DB_URL' => $url,
                ...$unsafe,
            ]);
            $this->assertSame(42, $out->getExitCode(),
                'A production-like DB setting bypassed the staging guard: '.implode(',', array_keys($unsafe)));
            $this->assertStringNotContainsString('synthetic-do-not-use-this-password',
                $out->getOutput().$out->getErrorOutput());
            $this->assertStringContainsString('BLOCKED', $out->getErrorOutput());
        }
    }

    public function test_staging_postgres_never_activates_if_no_url_or_on_default_sqlite_mode(): void
    {
        $withoutUrl = $this->guard([
            'CANOVIA_STAGING_DB_MODE' => 'render_postgres',
            'CANOVIA_STAGING_POSTGRES_ID' => 'dpg-db43rbbncjis73bmigi0-a',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'canovia_mcp_staging_db',
            'CANOVIA_STAGING_POSTGRES_USER' => 'canovia_mcp_staging_db_user',
        ]);
        $this->assertSame(42, $withoutUrl->getExitCode());

        $sqliteWithUrl = $this->guard([
            'DB_URL' => 'postgresql://staging:staging@dpg-db43rbbncjis73bmigi0-a/staging',
        ]);
        $this->assertSame(42, $sqliteWithUrl->getExitCode());
    }

    public function test_mcp_activation_requires_separate_staging_review(): void
    {
        $check = $this->guard(['CANOVIA_MCP_TOOLS_ENABLED' => 'true',
            'CANOVIA_STAGING_MCP_EXPLICITLY_APPROVED' => 'true']);
        $this->assertTrue($check->isSuccessful(), $check->getErrorOutput());
    }

    public function test_blueprint_has_no_auto_deploy_or_live_database_binding(): void
    {
        $blueprint = file_get_contents(base_path('deploy/render-mcp-staging.yaml'));
        $docker = file_get_contents(base_path('Dockerfile.mcp-staging'));
        $prod = file_get_contents(base_path('Dockerfile'));
        $this->assertStringContainsString('name: canovia-mcp-staging', $blueprint);
        $this->assertStringContainsString('autoDeployTrigger: off', $blueprint);
        $this->assertStringContainsString('plan: free', $blueprint);
        $this->assertStringContainsString('value: sqlite', $blueprint);
        $this->assertStringNotContainsString('fromDatabase:', $blueprint);
        $this->assertStringContainsString('pdo_sqlite', $docker);
        $this->assertStringContainsString('pdo_pgsql', $docker);
        $this->assertStringContainsString('mcp-staging-start.sh', $docker);
        $this->assertStringContainsString('CMD ["./docker/render-start.sh"]', $prod);
    }

    private function guard(array $changes = []): Process
    {
        $env = [
            'CANOVIA_STAGING_ISOLATED' => 'true',
            'CANOVIA_STAGING_WEB_ACCESS_ENABLED' => 'false',
            'CANOVIA_STAGING_DB_MODE' => 'sqlite',
            'CANOVIA_STAGING_MCP_EXPLICITLY_APPROVED' => 'false',
            'CANOVIA_MCP_TOOLS_ENABLED' => 'false',
            'APP_ENV' => 'staging',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://canovia-mcp-staging.onrender.com',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => '/var/www/html/storage/app/staging/mcp.sqlite',
            'SESSION_DRIVER' => 'file',
            'SESSION_COOKIE' => 'canovia_mcp_staging_session',
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'log',
        ];
        foreach (['DB_URL','DATABASE_URL','DB_HOST','DB_USERNAME','DB_PASSWORD',
            'MYSQL_ATTR_SSL_CA','PGPASSWORD','PGHOST','PGDATABASE','DB_SOCKET','SESSION_DOMAIN',
            'GITHUB_READ_TOKEN','GITHUB_APP_ID','GITHUB_APP_PRIVATE_KEY',
            'GITHUB_APP_PRIVATE_KEY_BASE64','GITHUB_APP_WEBHOOK_SECRET',
            'GITHUB_TOKEN','OPENAI_API_KEY','ANTHROPIC_API_KEY',
            'STRIPE_SECRET','STRIPE_WEBHOOK_SECRET','AWS_ACCESS_KEY_ID',
            'AWS_SECRET_ACCESS_KEY','RESEND_API_KEY'] as $name) {
            $env[$name] = '';
        }

        $proc = new Process(['sh', base_path('docker/mcp-staging-start.sh'),
            '--check-only'], base_path(), array_replace($env, $changes), null, 5);
        $proc->run();
        return $proc;
    }
}
