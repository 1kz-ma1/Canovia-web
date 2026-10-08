<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ActionFirstPlanCreateV5866Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array',
            'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_manual_plan_form_no_longer_requires_json_roadmap_before_action(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $this->actingAs($user)
            ->get(route('plans.create.manual'))
            ->assertOk()
            ->assertSee('目標を登録して今できることへ')
            ->assertSee('行動から始める')
            ->assertSee('活動分類（任意）')
            ->assertSee('最初からロードマップを完成させる必要はありません。')
            ->assertDontSee('AIへ相談文をコピーして')
            ->assertDontSee('JSONを戻してロードマップを完成')
            ->assertDontSee('計画を作ってAIへ進む');
    }

    public function test_recognized_category_routes_to_matching_workspace_without_forced_ai_task_assistant(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        foreach ([
            ['資格学習', 'workspace.study.index', '応用情報技術者試験 合格'],
            ['個人開発', 'workspace.development.index', 'Canoviaを開発する'],
        ] as [$category, $route, $title]) {
            $this->actingAs($user)
                ->post(route('plans.store'), [
                    'title' => $title,
                    'category' => $category,
                    'create_request_id' => (string) Str::uuid(),
                ])
                ->assertSessionHasNoErrors();

            $plan = Plan::query()->where('title', $title)->sole();
            $this->actingAs($user)
                ->get(route($route, ['plan_id' => $plan->id]))
                ->assertOk();
            $this->assertSame($category, $plan->category);
            $this->assertDatabaseCount('tasks', 0);
        }
    }

    public function test_generic_and_creative_categories_open_plan_without_assuming_development(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        foreach ([
            ['category' => null, 'title' => '暮らしを整える'],
            ['category' => '制作活動', 'title' => '学内作品展示'],
        ] as $payload) {
            $requestId = (string) Str::uuid();
            $post = [
                'title' => $payload['title'],
                'create_request_id' => $requestId,
            ];
            if ($payload['category'] !== null) {
                $post['category'] = $payload['category'];
            }

            $response = $this->actingAs($user)
                ->post(route('plans.store'), $post)
                ->assertSessionHasNoErrors();
            $plan = Plan::query()->where('title', $payload['title'])->sole();
            $response->assertRedirect(route('plans.show', $plan));
            $this->assertSame($payload['category'], $plan->category);

            $this->actingAs($user)
                ->post(route('plans.store'), $post)
                ->assertRedirect(route('plans.show', $plan));
            $this->assertSame(1, Plan::query()->where('title', $payload['title'])->count());
        }

        $this->assertDatabaseCount('plans', 2);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_explicit_incompatible_workspace_never_forces_wrong_domain(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $response = $this->actingAs($user)->post(route('plans.store'), [
            'title' => '共同作品の制作',
            'category' => '制作活動',
            'workspace_mode' => 'development',
            'create_request_id' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $plan = Plan::query()->sole();
        $response->assertRedirect(route('plans.show', $plan));
        $this->assertSame('制作活動', $plan->category);
        $this->assertDatabaseCount('intelligence_action_projections', 0);
    }
}
