<?php

namespace Tests\Feature;

use App\Models\DevelopmentOperationalProfile;
use App\Models\DevelopmentOperationalTarget;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DevelopmentOperationalConflictV56Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
        ]);
    }

    private function plan(User $owner): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'V5.6 operator conflict',
            'description' => 'Disposable fixture',
            'category' => '個人開発',
            'priority' => 2,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addWeeks(2),
            'is_public' => false,
        ]);
    }

    private function target(Plan $plan): DevelopmentOperationalTarget
    {
        DevelopmentOperationalProfile::query()->create([
            'plan_id' => $plan->id,
            'stage' => 'public_launch',
            'runtime' => 'web',
            'data_store' => 'server_db',
        ]);

        return DevelopmentOperationalTarget::query()->create([
            'plan_id' => $plan->id,
            'provider' => 'render',
            'environment' => 'production',
            'resource_ref' => 'srv-conflict987',
            'revision' => 2,
        ]);
    }

    private function routeFor(Plan $plan): string
    {
        return route('workspace.development.operational_target.update', $plan);
    }

    private function reloadUrl(Plan $plan): string
    {
        return route('workspace.development.index', [
            'plan_id' => $plan->id,
            'surface' => 'work',
            'release_preflight' => 1,
        ]);
    }

    public function test_old_service_form_shows_accessible_409_page_without_retrying_or_leaking_target(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);
        $target = $this->target($plan);
        $before = $target->getRawOriginal('updated_at');

        $this->actingAs($owner)->post($this->routeFor($plan), [
            'action' => 'confirm_service',
            'target_revision' => 1,
            'attest' => 'yes',
            'redirect_to' => 'https://attacker.example/collect',
        ])->assertStatus(409)
          ->assertHeader('Cache-Control', 'private, no-store')
          ->assertHeader('Referrer-Policy', 'no-referrer')
          ->assertSee('data-development-operational-stale-conflict', false)
          ->assertSee('role="alert"', false)
          ->assertSee('最新の対象環境を確認してください')
          ->assertSee('data-development-operational-stale-reload', false)
          ->assertSee('href="'.e($this->reloadUrl($plan)).'"', false)
          ->assertDontSee('srv-conflict987', false)
          ->assertDontSee('attacker.example', false)
          ->assertDontSee('確認しました', false);

        $after = $target->fresh();
        $this->assertNull($after->service_checked_at);
        $this->assertSame(2, $after->revision);
        $this->assertSame($before, $after->getRawOriginal('updated_at'));

        // The recovery control is GET only; it never sends a confirmation.
        $this->actingAs($owner)->get($this->reloadUrl($plan))->assertOk()
            ->assertSee('name="target_revision" value="2"', false);
        $this->assertNull($target->fresh()->service_checked_at);
    }

    public function test_changed_backup_and_restore_scope_produce_same_owner_only_409_recovery(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);
        $target = $this->target($plan);

        foreach (['backup', 'restore'] as $kind) {
            $this->actingAs($owner)->post($this->routeFor($plan), [
                'action' => 'confirm_'.$kind,
                'target_revision' => 1,
                'expected_data_store' => 'server_db',
                'attest' => 'yes',
            ])->assertStatus(409)
              ->assertSee('data-development-operational-stale-reload', false);
        }
        $this->assertNull($target->fresh()->backup_confirmed_at);
        $this->assertNull($target->fresh()->restore_confirmed_at);

        DevelopmentOperationalProfile::query()->where('plan_id', $plan->id)
            ->update(['data_store' => 'cloud_files']);

        $this->actingAs($owner)->post($this->routeFor($plan), [
            'action' => 'confirm_backup',
            'target_revision' => 2,
            'expected_data_store' => 'server_db',
            'attest' => 'yes',
        ])->assertStatus(409)
          ->assertSee('最新のDevelopment Workを開く');
        $this->assertNull($target->fresh()->backup_confirmed_at);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_json_clients_receive_bounded_409_and_unrelated_actor_sees_nothing(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);
        $target = $this->target($plan);

        $this->actingAs($owner)->postJson($this->routeFor($plan), [
            'action' => 'confirm_service',
            'target_revision' => 1,
            'attest' => 'yes',
            'api_key' => 'must-not-leak',
        ])->assertStatus(409)
          ->assertHeader('Cache-Control', 'private, no-store')
          ->assertJsonPath('code', 'development_operational_stale')
          ->assertJsonPath('reload_url', $this->reloadUrl($plan))
          ->assertDontSee('must-not-leak', false)
          ->assertDontSee('srv-conflict987', false);

        $plan->update(['is_collaborative' => true]);
        PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $other->id,
            'role' => PlanMember::ROLE_EDITOR,
            'joined_at' => now(),
        ]);

        $this->actingAs($other)->postJson($this->routeFor($plan), [
            'action' => 'confirm_service',
            'target_revision' => 1,
            'attest' => 'yes',
        ])->assertNotFound()
          ->assertDontSee('development_operational_stale', false)
          ->assertDontSee('srv-conflict987', false);
        $this->assertNull($target->fresh()->service_checked_at);
    }
}
