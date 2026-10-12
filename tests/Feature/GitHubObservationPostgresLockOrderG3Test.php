<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\DevelopmentActivityObservation;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\GitHubDevelopmentObservationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

/**
 * Disposable PostgreSQL only: independent connections exercise the real
 * Observation.persist() transaction without GitHub network requests.
 */
final class GitHubObservationPostgresLockOrderG3Test extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This G3 lock-order acceptance requires PostgreSQL.');
        }

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_plan_locked_by_competing_worker_blocks_observation_before_it_locks_root(): void
    {
        [$plan, $task, $root] = $this->scenario();
        $selectedRoot = $root->fresh()->load('plan.user');
        $rival = $this->rivalConnection();
        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'for update')) {
                $locks[] = $sql;
            }
        });

        $rival->beginTransaction();
        try {
            $statement = $rival->prepare('SELECT id FROM plans WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $plan->id]);
            $statement->fetchAll();

            // No root lock may be acquired before Observation requests the
            // Plan row, otherwise Plan -> Root writers can deadlock with it.
            DB::statement("SET lock_timeout = '250ms'");
            try {
                $this->persist($selectedRoot);
                $this->fail('An Observation cannot bypass a locked Plan.');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', (string) ($exception->errorInfo[0] ?? ''));
            } finally {
                DB::statement("SET lock_timeout = '0'");
            }

            $this->assertFalse(collect($locks)->contains(
                fn (string $sql) => str_contains($sql, 'plan_artifacts')
            ), 'Observation must not acquire the Repository before waiting on Plan.');

            // The other worker can still revoke the root in its own tx.
            $metadata = (array) $root->metadata;
            $metadata['github_app_connection']['status'] = 'revoked';
            $update = $rival->prepare(
                'UPDATE plan_artifacts SET metadata = :metadata WHERE id = :id'
            );
            $update->execute([
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
                'id' => $root->id,
            ]);
            $rival->commit();
        } finally {
            if ($rival->inTransaction()) {
                $rival->rollBack();
            }
        }

        $this->assertNull($this->persist($selectedRoot));
        $this->assertDatabaseCount('development_activity_observations', 0);
        $this->assertSame('doing', $task->fresh()->status);

        // Reconnection with the SAME installation does not authorize a
        // provider response selected in a previous source epoch.
        $live = $root->fresh();
        $metadata = (array) $live->metadata;
        $metadata['github_app_connection']['status'] = 'connected';
        $metadata['github_app_connection']['source_epoch'] = (string) Str::uuid();
        $live->update(['metadata' => $metadata]);
        $this->assertNull($this->persist($selectedRoot));
        $this->assertDatabaseCount('development_activity_observations', 0);

        // An independently re-fetched current observation is still accepted,
        // and the healthy transaction must lock Plan BEFORE Repository.
        $locks = [];
        $fresh = $live->fresh()->load('plan.user');
        $observation = $this->persist($fresh);
        $this->assertInstanceOf(DevelopmentActivityObservation::class, $observation);
        $this->assertSame(
            data_get($fresh->metadata, 'github_app_connection.source_epoch'),
            $observation->source_epoch,
        );
        $planLock = collect($locks)->search(
            fn (string $sql) => str_contains($sql, 'from "plans"') && str_contains($sql, 'for update')
        );
        $rootLock = collect($locks)->search(
            fn (string $sql) => str_contains($sql, 'from "plan_artifacts"') && str_contains($sql, 'for update')
        );
        $this->assertNotFalse($planLock);
        $this->assertNotFalse($rootLock);
        $this->assertLessThan($rootLock, $planLock);
        $this->assertDatabaseCount('development_activity_observations', 1);
        $this->assertDatabaseCount('task_evidences', 0);
        $this->assertSame('doing', $task->fresh()->status);
    }

    private function persist(PlanArtifact $root): ?DevelopmentActivityObservation
    {
        $method = new \ReflectionMethod(GitHubDevelopmentObservationService::class, 'persist');

        return $method->invoke(
            app(GitHubDevelopmentObservationService::class),
            $root,
            '1kz-ma1/Canovia-web',
            [
                'kind' => 'issue',
                'identity' => '164',
                'provider_number' => 164,
                'state' => 'closed',
                'source_updated_at' => '2026-10-13T08:00:00Z',
                'occurred_at' => '2026-10-13T08:00:00Z',
            ],
            777,
        );
    }

    private function rivalConnection(): PDO
    {
        $config = config('database.connections.pgsql');

        return new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s',
                (string) ($config['host'] ?? '127.0.0.1'),
                (int) ($config['port'] ?? 5432),
                (string) ($config['database'] ?? ''),
            ),
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function scenario(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::AllAccess,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
        ]);
        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'G3 observation lock order',
            'description' => 'No cross-worker lock inversion',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'GitHub fact is not a Task completion',
            'description' => 'History only',
            'status' => 'doing',
            'progress_percent' => 20,
            'estimated_minutes' => 60,
            'remaining_minutes' => 40,
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
        $root = PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'Canovia-web',
            'url' => 'https://github.com/1kz-ma1/Canovia-web',
            'metadata' => ['github_app_connection' => [
                'status' => 'connected',
                'installation_id' => 777,
                'source_epoch' => '098d7de9-5b6b-430e-83c9-c98b27ebfcf6',
            ]],
        ]);

        return [$plan, $task, $root];
    }
}
