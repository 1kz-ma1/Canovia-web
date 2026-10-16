<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InitialReleaseAudienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['release_levels.public_level' => 0,
            'canovia.super_admin_user_id' => null, 'canovia.admin_email' => null]);
    }

    public function test_hosted_environment_values_are_validated_before_numeric_conversion(): void
    {
        foreach (['1invalid' => 0, '1.5' => 0, '1e0' => 0, 'true' => 0,
            '(true)' => 0, '-1' => 0, '99' => 0, '0' => 0, '1' => 1,
            '2' => 2, '3' => 3, '4' => 0] as $raw => $expected) {
            $process = new Process([PHP_BINARY, '-r',
                'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; '
                .'$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
                .'echo app(App\\Services\\ReleaseLevelService::class)->publicLevel()->value;',
            ], base_path(), ['APP_ENV' => 'production',
                'CANOVIA_PUBLIC_RELEASE_LEVEL' => (string) $raw,
                'APP_CONFIG_CACHE' => storage_path('framework/testing/no-audience-config.php')]);
            $process->mustRun();
            $this->assertSame((string) $expected, trim($process->getOutput()), (string) $raw);
        }
    }

    public function test_l0_blocks_direct_specialized_workspaces_without_erasing_existing_data(): void
    {
        foreach (['study', 'development'] as $mode) {
            $user = User::factory()->create(['workspace_mode_preference' => $mode,
                'release_level_override' => 4, 'first_run_completed_at' => now()]);
            $plan = $this->plan($user, $mode);
            $this->actingAs($user);
            foreach (['index', 'top'] as $screen) {
                $this->get(route("workspace.{$mode}.{$screen}", ['plan_id' => $plan->id]))
                    ->assertRedirect(route('workspace.overview.index'));
            }
            $this->get(route('auth.account'))->assertOk();
            $this->get(route('plans.show', $plan))->assertOk();
            $this->assertDatabaseHas('plans', ['id' => $plan->id, 'user_id' => $user->id]);
            $this->assertSame($mode, $user->fresh()->workspace_mode_preference);
            $this->assertSame(4, $user->fresh()->release_level_override);
        }
        $this->post(route('workspace.development.operational_profile.update', $plan), [])
            ->assertNotFound();
    }

    public function test_l1_and_explicit_l1_tester_grant_restore_existing_workspaces(): void
    {
        foreach ([['public' => 1, 'grant' => null], ['public' => 0, 'grant' => 1]] as $audience) {
            config(['release_levels.public_level' => $audience['public']]);
            $user = User::factory()->create(['release_level_override' => $audience['grant'],
                'first_run_completed_at' => now()]);
            $this->actingAs($user);
            foreach (['study', 'development'] as $mode) {
                $plan = $this->plan($user, $mode);
                $this->get(route("workspace.{$mode}.index", ['plan_id' => $plan->id]))->assertOk();
                $this->get(route("workspace.{$mode}.top"))->assertOk();
            }
            $foreign = $this->plan(User::factory()->create(), 'study');
            $this->get(route('workspace.study.index', ['plan_id' => $foreign->id]))->assertNotFound();
        }
    }

    private function plan(User $user, string $mode): Plan
    {
        return Plan::query()->create(['user_id' => $user->id,
            'owner_token' => Str::random(64), 'public_slug' => (string) Str::uuid(),
            'title' => $mode === 'study' ? 'AP対策' : 'Web開発',
            'category' => $mode === 'study' ? '資格学習' : '個人開発',
            'priority' => 1, 'start_date' => today(), 'deadline' => today()->addMonth(),
            'is_public' => false, 'is_collaborative' => false]);
    }
}
