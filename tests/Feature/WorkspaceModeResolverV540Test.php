<?php

namespace Tests\Feature;

use App\Enums\WorkspaceMode;
use App\Enums\WorkspaceModeSource;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\WorkspaceModeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceModeResolverV540Test extends TestCase
{
    use RefreshDatabase;

    public function test_generic_plan_route_resolves_public_specialized_profiles(): void
    {
        $user = User::factory()->create();
        $study = $this->plan($user, 'AP対策', '資格学習');
        $development = $this->plan($user, 'Canovia開発', '個人開発');
        $career = $this->plan($user, '就職活動', '就活');

        $studyContext = app(WorkspaceModeResolver::class)->resolve(
            $this->request('plans.show', ['plan' => $study]),
        );
        $developmentContext = app(WorkspaceModeResolver::class)->resolve(
            $this->request('plans.show', ['plan' => $development]),
        );
        $careerContext = app(WorkspaceModeResolver::class)->resolve(
            $this->request('plans.show', ['plan' => $career]),
        );

        $this->assertSame(WorkspaceMode::Study, $studyContext->mode);
        $this->assertSame(
            WorkspaceModeSource::PlanProfile,
            $studyContext->source,
        );
        $this->assertSame('study', $studyContext->profileKey);
        $this->assertSame($study->id, $studyContext->planId);

        $this->assertSame(
            WorkspaceMode::Development,
            $developmentContext->mode,
        );
        $this->assertSame(
            WorkspaceModeSource::PlanProfile,
            $developmentContext->source,
        );
        $this->assertSame('development', $developmentContext->profileKey);

        $this->assertSame(
            WorkspaceMode::Career,
            $careerContext->mode,
        );
        $this->assertSame(
            WorkspaceModeSource::PlanProfile,
            $careerContext->source,
        );
        $this->assertSame('career', $careerContext->profileKey);
    }

    public function test_unpublished_profile_modes_fall_back_to_overview_without_reclassifying_plan(): void
    {
        $user = User::factory()->create();
        $creative = $this->plan($user, '卒業制作', '制作活動');

        $context = app(WorkspaceModeResolver::class)->resolve(
            $this->request('plans.show', ['plan' => $creative]),
        );

        $this->assertSame(WorkspaceMode::Overview, $context->mode);
        $this->assertSame(
            WorkspaceModeSource::PlanProfile,
            $context->source,
        );
        $this->assertSame('creative', $context->profileKey);
        $this->assertSame('制作活動', $creative->fresh()->category);
    }

    public function test_domain_routes_can_resolve_mode_without_a_plan_parameter(): void
    {
        $resolver = app(WorkspaceModeResolver::class);

        $study = $resolver->resolve(
            $this->request('plans.tasks.study_practice.show'),
        );
        $development = $resolver->resolve(
            $this->request('github_workflow.index'),
        );
        $career = $resolver->resolve(
            $this->request('plans.career.index'),
        );

        $this->assertSame(WorkspaceMode::Study, $study->mode);
        $this->assertSame(
            WorkspaceModeSource::RouteHint,
            $study->source,
        );

        $this->assertSame(
            WorkspaceMode::Development,
            $development->mode,
        );
        $this->assertSame(
            WorkspaceModeSource::RouteHint,
            $development->source,
        );

        $this->assertSame(WorkspaceMode::Career, $career->mode);
        $this->assertSame(
            WorkspaceModeSource::RouteHint,
            $career->source,
        );
    }

    public function test_route_task_and_work_session_can_supply_plan_context(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, '学習計画', '勉強');
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'ネットワーク演習',
            'description' => 'CIDR',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
        $session = WorkSession::query()->create([
            'actor_token' => Str::random(40),
            'browser_session_id' => Str::random(40),
            'client_session_id' => Str::random(40),
            'start_request_id' => (string) Str::uuid(),
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'status' => 'active',
            'intended_minutes' => 30,
            'started_at' => now(),
            'paused_seconds' => 0,
            'source' => 'test',
        ]);

        $resolver = app(WorkspaceModeResolver::class);

        $taskContext = $resolver->resolve(
            $this->request('tasks.edit', ['task' => $task]),
        );
        $sessionContext = $resolver->resolve(
            $this->request(
                'work_sessions.active',
                ['workSession' => $session],
            ),
        );

        $this->assertSame(WorkspaceMode::Study, $taskContext->mode);
        $this->assertSame(WorkspaceMode::Study, $sessionContext->mode);
        $this->assertSame($plan->id, $sessionContext->planId);
    }

    public function test_explicit_mode_has_precedence_but_is_not_persisted_in_v54_0(): void
    {
        $user = User::factory()->create();
        $study = $this->plan($user, 'AP', '資格');

        $context = app(WorkspaceModeResolver::class)->resolve(
            $this->request(
                'plans.study_scope.index',
                ['plan' => $study],
            ),
            WorkspaceMode::Development,
        );

        $this->assertSame(
            WorkspaceMode::Development,
            $context->mode,
        );
        $this->assertSame(
            WorkspaceModeSource::Explicit,
            $context->source,
        );
        $this->assertNull($context->planId);
        $this->assertNull($context->profileKey);
    }

    public function test_unknown_context_defaults_to_overview(): void
    {
        $context = app(WorkspaceModeResolver::class)->resolve(
            $this->request('feedback.index'),
        );

        $this->assertSame(WorkspaceMode::Overview, $context->mode);
        $this->assertSame(
            WorkspaceModeSource::Default,
            $context->source,
        );
    }

    private function request(
        string $name,
        array $parameters = [],
    ): Request {
        $route = new Route(['GET'], '/', fn () => null);
        $route->name($name);

        $request = Request::create('/', 'GET');
        $route->bind($request);

        foreach ($parameters as $key => $value) {
            $route->setParameter($key, $value);
        }

        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    private function plan(
        User $user,
        string $title,
        string $category,
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
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }
}
