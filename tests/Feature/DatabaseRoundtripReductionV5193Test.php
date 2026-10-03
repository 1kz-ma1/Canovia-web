<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseRoundtripReductionV5193Test extends TestCase
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

    public function test_execution_batches_availability_relations_for_all_plans(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        foreach (range(1, 4) as $index) {
            $plan = $this->plan($user, 'Execution '.$index);
            $this->task($plan, 'Execution Task '.$index);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)
            ->get(route('navigation.index'))
            ->assertOk();

        $queries = $this->queries();
        DB::disableQueryLog();

        $this->assertSame(
            1,
            $this->selectCount($queries, 'plan_availability_rules'),
            'Execution must batch availabilityRules instead of loading them once per Plan.',
        );
        $this->assertSame(
            1,
            $this->selectCount($queries, 'plan_availability_overrides'),
            'Execution must batch availabilityOverrides instead of loading them once per Plan.',
        );
    }

    public function test_timeline_batches_availability_relations_for_all_plans(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        foreach (range(1, 4) as $index) {
            $plan = $this->plan($user, 'Timeline '.$index);
            $this->task($plan, 'Timeline Task '.$index);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)
            ->get(route('timeline.index'))
            ->assertOk();

        $queries = $this->queries();
        DB::disableQueryLog();

        $this->assertSame(
            1,
            $this->selectCount($queries, 'plan_availability_rules'),
            'Timeline must batch availabilityRules before progress projection.',
        );
        $this->assertSame(
            1,
            $this->selectCount($queries, 'plan_availability_overrides'),
            'Timeline must batch availabilityOverrides before progress projection.',
        );
    }

    public function test_mysql_persistent_connection_is_opt_in_and_disabled_by_default(): void
    {
        $source = file_get_contents(config_path('database.php'));
        $env = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString(
            "\\PDO::ATTR_PERSISTENT => filter_var(env('DB_PERSISTENT', false), FILTER_VALIDATE_BOOL)",
            $source,
        );
        $this->assertStringContainsString('DB_PERSISTENT=false', $env);

        $this->assertArrayNotHasKey(
            \PDO::ATTR_PERSISTENT,
            config('database.connections.mysql.options', []),
            'Local/test configuration must not enable persistent DB connections implicitly.',
        );
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

                return preg_match(
                    '/\\bfrom\\s+["]?'.preg_quote($table, '/').'["]?\\b/i',
                    $sql,
                ) === 1;
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
