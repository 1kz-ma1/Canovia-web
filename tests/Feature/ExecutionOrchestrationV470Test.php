<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\ExecutionOrchestrationContextService;
use App\Services\TaskDependencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecutionOrchestrationV470Test extends TestCase
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

    public function test_blocked_task_shows_dependencies_and_external_handoff(): void
    {
        [$user, $plan, $dependency, $target] = $this->scenario();

        $this->actingAs($user)
            ->get(route('plans.tasks.execution_orchestration.show', [$plan, $target]))
            ->assertOk()
            ->assertSee('本作業はDependency待ちです')
            ->assertSee($dependency->title)
            ->assertSee('待つだけとは限りません');

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.prepare', [$plan, $target]), [
                'generation_mode' => 'external',
                'actor_type' => 'ai',
                'available_minutes' => 30,
            ])
            ->assertRedirect(route('plans.tasks.execution_orchestration.show', [$plan, $target]));

        $this->actingAs($user)
            ->get(route('plans.tasks.execution_orchestration.show', [$plan, $target]))
            ->assertSee('EXTERNAL AI HANDOFF')
            ->assertSee('Promptをコピー');
    }

    public function test_context_fingerprint_changes_when_dependency_completes(): void
    {
        [$user, $plan, $dependency, $target] = $this->scenario();

        $beforeRequest = Request::create('/');
        $beforeRequest->setUserResolver(fn () => $user);
        $before = app(ExecutionOrchestrationContextService::class)
            ->snapshot($beforeRequest, $plan, $target);

        $dependency->update([
            'status' => 'done',
            'progress_percent' => 100,
            'remaining_minutes' => 0,
        ]);

        $afterRequest = Request::create('/');
        $afterRequest->setUserResolver(fn () => $user);
        $after = app(ExecutionOrchestrationContextService::class)
            ->snapshot($afterRequest, $plan->fresh(), $target->fresh());

        $this->assertSame('blocked', $before['dependency_state']);
        $this->assertSame('ready', $after['dependency_state']);
        $this->assertNotSame($before['context_fingerprint'], $after['context_fingerprint']);
    }

    public function test_external_packet_is_normalized_to_current_target(): void
    {
        [$user, $plan, , $target] = $this->scenario();

        $json = json_encode([
            'schema_version' => '1.0',
            'flow' => 'execution_packet',
            'summary' => 'fixtureを先に作る',
            'execution_mode' => 'prepare',
            'current_situation' => '実データ待ち',
            'role' => 'Validation',
            'objective' => 'fixture基盤を準備',
            'reason' => 'confirmed contractだけで先行できる',
            'actions' => [[
                'title' => 'fixture testを作る',
                'details' => '未完成データを仮定しない',
                'estimated_minutes' => 30,
            ]],
            'inputs' => ['現行Contract'],
            'outputs' => ['Validation Tests'],
            'dependencies' => ['実データ待ち'],
            'assumptions' => [],
            'do_not_touch' => ['評価ロジック'],
            'completion_criteria' => ['tests green'],
            'confirmation_required' => [],
            'next_phase' => '実データE2E',
        ], JSON_UNESCAPED_UNICODE);

        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.import', [$plan, $target]), [
                'packet_json' => $json,
            ])
            ->assertRedirect(route('plans.tasks.execution_orchestration.show', [$plan, $target]));

        $this->actingAs($user)
            ->get(route('plans.tasks.execution_orchestration.show', [$plan, $target]))
            ->assertSee('fixtureを先に作る')
            ->assertSee('fixture testを作る')
            ->assertSee('tests green');
    }

    private function scenario(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'HINANEX',
            'description' => '複数担当で成果物を接続する',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $dependency = $this->task($plan, 'D / Data normalization', 'doing', 60, 1);
        $target = $this->task($plan, 'C / Validation', 'todo', 0, 2);
        app(TaskDependencyService::class)->sync($target, [$dependency->id]);

        return [$user, $plan, $dependency, $target];
    }

    private function task(Plan $plan, string $title, string $status, int $progress, int $sort): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title.' scope',
            'estimated_minutes' => 120,
            'remaining_minutes' => $status === 'done' ? 0 : 60,
            'progress_percent' => $progress,
            'status' => $status,
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => $sort,
        ]);
    }
}
