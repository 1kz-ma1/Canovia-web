<?php

namespace Tests\Feature;

use App\Models\DevelopmentOperationalProfile;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DevelopmentOperationalProfileV3FeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_owner_can_save_self_report_and_no_storage_does_not_require_db_restore(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '開発Plan');

        $this->actingAs($user)
            ->post(route('workspace.development.operational_profile.update', $plan), [
                'stage' => 'public_launch',
                'runtime' => 'web',
                'data_store' => 'none',
            ])
            ->assertRedirect(route('workspace.development.index', [
                'plan_id' => $plan->id, 'surface' => 'work', 'release_preflight' => 1,
            ]));

        $this->assertDatabaseHas('development_operational_profiles', [
            'plan_id' => $plan->id, 'stage' => 'public_launch',
            'runtime' => 'web', 'data_store' => 'none',
        ]);

        $this->actingAs($user)->get(route('workspace.development.index', [
            'plan_id' => $plan->id,
        ]))->assertOk()
          ->assertSee('data-development-prelaunch-preparation', false)
          ->assertSee('data-development-operational-profile-editor', false)
          ->assertSee('data-development-operational-check="backup"', false)
          ->assertSee('data-development-operational-check="restore"', false)
          ->assertSee('data-development-operational-status="not_applicable"', false)
          ->assertSee('data-development-operational-status="declared"', false)
          ->assertSee('自己申告', false);

        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseCount('plan_artifacts', 0);
    }

    public function test_updates_can_be_partial_without_erasing_other_fields(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'アプリの試作');

        $this->actingAs($user)->post(route('workspace.development.operational_profile.update', $plan), [
            'stage' => 'prototype',
        ])->assertRedirect();

        $this->actingAs($user)->post(route('workspace.development.operational_profile.update', $plan), [
            'data_store' => 'local_device',
        ])->assertRedirect();

        $this->assertDatabaseHas('development_operational_profiles', [
            'plan_id' => $plan->id, 'stage' => 'prototype',
            'runtime' => 'unknown', 'data_store' => 'local_device',
        ]);
        $this->actingAs($user)->get(route('workspace.development.index', [
            'plan_id' => $plan->id,
        ]))->assertOk()
          ->assertSee('data-development-prelaunch-entry', false)
          ->assertDontSee('data-development-operational-evidence', false)
          ->assertSee('data-development-operational-profile-editor', false);
    }

    public function test_bad_values_do_not_override_saved_profile_and_secrets_are_never_stored(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '開発');

        $this->actingAs($user)->post(route('workspace.development.operational_profile.update', $plan), [
            'stage' => 'preview',
            'db_password' => 'should-not-be-stored',
            'endpoint' => 'private.example.internal',
        ])->assertRedirect();

        $this->actingAs($user)->from(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->post(route('workspace.development.operational_profile.update', $plan), [
                'stage' => 'public_launch',
                'data_store' => 'postgres://secret',
            ])
            ->assertSessionHasErrors('data_store');

        $this->assertDatabaseHas('development_operational_profiles', [
            'plan_id' => $plan->id, 'stage' => 'preview', 'data_store' => 'unknown',
        ]);
        $record = DevelopmentOperationalProfile::query()->where('plan_id', $plan->id)->firstOrFail();
        $this->assertArrayNotHasKey('db_password', $record->getAttributes());
        $this->assertArrayNotHasKey('endpoint', $record->getAttributes());
    }

    public function test_other_owner_or_team_member_cannot_read_or_change_private_profile(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, '初回一般公開の準備');
        DevelopmentOperationalProfile::query()->create([
            'plan_id' => $plan->id, 'stage' => 'live',
            'runtime' => 'ios', 'data_store' => 'server_db',
        ]);

        $this->actingAs($other)->post(route('workspace.development.operational_profile.update', $plan), [
            'data_store' => 'none',
        ])->assertNotFound();
        $this->actingAs($other)->get(route('workspace.development.index', [
            'plan_id' => $plan->id,
        ]))->assertNotFound();

        $plan->update(['is_collaborative' => true]);
        PlanMember::query()->create([
            'plan_id' => $plan->id, 'user_id' => $other->id,
            'role' => 'viewer', 'joined_at' => now(),
        ]);

        $this->actingAs($other)->get(route('workspace.development.index', [
            'plan_id' => $plan->id,
        ]))->assertOk()
          ->assertDontSee('data-development-operational-profile-editor', false)
          ->assertDontSee('配信審査', false)
          ->assertSee('data-development-operational-status="unknown"', false);

        $this->actingAs($other)->post(route('workspace.development.operational_profile.update', $plan), [
            'stage' => 'prototype',
        ])->assertNotFound();

        $this->assertDatabaseHas('development_operational_profiles', [
            'plan_id' => $plan->id, 'stage' => 'live',
            'runtime' => 'ios', 'data_store' => 'server_db',
        ]);
    }

    public function test_non_development_plan_cannot_save_operational_profile(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'AP対策', '資格学習');

        $this->actingAs($user)->post(route('workspace.development.operational_profile.update', $plan), [
            'stage' => 'live',
        ])->assertNotFound();
        $this->assertDatabaseCount('development_operational_profiles', 0);
    }

    private function plan(User $user, string $title, string $category = '個人開発'): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title, 'description' => $title,
            'category' => $category,
            'priority' => 2, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addWeeks(3),
            'is_public' => false,
        ]);
    }
}
