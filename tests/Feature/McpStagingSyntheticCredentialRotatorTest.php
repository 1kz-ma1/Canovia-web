<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\McpStagingSyntheticActorBootstrap;
use App\Services\McpStagingSyntheticCredentialRotator;
use App\Services\McpStagingSyntheticPlanFixtureBootstrap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class McpStagingSyntheticCredentialRotatorTest extends TestCase
{
    use RefreshDatabase;

    private const INITIAL = 'STAGING-SYNTHETIC-PASSWORD-ONLY-0123456789';
    private const ROTATED = 'synthetic-only-password-ROTATED-abc123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.web_access_enabled' => false,
            'canovia_staging.web_access_explicitly_approved' => false,
            'canovia_staging.allow_synthetic_owner_bootstrap' => true,
            'canovia_staging.synthetic_owner_password' => self::INITIAL,
            'canovia_staging.allow_synthetic_password_rotation' => true,
            'canovia_staging.rotate_synthetic_password_on_start' => true,
            'canovia_staging.synthetic_owner_rotated_password' => self::ROTATED,
            'canovia_mcp.discovery_enabled' => false,
            'canovia_mcp.token_introspection_enabled' => false,
            'canovia_mcp.account_link_enabled' => false,
            'canovia_mcp.plan_consent_enabled' => false,
            'canovia_mcp.delegated_policy_enabled' => false,
            'canovia_mcp.tools_enabled' => false,
        ]);
    }

    private function createFixture(): void
    {
        $this->assertSame(0, Artisan::call('canovia:mcp-staging-create-synthetic-owner'));
        $this->assertSame(0, Artisan::call('canovia:mcp-staging-create-synthetic-plan'));
        config([
            'canovia_staging.allow_synthetic_owner_bootstrap' => false,
            'canovia_staging.synthetic_owner_password' => '',
        ]);
    }

    public function test_rotation_replaces_only_synthetic_password_and_is_idempotent(): void
    {
        $this->createFixture();
        $actor = User::query()->sole();
        $previousHash = $actor->password;
        $plan = Plan::query()->sole();
        $planId = $plan->id;
        $taskIds = Task::query()->orderBy('id')->pluck('id')->all();

        $this->assertSame(0, Artisan::call('canovia:mcp-staging-rotate-synthetic-password', ['--json' => true]));
        $this->assertStringContainsString('"rotated"', Artisan::output());
        $this->assertStringNotContainsString(self::ROTATED, Artisan::output());
        $this->assertStringNotContainsString(McpStagingSyntheticActorBootstrap::EMAIL, Artisan::output());

        $updated = $actor->fresh();
        $this->assertTrue(Hash::check(self::ROTATED, $updated->password));
        $this->assertFalse(Hash::check(self::INITIAL, $updated->password));
        $this->assertNotSame($previousHash, $updated->password);
        $this->assertSame($planId, Plan::query()->sole()->id);
        $this->assertSame($taskIds, Task::query()->orderBy('id')->pluck('id')->all());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('mcp_linked_subjects', 0);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);

        $hash = $updated->password;
        $this->assertSame(0, Artisan::call('canovia:mcp-staging-rotate-synthetic-password', ['--json' => true]));
        $this->assertStringContainsString('"already_current"', Artisan::output());
        $this->assertSame($hash, $actor->fresh()->password);

        config([
            'canovia_staging.allow_synthetic_password_rotation' => false,
            'canovia_staging.rotate_synthetic_password_on_start' => false,
            'canovia_staging.synthetic_owner_rotated_password' => '',
        ]);
        $this->assertSame('ready',
            app(McpStagingSyntheticPlanFixtureBootstrap::class)->verify(
                app(McpStagingSyntheticActorBootstrap::class)
            ));
    }

    public function test_rotation_is_refused_without_exact_private_fixture_or_secret(): void
    {
        $rotator = app(McpStagingSyntheticCredentialRotator::class);
        $owner = app(McpStagingSyntheticActorBootstrap::class);
        $fixture = app(McpStagingSyntheticPlanFixtureBootstrap::class);

        $this->assertSame('blocked', $rotator->rotate($owner, $fixture));
        $this->assertDatabaseCount('users', 0);

        $this->createFixture();
        $actor = User::query()->sole();
        $initialHash = $actor->password;

        foreach ([
            ['app.env' => 'production'],
            ['app.env' => 'local'],
            ['canovia_staging.isolated' => false],
            ['canovia_staging.web_access_enabled' => true],
            ['canovia_staging.web_access_explicitly_approved' => true],
            ['canovia_staging.allow_synthetic_password_rotation' => false],
            ['canovia_staging.rotate_synthetic_password_on_start' => false],
            ['canovia_staging.synthetic_owner_rotated_password' => 'too-short'],
            ['canovia_staging.synthetic_owner_rotated_password' => ''],
            ['canovia_staging.synthetic_owner_rotated_password' => str_repeat('a', 129)],
            ['canovia_staging.synthetic_owner_rotated_password' => "invalid\ncontrol-characters-password"],
            ['canovia_mcp.discovery_enabled' => true],
            ['canovia_mcp.token_introspection_enabled' => true],
            ['canovia_mcp.account_link_enabled' => true],
            ['canovia_mcp.plan_consent_enabled' => true],
            ['canovia_mcp.delegated_policy_enabled' => true],
            ['canovia_mcp.tools_enabled' => true],
            ['database.default' => 'mysql'],
            ['database.connections.sqlite.database' => '/tmp/unpinned-prod.sqlite'],
        ] as $settings) {
            $old = [];
            foreach ($settings as $key => $value) {
                $old[$key] = config($key);
            }
            config($settings);
            $this->assertSame('blocked', $rotator->rotate($owner, $fixture));
            config($old);
        }

        $this->assertSame($initialHash, $actor->fresh()->password);
        $this->assertDatabaseCount('plans', 1);
        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_rotation_refuses_tampered_or_extra_fixture_without_modification(): void
    {
        $this->createFixture();
        $actor = User::query()->sole();
        $initialHash = $actor->password;
        $task = Task::query()->firstOrFail();
        $task->update(['title' => 'Unexpected title']);

        $this->assertSame('blocked',
            app(McpStagingSyntheticCredentialRotator::class)->rotate(
                app(McpStagingSyntheticActorBootstrap::class),
                app(McpStagingSyntheticPlanFixtureBootstrap::class)
            ));
        $this->assertSame($initialHash, $actor->fresh()->password);
        $this->assertSame('Unexpected title', $task->fresh()->title);
    }
}
