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

final class DevelopmentOperationalAttestationV4FeatureTest extends TestCase
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

    public function test_owner_can_record_target_and_independent_backup_restore_confirmations(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '初回公開するアプリ');
        $this->profile($plan, 'server_db');

        $route = route('workspace.development.operational_target.update', $plan);
        $this->actingAs($user)->post($route, [
            'action' => 'set_target',
            'provider' => 'render',
            'environment' => 'production',
            'resource_ref' => 'srv-nonsecret123',
        ])->assertRedirect();

        $this->actingAs($user)->post($route, [
            'action' => 'confirm_backup',
            'attest' => 'yes',
        ])->assertRedirect();

        $target = DevelopmentOperationalTarget::query()
            ->where('plan_id', $plan->id)->firstOrFail();
        $this->assertNotNull($target->backup_confirmed_at);
        $this->assertNull($target->restore_confirmed_at);

        $this->actingAs($user)->get(route('workspace.development.index', [
            'plan_id' => $plan->id,
        ]))->assertOk()
          ->assertSee('data-development-operational-attestations', false)
          ->assertSee('data-development-provider-connection-state="unavailable"', false)
          ->assertSee('外部サービスの自動照合：未接続')
          ->assertSee('data-development-operational-status="operator_confirmed"', false)
          ->assertSee('data-development-operational-check="restore"', false)
          ->assertSee('独立検証なし', false);

        $this->actingAs($user)->post($route, [
            'action' => 'confirm_restore', 'attest' => 'yes',
        ])->assertRedirect();

        $this->assertNotNull(DevelopmentOperationalTarget::query()
            ->where('plan_id', $plan->id)->firstOrFail()->restore_confirmed_at);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('plan_artifacts', 0);
    }

    public function test_rebinding_target_invalidates_both_confirmations_and_clear_is_independent(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '一般公開の準備');
        $this->profile($plan, 'server_db');
        $route = route('workspace.development.operational_target.update', $plan);

        $this->actingAs($user)->post($route, [
            'action' => 'set_target',
            'provider' => 'render', 'environment' => 'staging',
            'resource_ref' => 'srv-test12345',
        ])->assertRedirect();

        foreach (['backup', 'restore'] as $kind) {
            $this->actingAs($user)->post($route, [
                'action' => 'confirm_'.$kind, 'attest' => 'yes',
            ])->assertRedirect();
        }

        $this->actingAs($user)->post($route, [
            'action' => 'set_target',
            'provider' => 'render', 'environment' => 'production',
            'resource_ref' => 'srv-test12345',
        ])->assertRedirect();

        $target = DevelopmentOperationalTarget::query()
            ->where('plan_id', $plan->id)->firstOrFail();
        $this->assertSame(2, $target->revision);
        $this->assertSame(1, $target->backup_revision);
        $this->assertSame(1, $target->restore_revision);

        $this->actingAs($user)->get(route('workspace.development.index', [
            'plan_id' => $plan->id,
        ]))->assertOk()->assertSee('data-development-operational-status="stale"', false);

        $this->actingAs($user)->post($route, ['action' => 'clear_backup'])->assertRedirect();

        $record = DevelopmentOperationalTarget::query()
            ->where('plan_id', $plan->id)->firstOrFail();
        $this->assertNull($record->backup_confirmed_at);
        $this->assertNotNull($record->restore_confirmed_at);
    }

    public function test_invalid_or_missing_operator_confirmation_does_not_create_proof(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '公開準備');
        $route = route('workspace.development.operational_target.update', $plan);
        $this->actingAs($user)->from(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->post($route, [
                'action' => 'set_target',
                'provider' => 'render',
                'environment' => 'production',
                'resource_ref' => 'https://example.com/private?key=secret',
            ])->assertSessionHasErrors('resource_ref');
        $this->assertDatabaseCount('development_operational_targets', 0);

        $this->actingAs($user)->post($route, [
            'action' => 'set_target',
            'provider' => 'render', 'environment' => 'production',
            'resource_ref' => 'srv-safe12345',
            'password' => 'a-secret-which-must-not-be-stored',
        ])->assertRedirect();

        $this->actingAs($user)->post($route, [
            'action' => 'confirm_backup',
        ])->assertSessionHasErrors('attest');

        $this->actingAs($user)->post($route, [
            'action' => 'confirm_backup', 'attest' => 'yes',
        ])->assertStatus(422);

        $record = DevelopmentOperationalTarget::query()
            ->where('plan_id', $plan->id)->firstOrFail();
        $this->assertNull($record->backup_confirmed_at);
        $this->assertArrayNotHasKey('password', $record->getAttributes());
    }

    public function test_other_user_and_editor_cannot_update_or_see_private_target_details(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $editor = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '初回公開するアプリ');
        $this->profile($plan, 'server_db');
        $target = DevelopmentOperationalTarget::query()->create([
            'plan_id' => $plan->id,
            'provider' => 'render',
            'environment' => 'production',
            'resource_ref' => 'srv-private123',
            'revision' => 1,
        ]);
        $route = route('workspace.development.operational_target.update', $plan);

        $this->actingAs($editor)->post($route, [
            'action' => 'confirm_backup', 'attest' => 'yes',
        ])->assertNotFound();

        $plan->update(['is_collaborative' => true]);
        PlanMember::query()->create([
            'plan_id' => $plan->id, 'user_id' => $editor->id,
            'role' => PlanMember::ROLE_EDITOR, 'joined_at' => now(),
        ]);

        $this->actingAs($editor)->get(route('workspace.development.index', [
            'plan_id' => $plan->id,
        ]))->assertOk()
          ->assertDontSee('data-development-operational-attestations', false)
          ->assertDontSee('data-development-provider-connection-state', false)
          ->assertDontSee('srv-private123', false)
          ->assertDontSee('data-development-operational-status="operator_confirmed"', false);

        $this->actingAs($editor)->post($route, [
            'action' => 'set_target', 'provider' => 'other',
            'environment' => 'staging', 'resource_ref' => 'myservice123',
        ])->assertNotFound();
        $this->assertSame($target->resource_ref, $target->fresh()->resource_ref);
    }

    public function test_changed_data_store_turns_existing_confirmation_stale(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '一般公開の準備');
        $profile = $this->profile($plan, 'server_db');
        $route = route('workspace.development.operational_target.update', $plan);

        $this->actingAs($user)->post($route, [
            'action' => 'set_target',
            'provider' => 'render',
            'environment' => 'production',
            'resource_ref' => 'srv-example123',
        ])->assertRedirect();
        $this->actingAs($user)->post($route, [
            'action' => 'confirm_backup', 'attest' => 'yes',
        ])->assertRedirect();

        $profile->update(['data_store' => 'cloud_files']);

        $this->actingAs($user)->get(route('workspace.development.index', [
            'plan_id' => $plan->id,
        ]))->assertOk()
          ->assertSee('data-development-operational-status="stale"', false);
    }

    private function plan(User $user, string $title): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => '個人開発',
            'priority' => 2, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addWeeks(3),
            'is_public' => false,
        ]);
    }

    private function profile(Plan $plan, string $store): DevelopmentOperationalProfile
    {
        return DevelopmentOperationalProfile::query()->create([
            'plan_id' => $plan->id,
            'stage' => 'public_launch',
            'runtime' => 'web',
            'data_store' => $store,
        ]);
    }
}
