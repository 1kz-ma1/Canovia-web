<?php

namespace Tests\Feature;

use App\Models\DevelopmentOperationalTarget;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DevelopmentOperationalAcceptanceV55Test extends TestCase
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
            'title' => '運用準備受入テスト',
            'description' => 'Disposable acceptance case',
            'category' => '個人開発',
            'priority' => 2,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addWeeks(2),
            'is_public' => false,
        ]);
    }

    private function targetRoute(Plan $plan): string
    {
        return route('workspace.development.operational_target.update', $plan);
    }

    private function page(Plan $plan): string
    {
        return route('workspace.development.index', [
            'plan_id' => $plan->id,
            'surface' => 'work',
            'release_preflight' => 1,
        ]);
    }

    private function confirm(Plan $plan, string $kind, int $revision, string $store): array
    {
        return [
            'action' => 'confirm_'.$kind,
            'attest' => 'yes',
            'target_revision' => $revision,
            'expected_data_store' => $store,
        ];
    }

    public function test_owner_journey_records_three_distinct_self_reports_and_stales_on_rebind(): void
    {
        // This synthetic multi-tab journey compresses several minutes of
        // owner interactions into one test; retain live HTTP rate limits.
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);

        $this->actingAs($owner)->post(route('workspace.development.operational_profile.update', $plan), [
            'stage' => 'public_launch',
            'runtime' => 'web',
            'data_store' => 'server_db',
        ])->assertRedirect();
        $this->actingAs($owner)->post($this->targetRoute($plan), [
            'action' => 'set_target',
            'provider' => 'render',
            'environment' => 'staging',
            'resource_ref' => 'srv-disposable987',
        ])->assertRedirect();

        $this->actingAs($owner)->get($this->page($plan))->assertOk()
            ->assertSee('data-development-operator-next="service"', false)
            ->assertSee('name="expected_data_store" value="server_db"', false)
            ->assertSee('name="target_revision" value="1"', false)
            ->assertSee('外部サービスの自動照合：未接続');

        $this->actingAs($owner)->post($this->targetRoute($plan), [
            'action' => 'confirm_service',
            'target_revision' => 1,
            'attest' => 'yes',
        ])->assertRedirect();

        $this->actingAs($owner)->post($this->targetRoute($plan), $this->confirm($plan, 'backup', 1, 'server_db'))
            ->assertRedirect();
        $this->actingAs($owner)->post($this->targetRoute($plan), $this->confirm($plan, 'restore', 1, 'server_db'))
            ->assertRedirect();

        $this->actingAs($owner)->get($this->page($plan))->assertOk()
            ->assertSee('data-development-operator-next="release_review"', false)
            ->assertSee('data-development-operator-status="operator_confirmed"', false)
            ->assertSee('外部サービスの自動照合：未接続')
            ->assertSee('独立検証', false);

        $this->actingAs($owner)->post($this->targetRoute($plan), [
            'action' => 'set_target',
            'provider' => 'render',
            'environment' => 'production',
            'resource_ref' => 'srv-disposable987',
        ])->assertRedirect();

        $target = DevelopmentOperationalTarget::query()->where('plan_id', $plan->id)->firstOrFail();
        $this->assertSame(2, $target->revision);
        $this->assertSame(1, $target->backup_revision);
        $this->assertSame(1, $target->restore_revision);
        $this->assertSame(1, $target->service_checked_revision);

        $this->actingAs($owner)->get($this->page($plan))->assertOk()
            ->assertSee('data-development-operator-status="stale"', false)
            ->assertSee('data-development-operator-next="service"', false)
            ->assertSee('name="target_revision" value="2"', false);

        foreach (['backup', 'restore'] as $kind) {
            $this->actingAs($owner)->post(
                $this->targetRoute($plan),
                $this->confirm($plan, $kind, 1, 'server_db')
            )->assertStatus(409);
        }
        $this->assertSame(1, $target->fresh()->backup_revision);
        $this->assertSame(1, $target->fresh()->restore_revision);

        // New current-view submissions are still explicit human claims only.
        $this->actingAs($owner)->post($this->targetRoute($plan), [
            'action' => 'confirm_service',
            'target_revision' => 2,
            'attest' => 'yes',
        ])->assertRedirect();
        foreach (['backup', 'restore'] as $kind) {
            $this->actingAs($owner)->post(
                $this->targetRoute($plan),
                $this->confirm($plan, $kind, 2, 'server_db')
            )->assertRedirect();
        }

        $target = $target->fresh();
        $this->assertSame(2, $target->backup_revision);
        $this->assertSame(2, $target->restore_revision);
        $this->assertSame(2, $target->service_checked_revision);

        $this->actingAs($owner)->post($this->targetRoute($plan), [
            'action' => 'clear_backup',
        ])->assertRedirect();
        $this->assertNull($target->fresh()->backup_confirmed_at);
        $this->assertNotNull($target->fresh()->restore_confirmed_at);
        $this->assertNotNull($target->fresh()->service_checked_at);

        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('plan_artifacts', 0);
    }

    public function test_old_storage_selection_is_rejected_without_rebinding_the_attestation(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);
        $this->actingAs($owner)->post(route('workspace.development.operational_profile.update', $plan), [
            'stage' => 'public_launch', 'runtime' => 'web', 'data_store' => 'server_db',
        ])->assertRedirect();
        $this->actingAs($owner)->post($this->targetRoute($plan), [
            'action' => 'set_target', 'provider' => 'render',
            'environment' => 'staging', 'resource_ref' => 'srv-storage123',
        ])->assertRedirect();

        $this->actingAs($owner)->post(route('workspace.development.operational_profile.update', $plan), [
            'data_store' => 'cloud_files',
        ])->assertRedirect();

        foreach (['backup', 'restore'] as $kind) {
            $this->actingAs($owner)->post(
                $this->targetRoute($plan),
                $this->confirm($plan, $kind, 1, 'server_db')
            )->assertStatus(409);
        }

        $this->assertNull(DevelopmentOperationalTarget::query()
            ->where('plan_id', $plan->id)->firstOrFail()->backup_confirmed_at);
        $this->actingAs($owner)->get($this->page($plan))->assertOk()
            ->assertSee('name="expected_data_store" value="cloud_files"', false);
        $this->actingAs($owner)->post($this->targetRoute($plan),
            $this->confirm($plan, 'backup', 1, 'cloud_files')
        )->assertRedirect();
        $this->assertSame('cloud_files', DevelopmentOperationalTarget::query()
            ->where('plan_id', $plan->id)->firstOrFail()->backup_store);
    }

    public function test_missing_scope_and_non_owner_never_create_recovery_claims(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $editor = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);
        $this->actingAs($owner)->post(route('workspace.development.operational_profile.update', $plan), [
            'stage' => 'public_launch', 'runtime' => 'web', 'data_store' => 'server_db',
        ])->assertRedirect();
        $this->actingAs($owner)->post($this->targetRoute($plan), [
            'action' => 'set_target', 'provider' => 'render',
            'environment' => 'staging', 'resource_ref' => 'srv-private987',
        ])->assertRedirect();

        $this->actingAs($owner)->post($this->targetRoute($plan), [
            'action' => 'confirm_backup', 'attest' => 'yes',
        ])->assertSessionHasErrors('target_revision');
        $this->actingAs($owner)->post($this->targetRoute($plan), [
            'action' => 'confirm_restore', 'attest' => 'yes', 'target_revision' => 1,
        ])->assertSessionHasErrors('expected_data_store');

        $plan->update(['is_collaborative' => true]);
        PlanMember::query()->create([
            'plan_id' => $plan->id, 'user_id' => $editor->id,
            'role' => PlanMember::ROLE_EDITOR,
            'joined_at' => now(),
        ]);
        $this->actingAs($editor)->post(
            $this->targetRoute($plan),
            $this->confirm($plan, 'backup', 1, 'server_db')
        )->assertNotFound();
        $this->actingAs($editor)->get($this->page($plan))->assertOk()
            ->assertDontSee('srv-private987', false)
            ->assertDontSee('data-development-operator-summary', false)
            ->assertDontSee('name="expected_data_store"', false);

        $this->assertNull(DevelopmentOperationalTarget::query()
            ->where('plan_id', $plan->id)->firstOrFail()->backup_confirmed_at);
    }
}
