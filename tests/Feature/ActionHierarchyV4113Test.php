<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\ExecutionActionPolicyService;
use App\Services\PlanToolService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActionHierarchyV4113Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_execution_policy_prefers_specialized_tool_and_uses_timer_only_as_fallback(): void
    {
        $policy = app(ExecutionActionPolicyService::class);

        $specialized = $policy->primary([
            ['id' => 'timer', 'recommended' => false],
            ['id' => 'resources', 'recommended' => true],
            ['id' => 'study_activity', 'recommended' => true],
        ]);

        $this->assertSame('study_activity', $specialized['id']);
        $this->assertFalse($specialized['is_fallback']);

        $fallback = $policy->primary([
            ['id' => 'timer', 'recommended' => false],
            ['id' => 'resources', 'recommended' => false],
        ]);

        $this->assertSame('timer', $fallback['id']);
        $this->assertTrue($fallback['is_fallback']);
        $this->assertTrue($fallback['recommended']);
    }

    public function test_ap_home_shows_ai_practice_without_optional_timer_next_to_it(): void
    {
        [$user] = $this->scenario(
            '応用情報 科目A',
            '資格学習',
            'ネットワーク過去問演習',
            'DNSとMTUの問題を解いて理解を確認する',
        );

        $response = $this->actingAs($user)->get(route('home'))->assertOk();

        $response->assertSee('AI演習で進める');
        $response->assertDontSee('集中タイマー（任意）');
    }

    public function test_toeic_home_and_plan_use_recall_as_primary_action(): void
    {
        [$user, $plan] = $this->scenario(
            'TOEIC 800点',
            '資格学習',
            'TOEIC英単語を暗記する',
            '頻出語彙を単語帳で覚える',
        );

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('記憶学習で進める')
            ->assertDontSee('集中タイマー（任意）');

        $this->actingAs($user)
            ->get(route('plans.show', $plan))
            ->assertOk()
            ->assertSee('記憶学習で進める');
    }

    public function test_generic_task_uses_timer_as_primary_fallback(): void
    {
        [$user, $plan, $task] = $this->scenario(
            '卒業制作',
            'その他',
            '方向性を考える',
            '次の実装候補を整理する',
        );

        $tools = app(PlanToolService::class)->forTask($plan, $task, true, $user);
        $primary = app(ExecutionActionPolicyService::class)->primary($tools);

        $this->assertSame('timer', $primary['id']);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('集中タイマーで進める')
            ->assertDontSee('集中タイマー（任意）');
    }

    public function test_home_keeps_study_action_without_embedding_study_focus_surface(): void
    {
        [$user] = $this->scenario(
            '応用情報 科目A',
            '資格学習',
            'ネットワーク過去問演習',
            'DNSとMTUの問題を解いて理解を確認する',
        );

        $response = $this->actingAs($user)->get(route('home'))->assertOk();

        $response
            ->assertSee('AI演習で進める')
            ->assertDontSee('data-surface-id="study_focus"', false)
            ->assertDontSee('data-dashboard-panel=', false);
    }

    public function test_home_does_not_expand_following_task_list_into_action_surface(): void
    {
        [$user, $plan, $current] = $this->scenario(
            'AP対策',
            '資格学習',
            '現在Task',
            '問題演習',
        );

        Task::create([
            'plan_id' => $plan->id,
            'title' => '次のTask',
            'description' => '次に進める内容',
            'estimated_minutes' => 45,
            'remaining_minutes' => 45,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 2,
            'activation_cost' => 2,
            'sort_order' => 2,
        ]);

        $response = $this->actingAs($user)->get(route('home'))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->assertSame(0, $xpath->query('//*[@data-surface-id="task_list"]')->length);
        $this->assertStringContainsString($current->title, $response->getContent());
        $this->assertStringNotContainsString('このあと', $response->getContent());
    }

    public function test_task_tools_hide_timer_when_a_specialized_primary_exists(): void
    {
        [$user, $plan] = $this->scenario(
            '応用情報 科目A',
            '資格学習',
            'ネットワーク過去問演習',
            'DNSとMTUの問題を解いて理解を確認する',
        );

        $response = $this->actingAs($user)->get(route('plans.show', $plan))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $tools = $xpath->query('//*[@id="canovia-tools"]')->item(0);
        $this->assertNotNull($tools);
        $text = trim($tools->textContent);

        $this->assertStringContainsString('AI演習', $text);
        $this->assertStringNotContainsString('集中タイマー', $text);
    }

    private function scenario(
        string $planTitle,
        string $category,
        string $taskTitle,
        string $taskDescription,
    ): array {
        $user = User::factory()->create();

        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $planTitle,
            'description' => $planTitle.'の計画',
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::create([
            'plan_id' => $plan->id,
            'title' => $taskTitle,
            'description' => $taskDescription,
            'next_action_note' => '次の一歩を進める',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
