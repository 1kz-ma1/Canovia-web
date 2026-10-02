<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NavigationBottleneckRemovalV519Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('session.driver', 'array');
    }

    public function test_navigation_dependency_queries_do_not_scale_with_task_count(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Large Execution Plan',
            'description' => 'Query regression plan',
            'category' => 'その他',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);

        $tasks = collect();

        for ($index = 1; $index <= 48; $index++) {
            $tasks->push(Task::query()->create([
                'plan_id' => $plan->id,
                'title' => 'Task '.$index,
                'estimated_minutes' => 30,
                'remaining_minutes' => 30,
                'progress_percent' => 0,
                'status' => 'todo',
                'priority' => 1,
                'activation_cost' => 2,
                'sort_order' => $index,
            ]));
        }

        foreach ($tasks->skip(1)->values() as $offset => $task) {
            DB::table('task_dependencies')->insert([
                'task_id' => $task->id,
                'prerequisite_task_id' => $tasks[$offset]->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($user)->get(route('navigation.index'));

        $response->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $taskSelects = collect($queries)
            ->filter(function (array $query): bool {
                $sql = strtolower((string) ($query['query'] ?? ''));

                return str_starts_with(ltrim($sql), 'select')
                    && preg_match('/\\bfrom\\s+["]?tasks["]?\\b/', $sql) === 1;
            })
            ->values();

        $this->assertLessThanOrEqual(
            4,
            $taskSelects->count(),
            'Navigation must eager load canonical prerequisites instead of querying them once per Task.',
        );

        $duplicateShapes = $taskSelects
            ->map(fn (array $query) => preg_replace('/\\?/', ':value', strtolower((string) $query['query'])))
            ->countBy()
            ->filter(fn (int $count) => $count > 4);

        $this->assertTrue(
            $duplicateShapes->isEmpty(),
            'Navigation must not reintroduce Task dependency N+1 queries.',
        );
    }

    public function test_navigation_eager_loads_legacy_and_canonical_dependencies(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/NavigationController.php'));

        $this->assertStringContainsString(
            "->with(['prerequisite', 'prerequisites'])",
            $source,
        );
    }
}
