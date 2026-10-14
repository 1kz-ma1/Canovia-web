<?php

namespace Tests\Feature;

use App\Models\DevelopmentOperationalProfile;
use App\Models\DevelopmentOperationalTarget;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DevelopmentOperationalStorageEpochV57Test extends TestCase
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
            'title' => 'V5.7 storage epoch acceptance',
            'description' => 'Disposable fixture',
            'category' => '個人開発',
            'priority' => 2,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addWeeks(2),
            'is_public' => false,
        ]);
    }

    private function profileUrl(Plan $plan): string
    {
        return route('workspace.development.operational_profile.update', $plan);
    }

    private function targetUrl(Plan $plan): string
    {
        return route('workspace.development.operational_target.update', $plan);
    }

    private function workUrl(Plan $plan): string
    {
        return route('workspace.development.index', [
            'plan_id' => $plan->id, 'surface' => 'work', 'release_preflight' => 1,
        ]);
    }

    private function confirmPayload(string $kind, int $targetRevision, string $store, int $epoch): array
    {
        return [
            'action' => 'confirm_'.$kind,
            'attest' => 'yes',
            'target_revision' => $targetRevision,
            'expected_data_store' => $store,
            'expected_data_store_revision' => $epoch,
        ];
    }

    private function setupTarget(User $owner, Plan $plan): void
    {
        $this->actingAs($owner)->post($this->profileUrl($plan), [
            'stage' => 'public_launch', 'runtime' => 'web', 'data_store' => 'server_db',
        ])->assertRedirect();
        $this->actingAs($owner)->post($this->targetUrl($plan), [
            'action' => 'set_target', 'provider' => 'render',
            'environment' => 'staging', 'resource_ref' => 'srv-epoch98765',
        ])->assertRedirect();
    }

    public function test_storage_a_b_a_never_revives_old_backup_or_restore_or_old_forms(): void
    {
        // One test compresses many owner interactions; never remove actual
        // live throttle middleware or permit unauthenticated confirmations.
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);
        $this->setupTarget($owner, $plan);

        $profile = DevelopmentOperationalProfile::query()->where('plan_id', $plan->id)->firstOrFail();
        $this->assertSame(1, $profile->data_store_revision);

        foreach (['backup', 'restore'] as $kind) {
            $this->actingAs($owner)->post($this->targetUrl($plan),
                $this->confirmPayload($kind, 1, 'server_db', 1)
            )->assertRedirect();
        }
        $target = DevelopmentOperationalTarget::query()->where('plan_id', $plan->id)->firstOrFail();
        $this->assertSame(1, $target->backup_store_revision);
        $this->assertSame(1, $target->restore_store_revision);
        $this->actingAs($owner)->get($this->workUrl($plan))->assertOk()
            ->assertSee('data-development-operator-status="operator_confirmed"', false);

        $this->actingAs($owner)->post($this->profileUrl($plan), [
            'data_store' => 'cloud_files',
        ])->assertRedirect();
        $this->assertSame(2, $profile->fresh()->data_store_revision);

        $this->actingAs($owner)->post($this->profileUrl($plan), [
            'data_store' => 'server_db',
        ])->assertRedirect();
        $this->assertSame(3, $profile->fresh()->data_store_revision);
        $this->assertSame('server_db', $profile->fresh()->data_store);
        $this->assertSame(1, $target->fresh()->revision);

        $this->actingAs($owner)->get($this->workUrl($plan))->assertOk()
            ->assertSee('data-development-operator-status="stale"', false)
            ->assertSee('data-development-operator-next="service"', false)
            ->assertSee('name="expected_data_store_revision" value="3"', false);
        // The service identity remains unknown, not independently verified.
        foreach (['backup', 'restore'] as $kind) {
            $this->actingAs($owner)->post($this->targetUrl($plan),
                $this->confirmPayload($kind, 1, 'server_db', 1)
            )->assertStatus(409)
              ->assertSee('data-development-operational-stale-reload', false);
        }
        $target = $target->fresh();
        $this->assertSame(1, $target->backup_store_revision);
        $this->assertSame(1, $target->restore_store_revision);

        foreach (['backup', 'restore'] as $kind) {
            $this->actingAs($owner)->post($this->targetUrl($plan),
                $this->confirmPayload($kind, 1, 'server_db', 3)
            )->assertRedirect();
        }
        $target = $target->fresh();
        $this->assertSame(3, $target->backup_store_revision);
        $this->assertSame(3, $target->restore_store_revision);
        $this->actingAs($owner)->get($this->workUrl($plan))->assertOk()
            ->assertSee('data-development-operator-next="service"', false);
        $this->actingAs($owner)->post($this->targetUrl($plan), [
            'action' => 'clear_backup',
        ])->assertRedirect();
        $this->assertNull($target->fresh()->backup_store_revision);
        $this->assertNotNull($target->fresh()->restore_confirmed_at);
        $this->assertSame(3, $target->fresh()->restore_store_revision);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('plan_artifacts', 0);
    }

    public function test_noop_and_nonstorage_changes_do_not_advance_epoch_but_invalid_forms_fail_closed(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);
        $this->setupTarget($owner, $plan);

        foreach ([
            ['data_store' => 'server_db'],
            ['stage' => 'live', 'runtime' => 'ios'],
        ] as $patch) {
            $this->actingAs($owner)->post($this->profileUrl($plan), $patch)->assertRedirect();
        }
        $this->assertSame(1, DevelopmentOperationalProfile::query()
            ->where('plan_id', $plan->id)->firstOrFail()->data_store_revision);

        $this->actingAs($owner)->post($this->targetUrl($plan), [
            'action' => 'confirm_backup', 'attest' => 'yes',
            'target_revision' => 1, 'expected_data_store' => 'server_db',
        ])->assertSessionHasErrors('expected_data_store_revision');

        $this->actingAs($owner)->post($this->targetUrl($plan),
            $this->confirmPayload('backup', 1, 'server_db', 999)
        )->assertStatus(409);
        $target = DevelopmentOperationalTarget::query()
            ->where('plan_id', $plan->id)->firstOrFail();
        $this->assertNull($target->backup_confirmed_at);

        $this->actingAs($owner)->post($this->profileUrl($plan), [
            'data_store' => 'https://evil.example',
        ])->assertSessionHasErrors('data_store');
        $this->assertSame(1, DevelopmentOperationalProfile::query()
            ->where('plan_id', $plan->id)->firstOrFail()->data_store_revision);
    }

    public function test_pre_epoch_legacy_claims_require_reconfirmation_and_other_owner_stays_denied(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);
        $this->setupTarget($owner, $plan);
        $target = DevelopmentOperationalTarget::query()
            ->where('plan_id', $plan->id)->firstOrFail();
        $target->backup_confirmed_at = now();
        $target->backup_revision = 1;
        $target->backup_store = 'server_db';
        $target->save();

        $this->actingAs($owner)->get($this->workUrl($plan))->assertOk()
            ->assertSee('data-development-operator-status="stale"', false);
        $this->actingAs($other)->post($this->targetUrl($plan),
            $this->confirmPayload('backup', 1, 'server_db', 1)
        )->assertNotFound();
        $this->actingAs($other)->get($this->workUrl($plan))->assertNotFound();
        $this->assertNull($target->fresh()->backup_store_revision);
    }
}
