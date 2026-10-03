<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\ContinuityService;
use App\Services\CoreContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModelReuseV5195Test extends TestCase
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

    public function test_core_context_hydrates_dependencies_from_loaded_task_models(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Dependency Plan');

        $root = $this->task($plan, 'Root', 1);
        $child = $this->task($plan, 'Child', 2, $root->id);
        $final = $this->task($plan, 'Final', 3);

        DB::table('task_dependencies')->insert([
            [
                'task_id' => $child->id,
                'prerequisite_task_id' => $root->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'task_id' => $final->id,
                'prerequisite_task_id' => $root->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'task_id' => $final->id,
                'prerequisite_task_id' => $child->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $plans = app(CoreContextService::class)->plans(
            $request,
            ['tasks', 'task_dependencies'],
        );

        $queries = $this->queries();
        DB::disableQueryLog();

        $loadedPlan = $plans->firstOrFail();
        $tasks = $loadedPlan->tasks->keyBy('id');
        $loadedRoot = $tasks->get($root->id);
        $loadedChild = $tasks->get($child->id);
        $loadedFinal = $tasks->get($final->id);

        $this->assertSame(1, $this->selectCount($queries, 'tasks'));
        $this->assertSame(1, $this->selectCount($queries, 'task_dependencies'));

        $this->assertTrue($loadedChild->relationLoaded('prerequisite'));
        $this->assertTrue($loadedChild->relationLoaded('prerequisites'));
        $this->assertTrue($loadedFinal->relationLoaded('prerequisites'));

        $this->assertTrue($loadedChild->prerequisite === $loadedRoot);
        $this->assertTrue($loadedChild->prerequisites->first() === $loadedRoot);
        $this->assertSame(
            [$root->id, $child->id],
            $loadedFinal->prerequisites->pluck('id')->sort()->values()->all(),
        );
        $this->assertSame(
            [$root->id, $child->id],
            $loadedFinal->dependencyIds(),
        );
    }

    public function test_home_continuity_reuses_loaded_plan_and_task_relations(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Continuity Plan');
        $task = $this->task($plan, 'Continue Here', 1);

        $loadedPlan = $plan->fresh();
        $loadedPlan->load('tasks');

        $actorToken = Str::random(64);
        $session = WorkSession::query()->create([
            'actor_token' => $actorToken,
            'browser_session_id' => 'browser-v5195',
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'status' => 'active',
            'intended_minutes' => 30,
            'started_at' => now()->subMinute(),
            'source' => 'dashboard',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $context = app(ContinuityService::class)->homeContext(
            collect([$loadedPlan]),
            $actorToken,
        );

        $queries = $this->queries();
        DB::disableQueryLog();

        $active = $context['active_work_session'];

        $this->assertSame($session->id, $active?->id);
        $this->assertTrue($active?->relationLoaded('plan') ?? false);
        $this->assertTrue($active?->relationLoaded('task') ?? false);
        $this->assertTrue($active?->plan === $loadedPlan);
        $this->assertTrue($active?->task === $loadedPlan->tasks->firstWhere('id', $task->id));

        $this->assertSame(
            0,
            $this->selectCount($queries, 'plans'),
            'Home continuity must reuse the Plan models already loaded by CoreContext.',
        );
        $this->assertSame(
            0,
            $this->selectCount($queries, 'tasks'),
            'Home continuity must reuse the Task models already loaded by CoreContext.',
        );
        $this->assertSame(
            2,
            $this->selectCount($queries, 'work_sessions'),
            'V51.9.5 only collapses relation hydration; the two distinct WorkSession scopes remain unchanged.',
        );
    }

    public function test_v5195_source_contract_uses_pivot_and_existing_models(): void
    {
        $core = file_get_contents(app_path('Services/CoreContextService.php'));
        $continuity = file_get_contents(app_path('Services/ContinuityService.php'));

        $this->assertStringContainsString("DB::table('task_dependencies')", $core);
        $this->assertStringContainsString('$task->setRelation(\'prerequisite\'', $core);
        $this->assertStringContainsString("'prerequisites'", $core);

        $this->assertStringContainsString(
            '$plansById = $plans->keyBy(fn (Plan $plan) => (int) $plan->id)',
            $continuity,
        );
        $this->assertStringContainsString('$session->setRelation(\'plan\', $plan)', $continuity);
        $this->assertStringContainsString('$session->setRelation(', $continuity);
    }

    /**
     * @return array<int,string>
     */
    private function queries(): array
    {
        return collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn ($sql) => mb_strtolower((string) $sql))
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $queries
     */
    private function selectCount(array $queries, string $table): int
    {
        return collect($queries)
            ->filter(function (string $sql) use ($table): bool {
                if (! str_starts_with(ltrim($sql), 'select')) {
                    return false;
                }

                if (! preg_match('/\\bfrom\\s+["]?([a-z0-9_]+)["]?/i', $sql, $matches)) {
                    return false;
                }

                return strtolower((string) ($matches[1] ?? '')) === strtolower($table);
            })
            ->count();
    }

    private function plan(User $user, string $title): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => 'その他',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }

    private function task(Plan $plan, string $title, int $sortOrder, ?int $dependsOn = null): Task
    {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'depends_on_task_id' => $dependsOn,
            'title' => $title,
            'description' => $title,
            'next_action_note' => $title.'を進める',
            'estimated_minutes' => 30,
            'remaining_minutes' => 30,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => $sortOrder,
        ]);
    }
}
