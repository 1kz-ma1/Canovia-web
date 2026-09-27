<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Models\Plan;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerformanceStep2V4119Test extends TestCase
{
    use RefreshDatabase;

    public function test_production_session_driver_is_not_forced_to_database(): void
    {
        $source = file_get_contents(config_path('session.php'));

        $this->assertStringContainsString("'driver' => env('SESSION_DRIVER', 'database')", $source);
        $this->assertStringNotContainsString("env('APP_ENV') === 'production' ? 'database'", $source);
    }

    public function test_service_worker_forces_new_activation_and_disables_navigation_preload_early(): void
    {
        $source = file_get_contents(public_path('sw.js'));
        $registrationSource = file_get_contents(resource_path('js/instant-start.mjs'));

        $this->assertStringContainsString("canovia-shell-v41-19-1", $source);
        $this->assertGreaterThanOrEqual(2, substr_count($source, 'navigationPreload.disable()'));
        $this->assertStringContainsString("self.addEventListener('install'", $source);
        $this->assertStringContainsString("self.addEventListener('activate'", $source);
        $this->assertStringContainsString("registration.navigationPreload?.disable?.()", $registrationSource);
    }

    public function test_home_batches_current_task_evidence_into_one_query(): void
    {
        $this->withoutVite();
        config()->set('session.driver', 'array');

        $user = User::factory()->create();

        foreach (range(1, 3) as $planIndex) {
            $plan = Plan::create([
                'user_id' => $user->id,
                'owner_token' => Str::random(64),
                'public_slug' => (string) Str::uuid(),
                'title' => 'Evidence plan '.$planIndex,
                'category' => 'その他',
                'priority' => $planIndex,
                'priority_mode' => 'manual',
                'start_date' => today(),
                'deadline' => today()->addMonth(),
                'is_public' => false,
            ]);

            $task = Task::create([
                'plan_id' => $plan->id,
                'title' => 'Current task '.$planIndex,
                'estimated_minutes' => 30,
                'remaining_minutes' => 25,
                'progress_percent' => 20,
                'status' => 'doing',
                'priority' => 1,
                'activation_cost' => 2,
                'sort_order' => 1,
            ]);

            foreach (range(1, 10) as $evidenceIndex) {
                TaskEvidence::create([
                    'plan_id' => $plan->id,
                    'task_id' => $task->id,
                    'user_id' => $user->id,
                    'actor_token' => str_repeat((string) $planIndex, 64),
                    'source' => EvidenceSource::Native,
                    'type' => 'focus_session_completed',
                    'confidence' => 1,
                    'occurred_at' => now()->subMinutes($evidenceIndex),
                    'metadata' => ['actual_minutes' => 5],
                ]);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($user)
            ->withSession(['pace_keeper.actor_token' => str_repeat('a', 64)])
            ->get('/');

        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();

        $evidenceQueries = $queries->filter(
            fn (array $query) => str_contains(strtolower($query['query']), 'task_evidences')
        );

        $this->assertCount(1, $evidenceQueries);
    }

    public function test_dockerfile_contains_redis_extension_and_writable_runtime_cache(): void
    {
        $source = file_get_contents(base_path('Dockerfile'));

        $this->assertStringContainsString('pecl install redis', $source);
        $this->assertStringContainsString('docker-php-ext-enable redis', $source);
        $this->assertStringContainsString('chown -R www-data:www-data storage bootstrap/cache', $source);
    }
}
