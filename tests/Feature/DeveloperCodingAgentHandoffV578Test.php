<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\ExecutionRequestHandoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeveloperCodingAgentHandoffV578Test extends TestCase
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

    public function test_developer_home_exposes_explicit_coding_agent_handoff_confirmation(): void
    {
        [$user, $plan, $task] = $this->scenario();

        Http::fake();

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-development-coding-agent-handoff',
                false,
            )
            ->assertSee('Coding Agentへ引き継ぐ')
            ->assertSee('Coding Agent向けContextを準備')
            ->assertSee('Agent実行・GitHub write・merge・deployはまだ行わない')
            ->assertSee(
                route(
                    'plans.tasks.development_coding_agent_handoff.prepare',
                    [$plan, $task],
                ),
                false,
            );

        Http::assertNothingSent();
    }

    public function test_handoff_requires_explicit_user_confirmation(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->actingAs($user)
            ->from(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->post(route(
                'plans.tasks.development_coding_agent_handoff.prepare',
                [$plan, $task],
            ))
            ->assertRedirect()
            ->assertSessionHasErrors('confirmed');

        $this->assertNull(session(
            ExecutionRequestHandoffService::sessionKey(
                $plan,
                $task,
            ),
        ));
    }

    public function test_confirmed_brief_reuses_execution_orchestration_external_handoff_without_ai_or_provider_call(): void
    {
        [$user, $plan, $task] = $this->scenario();

        Http::fake();

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.development_coding_agent_handoff.prepare',
                [$plan, $task],
            ), [
                'confirmed' => '1',
                'available_minutes' => 45,
            ])
            ->assertRedirect(route(
                'plans.tasks.execution_orchestration.show',
                [$plan, $task],
            ))
            ->assertSessionHas(
                'success',
                '確認済みImplementation BriefからCoding Agent向けExecution Contextを準備しました。まだAgent実行やGitHub writeは行っていません。',
            );

        Http::assertNothingSent();

        $state = session(
            ExecutionRequestHandoffService::sessionKey(
                $plan,
                $task,
            ),
        );

        $this->assertIsArray($state);
        $this->assertSame('external', $state['actor_type']);
        $this->assertSame(45, $state['available_minutes']);
        $this->assertNull($state['packet']);
        $this->assertIsString($state['handoff_prompt']);
        $this->assertNotSame('', trim($state['handoff_prompt']));

        $request = $state['execution_request'];

        $this->assertIsArray($request);
        $this->assertSame(
            'development_implementation_brief',
            data_get($request, 'source.type'),
        );
        $this->assertSame(
            'confirmed',
            data_get($request, 'confirmation.state'),
        );
        $this->assertSame(
            'user',
            data_get($request, 'confirmation.confirmed_by'),
        );
        $this->assertSame('external', data_get($request, 'actor_type'));
        $this->assertSame(45, data_get($request, 'available_minutes'));
        $this->assertSame(
            $task->id,
            data_get($request, 'target_task.id'),
        );
        $this->assertNotSame(
            '',
            (string) data_get($request, 'source.brief_hash'),
        );
        $this->assertStringContainsString(
            '# Canovia ',
            (string) data_get($request, 'instruction'),
        );

        $this->assertSame(
            'prepared',
            data_get(
                $state,
                'development_agent_handoff.state',
            ),
        );
        $this->assertSame(
            'user',
            data_get(
                $state,
                'development_agent_handoff.confirmed_by',
            ),
        );

        $prompt = (string) $state['handoff_prompt'];

        $this->assertStringContainsString(
            '【担当ラベル】',
            $prompt,
        );
        $this->assertStringContainsString(
            'Coding Agent',
            $prompt,
        );
        $this->assertStringContainsString(
            '【CONFIRMED EXECUTION REQUEST】',
            $prompt,
        );
        $this->assertStringContainsString(
            $task->title,
            $prompt,
        );

        $task->refresh();

        $this->assertSame(35, $task->progress_percent);
        $this->assertSame('doing', $task->status);
        $this->assertSame(80, $task->remaining_minutes);

        $this->actingAs($user)
            ->get(route(
                'plans.tasks.execution_orchestration.show',
                [$plan, $task],
            ))
            ->assertOk()
            ->assertSee(
                'Developer Homeで確認したImplementation Briefを引き継いでいます',
            )
            ->assertSee('人が確認済み')
            ->assertSee('外部システム')
            ->assertSee('EXTERNAL AI HANDOFF');
    }

    public function test_cross_plan_task_cannot_be_handed_to_coding_agent(): void
    {
        [$user, $plan] = $this->scenario();

        $otherPlan = $this->plan(
            $user,
            'Other Development',
        );
        $otherTask = $this->task(
            $otherPlan,
            'Other Task',
        );

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.development_coding_agent_handoff.prepare',
                [$plan, $otherTask],
            ), [
                'confirmed' => '1',
            ])
            ->assertNotFound();
    }

    public function test_completed_task_is_not_handed_to_coding_agent(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $task->update([
            'status' => 'done',
            'progress_percent' => 100,
            'remaining_minutes' => 0,
        ]);

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.development_coding_agent_handoff.prepare',
                [$plan, $task],
            ), [
                'confirmed' => '1',
            ])
            ->assertRedirect(route(
                'workspace.development.index',
                ['plan_id' => $plan->id],
            ))
            ->assertSessionHasErrors('coding_agent_handoff');

        $this->assertNull(session(
            ExecutionRequestHandoffService::sessionKey(
                $plan,
                $task,
            ),
        ));
    }

    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = $this->plan(
            $user,
            'Canovia Development',
        );
        $task = $this->task(
            $plan,
            'V57.8 Opt-in Coding Agent Handoff',
        );

        return [$user, $plan, $task];
    }

    private function plan(User $user, string $title): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => 'Coding Agent handoff',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => null,
            'estimated_minutes' => 120,
            'remaining_minutes' => 80,
            'progress_percent' => 35,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
    }
}
