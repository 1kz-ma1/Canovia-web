<?php

namespace Tests\Feature;

use App\Models\DevelopmentAiSharingPreference;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\DevelopmentAiSharingPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class DevelopmentAiSharingPreferenceTest extends TestCase
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

    public function test_owner_prepares_and_updates_scoped_short_lived_preference_without_activating_any_connection(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);
        $task = $this->task($plan);
        Http::fake();

        $this->actingAs($owner)
            ->post(route('workspace.development.sharing_preference.store', $plan), [
                'scope' => 'overview', 'duration_days' => 7,
            ])
            ->assertRedirect(route('workspace.development.index', ['plan_id' => $plan->id, 'surface' => 'work']));

        $preference = DevelopmentAiSharingPreference::query()->firstOrFail();
        $this->assertSame($owner->id, $preference->user_id);
        $this->assertSame($plan->id, $preference->plan_id);
        $this->assertSame('chatgpt', $preference->provider_key);
        $this->assertSame('overview', $preference->scope);
        $this->assertSame('prepared', $preference->status);
        $this->assertTrue($preference->isPrepared());
        $this->assertNull($preference->revoked_at);
        $this->assertTrue($preference->expires_at->between(now()->addDays(7)->subMinutes(1), now()->addDays(7)->addMinutes(1)));
        $this->assertDatabaseHas('development_ai_sharing_preference_events', [
            'preference_id' => $preference->id, 'actor_user_id' => $owner->id,
            'event' => 'prepared', 'scope' => 'overview',
        ]);

        // Updating the same Plan does not multiply grants or leak Context.
        $this->actingAs($owner)
            ->post(route('workspace.development.sharing_preference.store', $plan), [
                'scope' => 'tasks', 'duration_days' => 1,
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('development_ai_sharing_preferences', 1);
        $this->assertDatabaseCount('development_ai_sharing_preference_events', 2);
        $this->assertDatabaseHas('development_ai_sharing_preferences', [
            'user_id' => $owner->id, 'plan_id' => $plan->id,
            'scope' => 'tasks', 'status' => 'prepared',
        ]);
        $this->assertDatabaseHas('development_ai_sharing_preference_events', [
            'preference_id' => $preference->id, 'actor_user_id' => $owner->id,
            'event' => 'updated', 'scope' => 'tasks',
        ]);

        Http::assertNothingSent();
        $this->assertDatabaseCount('provider_connections', 0);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id, 'description' => 'SECRET_TASK_DESCRIPTION',
            'progress_percent' => 36,
        ]);
    }

    public function test_revocation_is_idempotent_and_works_after_plan_becomes_collaborative_and_creative(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);

        $this->actingAs($owner)
            ->post(route('workspace.development.sharing_preference.store', $plan), [
                'scope' => 'tasks', 'duration_days' => 30,
            ])->assertRedirect();

        $plan->update(['is_collaborative' => true, 'category' => '制作活動']);

        $this->actingAs($owner)
            ->post(route('workspace.development.sharing_preference.store', $plan), [
                'scope' => 'overview', 'duration_days' => 7,
            ])->assertNotFound();

        $url = route('workspace.development.sharing_preference.destroy', $plan);
        $this->actingAs($owner)->delete($url)->assertRedirect();

        $preference = DevelopmentAiSharingPreference::query()->firstOrFail();
        $this->assertSame('revoked', $preference->status);
        $this->assertFalse($preference->isPrepared());
        $this->assertNotNull($preference->revoked_at);
        $this->assertDatabaseHas('development_ai_sharing_preference_events', [
            'preference_id' => $preference->id, 'event' => 'revoked',
        ]);

        $this->actingAs($owner)->delete($url)->assertRedirect();
        $this->assertDatabaseCount('development_ai_sharing_preference_events', 2);
        $this->assertDatabaseCount('development_ai_sharing_preferences', 1);
    }

    public function test_expiry_is_not_interpreted_as_connected_or_extended_automatically(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);

        $this->actingAs($owner)
            ->post(route('workspace.development.sharing_preference.store', $plan), [
                'scope' => 'overview', 'duration_days' => 1,
            ])->assertRedirect();

        $this->travel(2)->days();

        $preference = DevelopmentAiSharingPreference::query()->firstOrFail();
        $this->assertFalse($preference->isPrepared());
        $this->assertSame('prepared', $preference->status);
        $this->assertDatabaseCount('development_ai_sharing_preference_events', 1);
    }

    public function test_untrusted_actors_and_invalid_or_shared_plan_preparations_fail_closed(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $personal = $this->plan($owner);
        $team = $this->plan($owner, '個人開発', true);
        $creative = $this->plan($owner, '制作活動');
        $study = $this->plan($owner, '資格学習');

        $this->post(route('workspace.development.sharing_preference.store', $personal), [
            'scope' => 'overview', 'duration_days' => 7,
        ])->assertRedirect();

        $this->actingAs($outsider)
            ->post(route('workspace.development.sharing_preference.store', $personal), [
                'scope' => 'overview', 'duration_days' => 7,
            ])->assertNotFound();

        $this->actingAs($outsider)
            ->delete(route('workspace.development.sharing_preference.destroy', $personal))
            ->assertNotFound();

        foreach ([$team, $creative, $study] as $forbidden) {
            $this->actingAs($owner)
                ->post(route('workspace.development.sharing_preference.store', $forbidden), [
                    'scope' => 'overview', 'duration_days' => 7,
                ])->assertNotFound();
        }

        $this->assertDatabaseCount('development_ai_sharing_preferences', 0);
        $this->assertDatabaseCount('development_ai_sharing_preference_events', 0);
    }

    public function test_invalid_scope_and_lifetime_are_rejected_without_any_persistence(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner);
        $uri = route('workspace.development.sharing_preference.store', $plan);

        $this->actingAs($owner)->post($uri, [
            'scope' => 'all', 'duration_days' => 7,
        ])->assertSessionHasErrors('scope');
        $this->actingAs($owner)->post($uri, [
            'scope' => 'tasks', 'duration_days' => 365,
        ])->assertSessionHasErrors('duration_days');

        $this->assertDatabaseCount('development_ai_sharing_preferences', 0);
        $this->assertDatabaseCount('development_ai_sharing_preference_events', 0);
    }

    public function test_service_cannot_be_used_to_bypass_owner_and_personal_development_checks(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $plan = $this->plan($owner);
        $service = app(DevelopmentAiSharingPreferenceService::class);

        foreach ([
            [$outsider, $plan],
            [$owner, $this->plan($owner, '個人開発', true)],
            [$owner, $this->plan($owner, '資格学習')],
        ] as [$actor, $subject]) {
            try {
                $service->prepare($actor, $subject, 'overview', 7);
                $this->fail('Unauthorized preparation must fail closed.');
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseCount('development_ai_sharing_preferences', 0);
    }

    public function test_work_surface_shows_staged_not_connected_and_hides_controls_from_team(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);

        $this->actingAs($owner)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id, 'surface' => 'work']))
            ->assertOk()
            ->assertSee('data-development-chatgpt-sharing-preference', false)
            ->assertSee('未接続')
            ->assertSee('準備設定を保存（まだ接続しない）')
            ->assertDontSee('data-chatgpt-preference-revoke', false);

        $this->actingAs($owner)
            ->post(route('workspace.development.sharing_preference.store', $plan), [
                'scope' => 'tasks', 'duration_days' => 7,
            ])->assertRedirect();

        $this->actingAs($owner)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id, 'surface' => 'work']))
            ->assertOk()
            ->assertSee('準備保存済み（接続なし）')
            ->assertSee('準備設定を取り消す')
            ->assertSee('data-chatgpt-preference-revoke', false);

        $team = $this->plan($owner, '個人開発', true);
        $this->actingAs($owner)
            ->get(route('workspace.development.index', ['plan_id' => $team->id, 'surface' => 'work']))
            ->assertOk()
            ->assertDontSee('data-development-chatgpt-sharing-preference', false);
    }

    public function test_account_can_revoke_a_preparation_after_plan_ownership_transfers_without_leaking_new_owner_data(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $newOwner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);

        $this->actingAs($owner)
            ->post(route('workspace.development.sharing_preference.store', $plan), [
                'scope' => 'tasks', 'duration_days' => 7,
            ])->assertRedirect();

        $this->actingAs($owner)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertSee('data-chatgpt-sharing-account', false)
            ->assertSee('準備保存済み（未接続）')
            ->assertSee('この準備設定を取り消す');

        $plan->update([
            'user_id' => $newOwner->id,
            'title' => 'SECRET_NEW_OWNER_PLAN_NAME',
        ]);

        $this->actingAs($owner)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertSee('以前の開発Plan（現在の内容は表示しません）')
            ->assertDontSee('SECRET_NEW_OWNER_PLAN_NAME');

        $this->actingAs($newOwner)
            ->delete(route('workspace.development.sharing_preference.destroy', $plan), [
                'return_to' => 'account',
            ])->assertNotFound();

        $this->actingAs($owner)
            ->delete(route('workspace.development.sharing_preference.destroy', $plan), [
                'return_to' => 'account',
            ])
            ->assertRedirect(route('auth.account'));

        $this->assertDatabaseHas('development_ai_sharing_preferences', [
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'status' => 'revoked',
        ]);
        $this->assertDatabaseCount('development_ai_sharing_preference_events', 2);
        $this->actingAs($owner)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertDontSee('data-chatgpt-preference-account-row', false);
    }

    public function test_account_shows_only_original_users_preparations(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $outsider = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner);
        $this->actingAs($owner)
            ->post(route('workspace.development.sharing_preference.store', $plan), [
                'scope' => 'overview', 'duration_days' => 7,
            ])->assertRedirect();

        $this->actingAs($outsider)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertDontSee('data-chatgpt-preference-account-row', false)
            ->assertDontSee('この準備設定を取り消す');
    }

    private function plan(User $user, string $category = '個人開発', bool $collaborative = false): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Development',
            'description' => 'PRIVATE_PLAN_DESCRIPTION',
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addWeeks(3),
            'is_public' => false,
            'is_collaborative' => $collaborative,
        ]);
    }

    private function task(Plan $plan): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Task visible in workspace',
            'description' => 'SECRET_TASK_DESCRIPTION',
            'status' => 'doing',
            'priority' => 1,
            'progress_percent' => 36,
            'estimated_minutes' => 45,
        ]);
    }
}
