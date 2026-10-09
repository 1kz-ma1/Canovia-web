<?php

namespace Tests\Feature;

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
        $this->assertStringContainsString('mcp-staging-start.sh', $docker);
        $this->assertStringContainsString('CMD ["./docker/render-start.sh"]', $prod);
    }

    private function guard(array $changes = []): Process
    {
        $env = [
            'CANOVIA_STAGING_ISOLATED' => 'true',
            'CANOVIA_STAGING_WEB_ACCESS_ENABLED' => 'false',
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
            'MYSQL_ATTR_SSL_CA','PGPASSWORD','SESSION_DOMAIN',
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
