<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicBetaPolishV16Test extends TestCase
{
    use RefreshDatabase;

    public function test_plan_create_uses_goal_discovery_and_manual_fallback_keeps_visual_preferences(): void
    {
        $this->get(route('plans.create'))
            ->assertOk()
            ->assertSee('どんな未来にしたい？')
            ->assertSee('応用情報技術者試験 合格')
            ->assertDontSee('応用情報処理技術者試験')
            ->assertSee('手動で細かくPlanを作る')
            ->assertDontSee('Personalize');

        $this->get(route('plans.create.manual'))
            ->assertOk()
            ->assertSee('まず、目標の名前だけ決めよう。')
            ->assertSee('AIで具体化')
            ->assertSee('計画を作ってAIへ進む')
            ->assertSee('>青<', false)
            ->assertSee('>緑<', false)
            ->assertSee('>紫<', false);
    }

    public function test_first_run_ui_contains_a_short_product_intro_before_the_spotlight_tutorial(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-onboarding-intro', false)
            ->assertSee('Canoviaは、次の一歩を決めやすくするアプリです')
            ->assertSee('いつものAIで相談')
            ->assertSee('新しい情報はInboxへ');
    }

    public function test_ai_import_accepts_explanatory_text_around_the_json_payload(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => Str::uuid()->toString(),
            'title' => '公開βテスト',
            'start_date' => today()->toDateString(),
            'deadline' => today()->addMonth()->toDateString(),
            'is_public' => false,
        ]);

        $payload = [
            'schema_version' => '2.0',
            'flow' => 'plan_generation',
            'target_plan' => ['id' => $plan->id, 'title' => $plan->title],
            'summary' => '初期計画',
            'operations' => [
                [
                    'type' => 'add_task',
                    'client_ref' => 'task_1',
                    'title' => '最初のタスク',
                    'description' => '完了条件',
                    'estimated_minutes' => 30,
                    'remaining_minutes' => 30,
                    'progress_percent' => 0,
                    'status' => 'todo',
                    'priority' => 1,
                    'activation_cost' => 1,
                ],
                [
                    'type' => 'reorder_tasks',
                    'items' => [['task_ref' => 'task_1']],
                ],
            ],
        ];

        $wrapped = "こちらが最終回答です。\n" . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n必要なら調整してください。";

        $this->actingAs($user)
            ->post(route('plans.ai_task_assistant.import', $plan), ['tasks_json' => $wrapped])
            ->assertRedirect(route('plans.show', $plan));

        $this->assertSame(1, Task::where('plan_id', $plan->id)->count());
        $this->assertSame('最初のタスク', Task::where('plan_id', $plan->id)->firstOrFail()->title);
    }

    public function test_static_welcome_has_a_recovery_path_when_startup_takes_too_long(): void
    {
        $html = file_get_contents(base_path('static-welcome/index.html'));
        $script = file_get_contents(base_path('static-welcome/app.js'));

        $this->assertStringContainsString('data-slow-notice', $html);
        $this->assertStringContainsString('data-retry', $html);
        $this->assertStringContainsString('宇宙船を起動しています', $html);
        $this->assertStringContainsString('slowAfterMs', $script);
        $this->assertStringContainsString('navigator.onLine', $script);
    }
}
