<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\McpStagingSyntheticActorBootstrap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class McpStagingSyntheticActorBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.web_access_enabled' => false,
            'canovia_staging.allow_synthetic_owner_bootstrap' => true,
            'canovia_staging.synthetic_owner_password' => 'STAGING-SYNTHETIC-PASSWORD-ONLY-0123456789',
        ]);
    }

    public function test_explicit_closed_stage_can_create_only_one_fixed_synthetic_actor(): void
    {
        $code = Artisan::call('canovia:mcp-staging-create-synthetic-owner', ['--json' => true]);
        $this->assertSame(0, $code);

        $actor = User::query()->sole();
        $this->assertSame(McpStagingSyntheticActorBootstrap::EMAIL, $actor->email);
        $this->assertSame('Canovia MCP Synthetic Owner', $actor->name);
        $this->assertNotNull($actor->email_verified_at);
        $this->assertNotNull($actor->first_run_completed_at);
        $this->assertTrue(Hash::check(
            'STAGING-SYNTHETIC-PASSWORD-ONLY-0123456789',
            $actor->password,
        ));

        $text = Artisan::output();
        $this->assertStringContainsString('"created"', $text);
        $this->assertStringNotContainsString('STAGING-SYNTHETIC-PASSWORD-ONLY-0123456789', $text);
        $this->assertStringNotContainsString($actor->password, $text);

        $again = Artisan::call('canovia:mcp-staging-create-synthetic-owner', ['--json' => true]);
        $this->assertSame(0, $again);
        $this->assertStringContainsString('"already_present"', Artisan::output());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_production_or_nonisolated_environment_is_always_denied(): void
    {
        foreach ([
            ['app.env' => 'production'],
            ['app.env' => 'local'],
            ['canovia_staging.isolated' => false],
            ['canovia_staging.web_access_enabled' => true],
            ['canovia_staging.allow_synthetic_owner_bootstrap' => false],
            ['canovia_staging.synthetic_owner_password' => 'short'],
            ['canovia_staging.synthetic_owner_password' => ''],
            ['canovia_staging.synthetic_owner_password' => str_repeat('a', 129)],
            ['canovia_staging.synthetic_owner_password' => "weak\ncontrol-characters-are-not-allowed"],
        ] as $settings) {
            $original = [];
            foreach ($settings as $key => $value) {
                $original[$key] = config($key);
            }
            config($settings);

            $this->assertSame('blocked',
                app(McpStagingSyntheticActorBootstrap::class)->provision());

            config($original);
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_any_non_pinned_database_is_refused_before_query(): void
    {
        foreach ([
            ['database.default' => 'mysql'],
            ['database.default' => 'pgsql'],
            ['database.connections.sqlite.database' => '/tmp/canovia-production.sqlite'],
            ['database.connections.sqlite.database' => ':memory:/fake'],
            ['database.connections.pgsql.database' => 'production_db',
                'database.default' => 'pgsql'],
            ['database.connections.pgsql.database' => 'canovia_mcp_staging_db',
                'database.default' => 'pgsql',
                'canovia_staging.postgres_id' => 'different-resource'],
            ['database.connections.pgsql.database' => 'canovia_mcp_staging_db',
                'database.default' => 'pgsql',
                'canovia_staging.postgres_id' => 'dpg-db43rbbncjis73bmigi0-a',
                'database.connections.pgsql.url' => 'postgresql://prod:secret@aiven.example.test/prod'],
        ] as $settings) {
            $original = [];
            foreach ($settings as $key => $value) {
                $original[$key] = config($key);
            }
            config($settings);
            $this->assertSame('blocked',
                app(McpStagingSyntheticActorBootstrap::class)->provision());
            config($original);
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_actor_bootstrap_is_not_an_external_id_link_or_plan_sharing_grant(): void
    {
        Artisan::call('canovia:mcp-staging-create-synthetic-owner');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('plans', 0);
        $this->assertDatabaseCount('mcp_linked_subjects', 0);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        $this->assertDatabaseCount('mcp_delegated_access_events', 0);
    }
}
