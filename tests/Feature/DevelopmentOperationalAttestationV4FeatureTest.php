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
            'target_revision' => 1, 'expected_data_store' => 'server_db', 'expected_data_store_revision' => 1,
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
          ->assertSee('data-development-render-operator-handoff', false)
          ->assertSee('data-development-render-target-id', false)
          ->assertSee('data-development-render-step="backup"', false)
          ->assertSee('data-development-render-step="restore"', false)
          ->assertSee('外部サービスの自動照合：未接続')
          ->assertSee('data-development-operational-status="operator_confirmed"', false)
          ->assertSee('data-development-operational-check="restore"', false)
          ->assertSee('独立検証なし', false);

        $this->actingAs($user)->post($route, [
            'action' => 'confirm_restore', 'attest' => 'yes',
            'target_revision' => 1, 'expected_data_store' => 'server_db', 'expected_data_store_revision' => 1,
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
                'target_revision' => 1, 'expected_data_store' => 'server_db', 'expected_data_store_revision' => 1,
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
            'target_revision' => 1, 'expected_data_store' => 'server_db', 'expected_data_store_revision' => 1,
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
            'target_revision' => 1, 'expected_data_store' => 'server_db', 'expected_data_store_revision' => 1,
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
          ->assertDontSee('data-development-render-operator-handoff', false)
          ->assertDontSee('data-development-operator-summary', false)
          ->assertDontSee('data-development-render-target-id', false)
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
            'target_revision' => 1, 'expected_data_store' => 'server_db', 'expected_data_store_revision' => 1,
        ])->assertRedirect();

        $profile->update(['data_store' => 'cloud_files']);

        $this->actingAs($user)->get(route('workspace.development.index', [
            'plan_id' => $plan->id,
        ]))->assertOk()
          ->assertSee('data-development-operational-status="stale"', false);
    }

    public function test_render_handoff_is_read_only_and_no_data_requires_no_database_recovery(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '公開予定の静的アプリ');
        $this->profile($plan, 'none');
        $target = DevelopmentOperationalTarget::query()->create([
            'plan_id' => $plan->id,
            'provider' => 'render',
            'environment' => 'staging',
            'resource_ref' => 'srv-static123',
            'revision' => 1,
        ]);
        $beforeUpdatedAt = $target->getRawOriginal('updated_at');
        $this->actingAs($owner)->get(route('workspace.development.index', [
            'plan_id' => $plan->id,
            'surface' => 'work',
        ]))->assertOk()
          ->assertSee('data-development-render-operator-handoff', false)
          ->assertSee('srv-static123', false)
          ->assertSee('href="https://dashboard.render.com/"', false)
          ->assertSee('rel="noopener noreferrer"', false)
          ->assertSee('data-development-render-step="service_identity"', false)
          ->assertSee('data-development-render-step="environment"', false)
          ->assertSee('data-development-render-step="deployment"', false)
          ->assertDontSee('data-development-render-step="backup"', false)
          ->assertDontSee('data-development-render-step="restore"', false)
          ->assertDontSee('name="api_key"', false);

        $after = $target->fresh();
        $this->assertSame('srv-static123', $after->resource_ref);
        $this->assertSame(1, $after->revision);
        $this->assertNull($after->backup_confirmed_at);
        $this->assertNull($after->restore_confirmed_at);
        $this->assertSame($beforeUpdatedAt, $after->getRawOriginal('updated_at'));
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_other_provider_does_not_show_render_handoff(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '公開予定');
        $this->profile($plan, 'server_db');
        DevelopmentOperationalTarget::query()->create([
            'plan_id' => $plan->id,
            'provider' => 'other',
            'environment' => 'production',
            'resource_ref' => 'myprovider123',
            'revision' => 1,
        ]);

        $this->actingAs($owner)->get(route('workspace.development.index', [
            'plan_id' => $plan->id, 'surface' => 'work',
        ]))->assertOk()
          ->assertDontSee('data-development-render-operator-handoff', false)
          ->assertDontSee('data-development-render-dashboard-link', false);
    }

    public function test_owner_can_confirm_and_revoke_revision_bound_manual_service_check_without_release_proof(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, 'サービス照合');
        $this->profile($plan, 'none');
        $target = DevelopmentOperationalTarget::query()->create([
            'plan_id' => $plan->id, 'provider' => 'render',
            'environment' => 'production', 'resource_ref' => 'srv-check1234',
            'revision' => 1,
        ]);
        $route = route('workspace.development.operational_target.update', $plan);
        $page = route('workspace.development.index', ['plan_id' => $plan->id, 'surface' => 'work']);

        $this->actingAs($owner)->get($page)->assertOk()
            ->assertSee('data-development-service-check-status="unknown"', false)
            ->assertSee('data-development-service-check-submit', false)
            ->assertSee('name="target_revision" value="1"', false);

        $this->actingAs($owner)->post($route, [
            'action' => 'confirm_service', 'attest' => 'yes',
        ])->assertSessionHasErrors('target_revision');
        $this->assertNull($target->fresh()->service_checked_at);

        $this->actingAs($owner)->post($route, [
            'action' => 'confirm_service', 'target_revision' => 1,
            'attest' => 'yes', 'origin' => 'authenticated_provider_adapter',
            'verified' => true, 'api_key' => 'DO_NOT_PERSIST',
        ])->assertRedirect();

        $record = $target->fresh();
        $this->assertNotNull($record->service_checked_at);
        $this->assertSame(1, $record->service_checked_revision);
        $this->assertSame('production', $record->service_checked_environment);
        $this->assertNull($record->backup_confirmed_at);
        $this->assertNull($record->restore_confirmed_at);
        $this->assertArrayNotHasKey('api_key', $record->getAttributes());

        $this->actingAs($owner)->get($page)->assertOk()
            ->assertSee('data-development-service-check-status="operator_confirmed"', false)
            ->assertSee('data-development-service-check-clear', false)
            ->assertSee('data-development-operational-status="declared"', false)
            ->assertSee('data-development-operational-check="deploy"', false);

        $this->actingAs($owner)->post($route, ['action' => 'clear_service'])->assertRedirect();
        $this->assertNull($target->fresh()->service_checked_at);
        $this->assertNull($target->fresh()->service_checked_revision);
        $this->assertNull($target->fresh()->service_checked_environment);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_changed_target_stales_service_check_and_old_form_cannot_reconfirm(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '環境変更');
        $this->profile($plan, 'server_db');
        $route = route('workspace.development.operational_target.update', $plan);
        $this->actingAs($owner)->post($route, [
            'action' => 'set_target', 'provider' => 'render',
            'environment' => 'staging', 'resource_ref' => 'srv-bounded12',
        ])->assertRedirect();

        $this->actingAs($owner)->post($route, [
            'action' => 'confirm_service', 'target_revision' => 1,
            'attest' => 'yes',
        ])->assertRedirect();

        $this->actingAs($owner)->post($route, [
            'action' => 'set_target', 'provider' => 'render',
            'environment' => 'production', 'resource_ref' => 'srv-bounded12',
        ])->assertRedirect();

        $target = DevelopmentOperationalTarget::query()->where('plan_id', $plan->id)->firstOrFail();
        $this->assertSame(2, $target->revision);
        $this->assertSame(1, $target->service_checked_revision);
        $this->assertSame('staging', $target->service_checked_environment);

        $page = route('workspace.development.index', ['plan_id' => $plan->id, 'surface' => 'work']);
        $this->actingAs($owner)->get($page)->assertOk()
            ->assertSee('data-development-service-check-status="stale"', false)
            ->assertSee('name="target_revision" value="2"', false);

        $this->actingAs($owner)->post($route, [
            'action' => 'confirm_service', 'target_revision' => 1, 'attest' => 'yes',
        ])->assertStatus(409);

        $this->assertSame(1, $target->fresh()->service_checked_revision);
        $this->actingAs($owner)->post($route, [
            'action' => 'confirm_service', 'target_revision' => 2, 'attest' => 'yes',
        ])->assertRedirect();
        $this->assertSame('production', $target->fresh()->service_checked_environment);
        $this->assertSame(2, $target->fresh()->service_checked_revision);
        $this->actingAs($owner)->get($page)->assertOk()
            ->assertSee('data-development-service-check-status="operator_confirmed"', false);
    }

    public function test_non_owner_cannot_write_service_check_and_other_provider_is_ineligible(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $editor = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '共同制作環境');
        $this->profile($plan, 'none');
        $target = DevelopmentOperationalTarget::query()->create([
            'plan_id' => $plan->id, 'provider' => 'render',
            'environment' => 'staging', 'resource_ref' => 'srv-secret123',
            'revision' => 1,
        ]);
        $route = route('workspace.development.operational_target.update', $plan);
        $plan->update(['is_collaborative' => true]);
        PlanMember::query()->create([
            'plan_id' => $plan->id, 'user_id' => $editor->id,
            'role' => PlanMember::ROLE_EDITOR, 'joined_at' => now(),
        ]);
        $this->actingAs($editor)->post($route, [
            'action' => 'confirm_service', 'target_revision' => 1, 'attest' => 'yes',
        ])->assertNotFound();
        $this->actingAs($editor)->post($route, [
            'action' => 'clear_service',
        ])->assertNotFound();
        $this->actingAs($editor)->get(route('workspace.development.index', [
            'plan_id' => $plan->id, 'surface' => 'work',
        ]))->assertOk()->assertDontSee('data-development-service-check-status', false);
        $this->assertNull($target->fresh()->service_checked_at);

        $target->update(['provider' => 'other', 'resource_ref' => 'other12345', 'revision' => 2]);
        $this->actingAs($owner)->post($route, [
            'action' => 'confirm_service', 'target_revision' => 2, 'attest' => 'yes',
        ])->assertStatus(409);
        $this->assertNull($target->fresh()->service_checked_at);
    }

    public function test_owner_sees_read_only_self_report_summary_and_ordered_next_actions(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '初回公開準備');
        $this->profile($plan, 'server_db');
        $target = DevelopmentOperationalTarget::query()->create([
            'plan_id' => $plan->id,
            'provider' => 'render',
            'environment' => 'staging',
            'resource_ref' => 'srv-summary123',
            'revision' => 1,
        ]);
        $route = route('workspace.development.operational_target.update', $plan);
        $page = route('workspace.development.index', ['plan_id' => $plan->id, 'surface' => 'work']);

        $this->actingAs($owner)->get($page)->assertOk()
            ->assertSee('data-development-operator-summary', false)
            ->assertSee('data-development-operator-check="service"', false)
            ->assertSee('data-development-operator-check="backup"', false)
            ->assertSee('data-development-operator-check="restore"', false)
            ->assertSee('data-development-operator-next="service"', false)
            ->assertSee('外部APIによる照合なし', false);
        $this->assertNull($target->fresh()->service_checked_at);

        $this->actingAs($owner)->post($route, [
            'action' => 'confirm_service', 'target_revision' => 1, 'attest' => 'yes',
        ])->assertRedirect();
        $this->actingAs($owner)->get($page)->assertOk()
            ->assertSee('data-development-operator-status="operator_confirmed"', false)
            ->assertSee('data-development-operator-next="backup"', false);

        $this->actingAs($owner)->post($route, [
            'action' => 'confirm_backup', 'attest' => 'yes',
            'target_revision' => 1, 'expected_data_store' => 'server_db', 'expected_data_store_revision' => 1,
        ])->assertRedirect();
        $this->actingAs($owner)->get($page)->assertOk()
            ->assertSee('data-development-operator-next="restore"', false);

        $this->actingAs($owner)->post($route, [
            'action' => 'confirm_restore', 'attest' => 'yes',
            'target_revision' => 1, 'expected_data_store' => 'server_db', 'expected_data_store_revision' => 1,
        ])->assertRedirect();
        $this->actingAs($owner)->get($page)->assertOk()
            ->assertSee('data-development-operator-next="release_review"', false)
            ->assertSee('既存の公開品質ゲートは別途必要', false)
            ->assertSee('data-development-operational-check="deploy"', false);

        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertSame('srv-summary123', $target->fresh()->resource_ref);
    }

    public function test_nonpersistent_store_has_not_applicable_summary_recovery_without_confirmation(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, '静的アプリ');
        $this->profile($plan, 'none');
        DevelopmentOperationalTarget::query()->create([
            'plan_id' => $plan->id,
            'provider' => 'render',
            'environment' => 'staging',
            'resource_ref' => 'srv-static7890',
            'revision' => 1,
        ]);

        $this->actingAs($owner)->get(route('workspace.development.index', [
            'plan_id' => $plan->id, 'surface' => 'work',
        ]))->assertOk()
          ->assertSee('data-development-operator-summary', false)
          ->assertSee('data-development-operator-status="not_applicable"', false)
          ->assertSee('data-development-operator-next="service"', false)
          ->assertDontSee('data-development-confirm-submit="backup"', false);
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
