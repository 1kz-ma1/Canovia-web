<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\McpStagingSyntheticActorBootstrap;
use App\Services\McpStagingSyntheticPlanFixtureBootstrap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class McpStagingSyntheticPlanFixtureBootstrapTest extends TestCase
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

    private function createActor(): void
    {
        $this->assertSame(0, Artisan::call('canovia:mcp-staging-create-synthetic-owner'));
    }

    public function test_fixture_requires_preexisting_synthetic_owner_and_is_private(): void
    {
        $this->assertSame(1, Artisan::call('canovia:mcp-staging-create-synthetic-plan', ['--json' => true]));
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('plans', 0);

        $this->createActor();
        $this->assertSame(0, Artisan::call('canovia:mcp-staging-create-synthetic-plan', ['--json' => true]));

        $actor = User::query()->sole();
        $plan = Plan::query()->sole();
        $this->assertSame(McpStagingSyntheticActorBootstrap::EMAIL, $actor->email);
        $this->assertSame($actor->id, $plan->user_id);
        $this->assertSame(McpStagingSyntheticPlanFixtureBootstrap::REQUEST_ID, $plan->creation_request_id);
        $this->assertSame(McpStagingSyntheticPlanFixtureBootstrap::TITLE, $plan->title);
        $this->assertFalse($plan->is_public);
        $this->assertFalse($plan->is_collaborative);
        $this->assertNotEmpty($plan->owner_token);
        $this->assertSame(2, $plan->tasks()->count());
        $this->assertSame(
            [McpStagingSyntheticPlanFixtureBootstrap::FIRST_TASK,
                McpStagingSyntheticPlanFixtureBootstrap::SECOND_TASK],
            $plan->tasks()->orderBy('sort_order')->pluck('title')->all()
        );
        $this->assertDatabaseCount('mcp_linked_subjects', 0);
        $this->assertDatabaseCount('mcp_delegated_grants', 0);
        $this->assertDatabaseCount('mcp_delegated_access_events', 0);

        $output = Artisan::output();
        $this->assertStringContainsString('"created"', $output);
        $this->assertStringNotContainsString($actor->email, $output);
        $this->assertStringNotContainsString((string) $plan->owner_token, $output);
        $this->assertStringNotContainsString('STAGING-SYNTHETIC-PASSWORD-ONLY', $output);

        $this->assertSame(0, Artisan::call('canovia:mcp-staging-create-synthetic-plan', ['--json' => true]));
        $this->assertStringContainsString('"already_present"', Artisan::output());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('plans', 1);
        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_fixture_denies_prod_open_web_mcp_and_nonpinned_database(): void
    {
        $this->createActor();

        foreach ([
            ['app.env' => 'production'],
            ['canovia_staging.isolated' => false],
            ['canovia_staging.web_access_enabled' => true],
            ['canovia_staging.web_access_explicitly_approved' => true],
            ['canovia_mcp.tools_enabled' => true],
            ['canovia_staging.allow_synthetic_owner_bootstrap' => false],
            ['canovia_staging.synthetic_owner_password' => ''],
            ['database.default' => 'mysql'],
            ['database.connections.sqlite.database' => '/tmp/production.sqlite'],
        ] as $settings) {
            $original = [];
            foreach ($settings as $key => $value) {
                $original[$key] = config($key);
            }
            config($settings);

            $this->assertSame('blocked',
                app(McpStagingSyntheticPlanFixtureBootstrap::class)->provision(
                    app(McpStagingSyntheticActorBootstrap::class)
                ));

            config($original);
        }

        $this->assertDatabaseCount('plans', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_fixture_refuses_foreign_user_and_existing_data(): void
    {
        $this->createActor();
        $other = User::factory()->create();
        $this->assertSame(1, Artisan::call('canovia:mcp-staging-create-synthetic-plan'));
        $this->assertDatabaseCount('plans', 0);
        $other->delete();

        Plan::query()->create([
            'user_id' => User::query()->sole()->id,
            'owner_token' => 'unrelated-owner-token',
            'public_slug' => '7815fdc1-d106-4877-a011-33e7a830d611',
            'title' => 'Unrelated plan',
            'start_date' => today(),
            'deadline' => today()->addWeek(),
            'is_public' => false,
        ]);
        $this->assertSame(1, Artisan::call('canovia:mcp-staging-create-synthetic-plan'));
        $this->assertDatabaseCount('plans', 1);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_fixture_refuses_mutation_of_existing_fixture(): void
    {
        $this->createActor();
        $this->assertSame(0, Artisan::call('canovia:mcp-staging-create-synthetic-plan'));
        $plan = Plan::query()->sole();
        $plan->update(['is_public' => true]);

        $this->assertSame(1, Artisan::call('canovia:mcp-staging-create-synthetic-plan'));
        $this->assertTrue($plan->fresh()->is_public);
        $this->assertDatabaseCount('tasks', 2);
    }
}
