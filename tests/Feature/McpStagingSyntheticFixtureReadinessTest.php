<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Services\McpStagingSyntheticActorBootstrap;
use App\Services\McpStagingSyntheticPlanFixtureBootstrap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class McpStagingSyntheticFixtureReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.web_access_enabled' => false,
            'canovia_staging.web_access_explicitly_approved' => false,
            'canovia_staging.allow_synthetic_owner_bootstrap' => true,
            'canovia_staging.synthetic_owner_password' => 'STAGING-SYNTHETIC-PASSWORD-ONLY-0123456789',
            'canovia_mcp.tools_enabled' => false,
        ]);
    }

    private function seedFixture(): void
    {
        $this->assertSame(0, Artisan::call('canovia:mcp-staging-create-synthetic-owner'));
        $this->assertSame(0, Artisan::call('canovia:mcp-staging-create-synthetic-plan'));
    }

    public function test_closed_staging_readiness_is_safe_and_does_not_need_bootstrap_secret(): void
    {
        $this->assertSame(1, Artisan::call('canovia:mcp-staging-verify-synthetic-plan', ['--json' => true]));
        $this->assertStringContainsString('"blocked"', Artisan::output());

        $this->seedFixture();
        $plan = Plan::query()->sole();
        $createdAt = $plan->created_at;
        $ownerToken = $plan->owner_token;

        // Bootstrap approvals and the private password have been removed on
        // the sealed follow-up deployment; read-only readiness still works.
        config([
            'canovia_staging.allow_synthetic_owner_bootstrap' => false,
            'canovia_staging.synthetic_owner_password' => '',
        ]);
        $this->assertSame(0, Artisan::call('canovia:mcp-staging-verify-synthetic-plan', ['--json' => true]));
        $this->assertStringContainsString('"ready"', Artisan::output());
        $this->assertStringContainsString('"production_authorized":false', Artisan::output());
        $this->assertStringNotContainsString(McpStagingSyntheticActorBootstrap::EMAIL, Artisan::output());
        $this->assertStringNotContainsString($ownerToken, Artisan::output());
        $this->assertSame($createdAt->toISOString(), $plan->fresh()->created_at->toISOString());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('plans', 1);
        $this->assertDatabaseCount('tasks', 2);
        $this->assertDatabaseCount('mcp_linked_subjects', 0);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        $this->assertDatabaseCount('mcp_delegated_access_events', 0);
    }

    public function test_readiness_refuses_production_exposure_or_wrong_database(): void
    {
        $this->seedFixture();
        $fixture = app(McpStagingSyntheticPlanFixtureBootstrap::class);
        $owner = app(McpStagingSyntheticActorBootstrap::class);

        foreach ([
            ['app.env' => 'production'],
            ['canovia_staging.isolated' => false],
            ['canovia_staging.web_access_enabled' => true],
            ['canovia_staging.web_access_explicitly_approved' => true],
            ['canovia_mcp.tools_enabled' => true],
            ['database.default' => 'mysql'],
            ['database.connections.sqlite.database' => '/tmp/unpinned.sqlite'],
        ] as $settings) {
            $original = [];
            foreach ($settings as $key => $value) {
                $original[$key] = config($key);
            }
            config($settings);
            $this->assertSame('blocked', $fixture->verify($owner));
            config($original);
        }

        $this->assertSame('ready', $fixture->verify($owner));
    }

    public function test_readiness_refuses_mutated_fixture_without_repairing_it(): void
    {
        $this->seedFixture();
        $task = Task::query()->orderBy('sort_order')->firstOrFail();
        $task->update(['progress_percent' => 99]);
        $this->assertSame(1, Artisan::call('canovia:mcp-staging-verify-synthetic-plan'));
        $this->assertSame(99, $task->fresh()->progress_percent);

        $task->update(['progress_percent' => 25]);
        $this->assertSame(0, Artisan::call('canovia:mcp-staging-verify-synthetic-plan'));
        $plan = Plan::query()->sole();
        $plan->update(['is_public' => true]);
        $this->assertSame(1, Artisan::call('canovia:mcp-staging-verify-synthetic-plan'));
        $this->assertTrue($plan->fresh()->is_public);
    }
}
