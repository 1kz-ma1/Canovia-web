<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\FirstRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class FirstRunUxV4123Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_fresh_guest_must_pass_dedicated_welcome_before_core_navigation(): void
    {
        foreach ([
            route('home'),
            route('inbox.index'),
            route('roadmap.index'),
            route('timeline.index'),
            route('calendar.index'),
            route('plans.create'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('first_run.show'));
        }

        $this->get(route('first_run.show'))
            ->assertOk()
            ->assertSee('はじめまして')
            ->assertSee('Canoviaは、話しながら')
            ->assertSee('Canoviaと始める')
            ->assertSee('ログインする')
            ->assertDontSee('今はスキップ')
            ->assertDontSee('data-onboarding-intro-skip', false);
    }

    public function test_starting_first_run_unlocks_goal_discovery_and_persists_browser_gate(): void
    {
        $response = $this->post(route('first_run.start'));

        $response
            ->assertRedirect(route('plans.create'))
            ->assertCookie(FirstRunService::COOKIE, '1')
            ->assertSessionHas('canovia.first_run.passed', true)
            ->assertSessionMissing('canovia.first_run.required');

        $this->get(route('plans.create'))
            ->assertOk()
            ->assertSee('FIRST COMPANION', false);

        $this->assertNotNull($this->app['auth']->user()?->fresh()->first_run_completed_at);
    }

    public function test_existing_guest_plan_bypasses_first_run_gate(): void
    {
        $plan = Plan::create([
            'user_id' => null,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '既存Guest計画',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $this->withCookie('pace_keeper_owner_token_'.$plan->id, $plan->owner_token)
            ->get(route('home'))
            ->assertOk();
    }

    public function test_unfinished_new_account_returns_to_welcome_after_logout_and_login(): void
    {
        $this->post(route('auth.register'), [
            'name' => 'Interrupted New User',
            'email' => 'interrupted@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('first_run.show'));

        $user = User::query()->where('email', 'interrupted@example.com')->firstOrFail();
        $this->assertNull($user->first_run_completed_at);

        $this->post(route('auth.logout'))->assertRedirect(route('home'));

        $this->post(route('auth.login'), [
            'email' => $user->email,
            'password' => 'password123',
            'remember' => '1',
        ])->assertRedirect(route('home'));

        $this->get(route('home'))
            ->assertRedirect(route('first_run.show'));

        $this->assertNull($user->fresh()->first_run_completed_at);
    }

    public function test_existing_account_can_log_in_from_welcome_without_being_forced_back_to_first_run(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->get(route('auth.login.form'))->assertOk();

        $this->post(route('auth.login'), [
            'email' => $user->email,
            'password' => 'password123',
            'remember' => '1',
        ])->assertRedirect(route('home'));

        $this->get(route('home'))->assertOk();
    }

    public function test_new_account_is_required_to_pass_welcome_before_first_companion(): void
    {
        $this->post(route('auth.register'), [
            'name' => 'Brand New',
            'email' => 'brand-new@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertRedirect(route('first_run.show'))
            ->assertSessionHas('canovia.first_run.required', true);

        $this->get(route('plans.create'))
            ->assertRedirect(route('first_run.show'));

        $this->post(route('first_run.start'))
            ->assertRedirect(route('plans.create'));

        $this->get(route('plans.create'))->assertOk();
    }

    public function test_plan_primary_action_is_full_width_and_secondary_actions_are_two_column_mobile_grid(): void
    {
        [$user, $plan] = $this->scenario();

        $response = $this->actingAs($user)
            ->get(route('plans.show', $plan))
            ->assertOk();

        $response->assertSee('grid grid-cols-2 gap-2 md:flex md:flex-wrap', false);
        $response->assertSee('col-span-2 min-h-12 w-full justify-center', false);
        $response->assertSee('btn-secondary min-h-11 w-full justify-center', false);
    }

    public function test_today_card_exposes_plan_drill_down_without_overriding_inner_actions(): void
    {
        [$user, $plan] = $this->scenario();

        $response = $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();

        $response->assertSee('data-plan-card-url="'.route('plans.show', $plan).'"', false);
        $response->assertSee('href="'.route('plans.show', $plan).'" class="plan-identity-chip', false);

        $source = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("[data-plan-card-url]", $source);
        $this->assertStringContainsString(
            "a, button, form, input, select, textarea, label, summary, details",
            $source,
        );
    }

    public function test_legacy_intro_is_no_longer_dismissible_with_close_or_skip(): void
    {
        $partial = file_get_contents(resource_path('views/layouts/partials/onboarding.blade.php'));
        $source = file_get_contents(resource_path('js/app.js'));

        $this->assertStringNotContainsString('data-onboarding-intro-skip', $partial);
        $this->assertStringContainsString("introDialog?.addEventListener('cancel'", $source);
        $this->assertStringContainsString('event.preventDefault()', $source);
    }

    private function scenario(): array
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Canovia改善',
            'description' => 'Canoviaをもっと使いやすくする',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        Task::create([
            'plan_id' => $plan->id,
            'title' => '次の改善を実装する',
            'description' => 'UI導線を改善する',
            'next_action_note' => '実装を進める',
            'estimated_minutes' => 60,
            'remaining_minutes' => 45,
            'progress_percent' => 25,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan];
    }
}
