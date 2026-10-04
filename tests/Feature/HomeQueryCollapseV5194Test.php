<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\CoreContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HomeQueryCollapseV5194Test extends TestCase
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

    public function test_home_does_not_load_legacy_memberships_projection_for_collaborative_plan(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Shared Plan', true);
        $this->task($plan, 'Shared Task');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk();

        $queries = $this->queries();
        DB::disableQueryLog();

        $this->assertSame(
            0,
            $this->selectCount($queries, 'plan_members'),
            'Primary Home does not render collaborationPlans, so membership rows must not be loaded.',
        );
    }

    public function test_work_logs_reuse_task_models_when_core_tasks_are_already_loaded(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Work Log Plan', false);
        $task = $this->task($plan, 'Reusable Task');

        WorkLog::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'worked_on' => today(),
            'actual_minutes' => 25,
            'progress_delta_percent' => 10,
        ]);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $plans = app(CoreContextService::class)->plans(
            $request,
            ['tasks', 'work_logs'],
        );

        $queries = $this->queries();
        DB::disableQueryLog();

        $loadedPlan = $plans->firstOrFail();
        $loadedLog = $loadedPlan->workLogs->firstOrFail();

        $this->assertTrue($loadedLog->relationLoaded('task'));
        $this->assertSame($task->id, $loadedLog->task?->id);
        $this->assertSame(
            1,
            $this->selectCount($queries, 'tasks'),
            'WorkLog.task must reuse the already-loaded Plan tasks instead of issuing another Task SELECT.',
        );
        $this->assertSame(
            1,
            $this->selectCount($queries, 'work_logs'),
        );
    }

    public function test_work_logs_keep_task_eager_loading_when_tasks_were_not_requested(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($user, 'Fallback Plan', false);
        $task = $this->task($plan, 'Fallback Task');

        WorkLog::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'worked_on' => today(),
            'actual_minutes' => 10,
            'progress_delta_percent' => 5,
        ]);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn () => $user);

        $plans = app(CoreContextService::class)->plans(
            $request,
            ['work_logs'],
        );

        $loadedLog = $plans->firstOrFail()->workLogs->firstOrFail();

        $this->assertTrue($loadedLog->relationLoaded('task'));
        $this->assertSame($task->id, $loadedLog->task?->id);
    }

    public function test_v5194_source_contract_removes_dead_home_membership_projection(): void
    {
        $home = file_get_contents(app_path('Services/HomePageDataService.php'));
        $core = file_get_contents(app_path('Services/CoreContextService.php'));

        $this->assertStringNotContainsString("'memberships', 'activity_logs'", $home);
        $this->assertStringNotContainsString('$collaborationPlans =', $home);
        $this->assertStringContainsString(
            "'dashboard',",
            $home,
        );
        $this->assertStringContainsString(
            "'actionHome',",
            $home,
        );
        $this->assertStringContainsString(
            "'intelligencePresentation',",
            $home,
        );
        $this->assertStringContainsString('$tasksAlreadyLoaded', $core);
        $this->assertStringContainsString('$workLog->setRelation(', $core);
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

    private function plan(User $user, string $title, bool $collaborative): Plan
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
            'is_collaborative' => $collaborative,
        ]);
    }

    private function task(Plan $plan, string $title): Task
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
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
    }
}
