<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActionHomeDensityV5197Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'session.driver' => 'array',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_pending_plan_update_can_be_dismissed_without_deleting_history(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'AP対策', '資格学習');
        $task = $this->task($plan, '科目Aの弱点補強', 1);
        $actorToken = Str::random(64);

        $session = WorkSession::query()->create([
            'actor_token' => $actorToken,
            'browser_session_id' => 'browser-v5197',
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'status' => 'completed',
            'intended_minutes' => 30,
            'started_at' => now()->subMinutes(2),
            'ended_at' => now()->subMinute(),
            'actual_seconds' => 60,
            'paused_seconds' => 0,
            'source' => 'dashboard',
            'needs_plan_update' => true,
        ]);

        $log = WorkLog::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'work_session_id' => $session->id,
            'task_title_snapshot' => $task->title,
            'worked_on' => today(),
            'actual_minutes' => 1,
            'progress_delta_percent' => 0,
            'progress_before_percent' => 0,
            'progress_after_percent' => 0,
            'remaining_minutes_before' => 30,
            'remaining_minutes_after' => 30,
            'outcome' => '作業セッションを終了',
        ]);

        $this->actingAs($user)
            ->withSession(['pace_keeper.actor_token' => $actorToken])
            ->postJson(route('work_sessions.dismiss_plan_update', $session))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'work_session_id' => $session->id,
                'history_preserved' => true,
            ]);

        $session->refresh();

        $this->assertFalse($session->needs_plan_update);
        $this->assertNotEmpty(data_get($session->metadata, 'plan_update_dismissed_at'));
        $this->assertDatabaseHas('work_sessions', ['id' => $session->id]);
        $this->assertDatabaseHas('work_logs', ['id' => $log->id, 'actual_minutes' => 1]);
    }

    public function test_home_renders_compact_dismissible_attention_signal_with_clearer_delayed_cta(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan(
            $user,
            '遅れたPlan',
            'その他',
            today()->subDays(15),
            today()->addDays(5),
        );
        $task = $this->task($plan, '遅れたTask', 1);
        $actorToken = Str::random(64);

        WorkSession::query()->create([
            'actor_token' => $actorToken,
            'browser_session_id' => 'browser-v5197',
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'status' => 'completed',
            'started_at' => now()->subMinutes(2),
            'ended_at' => now()->subMinute(),
            'actual_seconds' => 60,
            'paused_seconds' => 0,
            'source' => 'dashboard',
            'needs_plan_update' => true,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['pace_keeper.actor_token' => $actorToken])
            ->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('data-action-home-signals', false)
            ->assertSee('canovia-action-home-signals-track', false)
            ->assertSee('data-action-home-dismiss-form', false)
            ->assertSee('この通知だけ閉じる（実績は残します）')
            ->assertSee('次のTaskを見る')
            ->assertDontSee('実行を見直す');
    }

    public function test_active_session_home_restores_horizontal_other_plan_browsing(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $activePlan = $this->plan($user, '現在のPlan', 'その他');
        $activeTask = $this->task($activePlan, '現在のTask', 1);
        $otherPlan = $this->plan($user, '次のPlan', 'その他');
        $otherTask = $this->task($otherPlan, '次のPlanで進めるTask', 1);
        $actorToken = Str::random(64);

        WorkSession::query()->create([
            'actor_token' => $actorToken,
            'browser_session_id' => 'browser-v5197',
            'plan_id' => $activePlan->id,
            'task_id' => $activeTask->id,
            'status' => 'active',
            'intended_minutes' => 30,
            'started_at' => now()->subMinute(),
            'paused_seconds' => 0,
            'source' => 'dashboard',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['pace_keeper.actor_token' => $actorToken])
            ->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('data-action-home-focus-deck', false)
            ->assertSee('data-action-home-focus-alternative', false)
            ->assertSee($activeTask->title)
            ->assertSee($otherTask->title)
            ->assertSee($otherPlan->title)
            ->assertSee('横にスワイプすると、他のPlanも確認できます。')
            ->assertSee(route('navigation.index', ['plan_id' => $otherPlan->id]), false);
    }

    public function test_frontend_contract_keeps_attention_compact_and_dismisses_in_place(): void
    {
        $css = file_get_contents(resource_path('css/action-home.css'));
        $js = file_get_contents(resource_path('js/app.js'));
        $view = file_get_contents(resource_path('views/dashboard/index.blade.php'));

        $this->assertStringContainsString('.canovia-action-home-signals-track', $css);
        $this->assertStringContainsString('scroll-snap-type: x mandatory;', $css);
        $this->assertStringContainsString('flex: 0 0 min(84%, 21rem);', $css);
        $this->assertStringContainsString('.pk-v18-focus-track', $css);

        $this->assertStringContainsString('[data-action-home-dismiss-form]', $js);
        $this->assertStringContainsString("card?.remove();", $js);
        $this->assertStringContainsString("if (section && remaining === 0) section.remove();", $js);
        $this->assertStringContainsString('[data-action-home-focus-deck], .pk-v18-active-session', $js);

        $this->assertStringContainsString('data-action-home-focus-deck', $view);
        $this->assertStringContainsString('data-action-home-signal-count', $view);
    }

    private function plan(
        User $user,
        string $title,
        string $category,
        mixed $startDate = null,
        mixed $deadline = null,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => $startDate ?? today(),
            'deadline' => $deadline ?? today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function task(Plan $plan, string $title, int $sortOrder): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'next_action_note' => $title.'を進める',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => $sortOrder,
        ]);
    }
}
