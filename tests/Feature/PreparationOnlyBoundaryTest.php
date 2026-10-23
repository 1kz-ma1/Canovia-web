<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\WorkspaceMode;
use App\Jobs\BootstrapGitHubDevelopmentActivity;
use App\Jobs\ProcessGitHubWebhookDelivery;
use App\Models\Plan;
use App\Models\User;
use App\Services\FeatureAccessService;
use App\Services\ReleaseLevelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PreparationOnlyBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['release_levels.preparation_only' => true, 'release_levels.public_level' => 1]);
        Http::preventStrayRequests();
    }

    private function plan(User $owner): Plan
    {
        return Plan::query()->create(['user_id' => $owner->id,
            'owner_token' => Str::random(64), 'public_slug' => (string) Str::uuid(),
            'title' => '旧AccountのPlan', 'category' => '資格学習', 'start_date' => today(),
            'is_public' => true, 'is_collaborative' => false]);
    }

    public function test_restrictive_profile_does_not_publish_by_itself_and_cannot_be_widened_by_admin(): void
    {
        $user = User::factory()->create(['release_level_override' => 4]);
        config(['canovia.super_admin_user_id' => $user->id]);
        $this->actingAs($user);
        foreach (FeatureKey::cases() as $feature) {
            config(["features.flags.{$feature->value}" => true]);
            $this->assertFalse(app(\App\Services\FeatureFlagService::class)->isEnabled($feature));
            $this->assertFalse(app(FeatureAccessService::class)->canUse($user, $feature));
        }
        foreach ([WorkspaceMode::Study, WorkspaceMode::Development, WorkspaceMode::Career] as $mode) {
            $this->assertFalse(app(ReleaseLevelService::class)->allowsWorkspace($mode, $user));
        }
        config(['release_levels.preparation_only' => 'invalid']);
        $this->assertTrue(app(ReleaseLevelService::class)->preparationOnly());
        config(['release_levels.preparation_only' => false]);
        $this->assertFalse(app(ReleaseLevelService::class)->preparationOnly());
    }

    public function test_anonymous_cannot_create_persistent_guest_plan_in_preparation_profile(): void
    {
        $this->get('/')->assertOk()->assertSee('アカウントを作成する');
        $this->post('/plans', ['title' => 'Guest保存'])->assertRedirect(route('auth.login.form'));
        $this->assertDatabaseCount('plans', 0);
    }

    public function test_new_and_legacy_owner_plan_remain_canonical_without_task_or_progress_mutation(): void
    {
        $user = User::factory()->create();
        $legacy = $this->plan($user);
        $task = \App\Models\Task::query()->create([
            'plan_id' => $legacy->id, 'title' => '以前からのTask', 'status' => 'doing',
            'estimated_minutes' => 30, 'remaining_minutes' => 25,
            'progress_percent' => 17, 'priority' => 1, 'activation_cost' => 1, 'sort_order' => 1,
        ]);
        $taskBefore = $task->fresh()->getRawOriginal();
        $before = $legacy->fresh()->getRawOriginal();
        $this->actingAs($user)->get('/')->assertOk()->assertSee($legacy->title)->assertDontSee('/work-sessions');
        $this->get(route('plans.show', $legacy))->assertOk()->assertSee('保存した準備Plan')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, $legacy->fresh()->getRawOriginal());
        $id = (string) Str::uuid();
        $this->get(route('plans.create.manual'))->assertOk()->assertSee('Planを保存する');
        $this->post(route('plans.store'), ['title' => '新しい準備', 'category' => '資格学習', 'create_request_id' => $id])->assertRedirect();
        $new = Plan::where('creation_request_id', $id)->sole();
        $this->assertSame($user->id, $new->user_id);
        $revision = $this->get(route('plans.edit', $new))->assertOk()->viewData('revision');
        $this->put(route('plans.update', $new), ['title' => '準備を編集', 'preparation_revision' => $revision])->assertRedirect();
        $this->assertSame('準備を編集', $new->fresh()->title);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame($taskBefore, $task->fresh()->getRawOriginal());
        $this->assertSame(0, $new->tasks()->count());
        $this->assertDatabaseCount('work_sessions', 0);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }

    public function test_stale_or_missing_edit_snapshot_cannot_overwrite_a_newer_save(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $this->actingAs($user);
        $revision = $this->get(route('plans.edit', $plan))->viewData('revision');
        $this->put(route('plans.update', $plan), ['title' => '先に保存した目標', 'preparation_revision' => $revision])->assertSessionHasNoErrors();
        $before = $plan->fresh()->getRawOriginal();
        $this->from(route('plans.edit', $plan))->put(route('plans.update', $plan), [
            'title' => '古い画面の入力', 'preparation_revision' => $revision,
        ])->assertSessionHasErrors('preparation_revision');
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
        $this->put(route('plans.update', $plan), ['title' => '比較値なし'])->assertSessionHasErrors('preparation_revision');
        $this->assertSame($before, $plan->fresh()->getRawOriginal());
    }

    public function test_other_users_and_public_slugs_do_not_grant_access(): void
    {
        $plan = $this->plan(User::factory()->create());
        $this->actingAs(User::factory()->create())->get(route('plans.show', $plan))->assertNotFound();
        $this->get(route('plans.edit', $plan))->assertForbidden();
        $this->put(route('plans.update', $plan), ['title' => '他者上書き'])->assertForbidden();
        $this->get(route('public_plans.show', $plan->public_slug))->assertNotFound();
        $this->assertSame('旧AccountのPlan', $plan->fresh()->title);
    }

    public function test_full_execution_and_api_routes_deny_before_controller_even_for_admin(): void
    {
        $user = User::factory()->create();
        config(['canovia.super_admin_user_id' => $user->id]);
        $this->actingAs($user);
        foreach (['/workspace/study', '/workspace/development', '/workspace/career', '/map', '/public-plans', '/admin'] as $url) {
            $this->get($url)->assertNotFound()->assertJsonPath('code', 'PREPARATION_SURFACE_CLOSED');
        }
        foreach (['/work-sessions', '/offline/work-sessions/sync', '/api/integrations/github/webhook', '/api/execution/activities', '/api/mcp', '/account/mcp/link/start', '/tasks/999/work-logs'] as $url) {
            $this->postJson($url)->assertNotFound();
        }
        $this->assertDatabaseCount('work_sessions', 0);
        $this->assertDatabaseCount('github_webhook_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_sharing_cannot_be_enabled_through_plan_forms(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        $this->actingAs($user)->post('/plans', ['title' => '共有', 'is_collaborative' => true])->assertForbidden();
        $this->put(route('plans.update', $plan), ['title' => '公開', 'is_public' => true])->assertForbidden();
        $this->assertSame('旧AccountのPlan', $plan->fresh()->title);
    }

    public function test_direct_old_job_handlers_and_generic_queue_dispatch_are_closed(): void
    {
        foreach ([new BootstrapGitHubDevelopmentActivity(999), new ProcessGitHubWebhookDelivery(999)] as $job) {
            try {
                app()->call([$job, 'handle']);
                $this->fail('A preparation job ran');
            } catch (\RuntimeException $error) {
                $this->assertSame('PREPARATION_EXECUTION_CLOSED', $error->getMessage());
            }
        }
        try {
            \Illuminate\Support\Facades\Bus::dispatch(new PreparationUnknownQueuedJob);
            $this->fail('An unknown queued handler could run');
        } catch (\RuntimeException $error) {
            $this->assertSame('PREPARATION_QUEUE_CLOSED', $error->getMessage());
        }
    }

    public function test_console_blocks_scheduler_provider_and_unapproved_migration_before_execution(): void
    {
        foreach (['schedule:run', 'queue:work', 'migrate', 'inspire'] as $command) {
            $process = new \Symfony\Component\Process\Process(
                [PHP_BINARY, 'artisan', $command, '--no-interaction'], base_path(),
                ['APP_ENV' => 'local', 'CANOVIA_PREPARATION_ONLY' => 'true',
                    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => ''],
            );
            $process->setTimeout(15);
            $process->run();
            $this->assertNotSame(0, $process->getExitCode());
            $this->assertStringContainsString('PREPARATION_CONSOLE_CLOSED', $process->getOutput().$process->getErrorOutput());
        }
    }

    public function test_low_level_diagnosis_is_not_implicitly_promoted_and_shutdown_does_not_delete_data(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user);
        config(['release_levels.public_level' => 0]);
        $this->actingAs($user)->post('/personalization', ['domains' => ['study']])->assertNotFound();
        config(['release_levels.public_level' => 1]);
        $this->post('/personalization', ['domains' => ['study'], 'study_goal' => '目標'])->assertRedirect(route('personalization.result'));
        $this->get('/personalization/result')->assertOk()->assertSee('現在地を保存しました')->assertDontSee('GitHub連携');
        $this->get('/account')->assertOk()->assertDontSee('/account/mcp');
        $this->assertSame($user->id, $plan->fresh()->user_id);
    }
}

final class PreparationUnknownQueuedJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    public function handle(): void
    {
        throw new \LogicException('UNAPPROVED_HANDLER_REACHED');
    }
}
