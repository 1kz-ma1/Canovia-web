<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\CompanionDerivedEvidenceSourceGuard;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Uses two genuinely independent PostgreSQL sessions. No Aiven/Render DB.
 * Source row-lock ownership must span validation AND the caller's commit.
 */
final class CompanionPostgresSourceRaceG3Test extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This concurrency acceptance requires an isolated PostgreSQL service.');
        }

        config(['canovia.super_admin_user_id' => null, 'canovia.admin_email' => null]);
    }

    public function test_companion_commit_locks_repository_and_evidence_until_commit(): void
    {
        [$plan, $root, $evidence, $provenance] = $this->scenario();
        $guard = app(CompanionDerivedEvidenceSourceGuard::class);
        $rival = $this->rivalConnection();

        $this->assertTrue($guard->isCurrent($plan, $provenance));

        DB::transaction(function () use ($plan, $root, $evidence, $guard, $provenance, $rival): void {
            // Real Companion commit code locks Plan and Task before source rows.
            Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            Task::query()->whereKey($evidence->task_id)->lockForUpdate()->firstOrFail();

            $this->assertTrue($guard->isCurrentForCommit($plan, $provenance));

            // A different PostgreSQL worker is not allowed to revoke the
            // source while the candidate/assistant is being persisted.
            $revoked = (array) $root->metadata;
            $revoked['github_app_connection']['status'] = 'revoked';

            $this->expectLockTimeout(
                $rival,
                'UPDATE plan_artifacts SET metadata = :metadata WHERE id = :id',
                ['metadata' => json_encode($revoked), 'id' => $root->id],
            );

            // Same-epoch Evidence content updates must also serialize.
            $changed = (array) $evidence->metadata;
            $changed['issue_state'] = 'reopened';
            $this->expectLockTimeout(
                $rival,
                'UPDATE task_evidences SET metadata = :metadata WHERE id = :id',
                ['metadata' => json_encode($changed), 'id' => $evidence->id],
            );
            $this->assertTrue($guard->isCurrentForCommit($plan, $provenance));
        });

        // After A commits, B can revoke. Replaying the old provenance fails.
        $this->setRepositoryStatus($rival, $root, 'revoked');
        $this->assertFalse($guard->isCurrent($plan, $provenance));
        $this->assertFalse(DB::transaction(
            fn () => $guard->isCurrentForCommit($plan, $provenance)
        ));
        $this->assertDatabaseHas('task_evidences', ['id' => $evidence->id]);
    }

    public function test_revocation_that_wins_first_prevents_companion_commit(): void
    {
        [$plan, $root, $evidence, $provenance] = $this->scenario();
        $guard = app(CompanionDerivedEvidenceSourceGuard::class);
        $rival = $this->rivalConnection();
        $revoked = (array) $root->metadata;
        $revoked['github_app_connection']['status'] = 'revoked';

        // Competing worker B owns the repository row and has not committed.
        $rival->beginTransaction();
        try {
            $this->execute($rival,
                'UPDATE plan_artifacts SET metadata = :metadata WHERE id = :id',
                ['metadata' => json_encode($revoked), 'id' => $root->id]
            );

            // Worker A may fail on lock timeout/deadlock, but cannot commit
            // an AI-derived mutation based on the last committed "connected".
            try {
                DB::transaction(function () use ($plan, $guard, $provenance): void {
                    DB::statement("SET LOCAL lock_timeout = '250ms'");
                    Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
                    $guard->isCurrentForCommit($plan, $provenance);
                    $this->fail('An uncommitted revocation must not permit validation.');
                });
                $this->fail('Expected PostgreSQL row-lock conflict');
            } catch (\Illuminate\Database\QueryException $exception) {
                $this->assertSame('55P03', (string) ($exception->errorInfo[0] ?? ''));
            }

            $rival->commit();
        } finally {
            if ($rival->inTransaction()) {
                $rival->rollBack();
            }
        }

        // Once the competing write is durable, the next request is rejected.
        $this->assertFalse(DB::transaction(
            fn () => $guard->isCurrentForCommit($plan, $provenance)
        ));
        $this->assertDatabaseHas('task_evidences', ['id' => $evidence->id]);
    }

    public function test_commit_only_guard_cannot_be_used_outside_transaction(): void
    {
        [$plan, , , $provenance] = $this->scenario();

        $this->expectException(LogicException::class);
        app(CompanionDerivedEvidenceSourceGuard::class)
            ->isCurrentForCommit($plan, $provenance);
    }

    private function expectLockTimeout(PDO $rival, string $sql, array $params): void
    {
        $rival->beginTransaction();
        try {
            $rival->exec("SET LOCAL lock_timeout = '250ms'");
            try {
                $this->execute($rival, $sql, $params);
                $this->fail('Another connection was able to update a locked source.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', (string) ($exception->errorInfo[0] ?? ''));
            }
        } finally {
            $rival->rollBack();
        }
    }

    private function setRepositoryStatus(PDO $rival, PlanArtifact $root, string $status): void
    {
        $meta = (array) $root->metadata;
        $meta['github_app_connection']['status'] = $status;
        $this->execute($rival,
            'UPDATE plan_artifacts SET metadata = :metadata WHERE id = :id',
            ['metadata' => json_encode($meta), 'id' => $root->id]
        );
    }

    private function execute(PDO $pdo, string $sql, array $params): void
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function rivalConnection(): PDO
    {
        $config = config('database.connections.pgsql');
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 5432);
        $database = (string) ($config['database'] ?? '');
        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        return new PDO(
            $dsn,
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
            'title' => 'G3 isolated PG conflict',
            'description' => 'Independent DB connections',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Review current GitHub status',
            'description' => 'provider source',
            'status' => 'doing',
            'progress_percent' => 25,
            'priority' => 1,
            'activation_cost' => 2,
            'estimated_minutes' => 60,
            'remaining_minutes' => 45,
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
                'source_epoch' => 'e8fb8d38-e329-4a69-a58e-3707d47f8780',
            ]],
        ]);
        $evidence = TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::GitHub->value,
            'type' => 'github_issue_observed',
            'external_key' => 'github:g3:issue:77',
            'occurred_at' => now(),
            'metadata' => [
                'repo_full_name' => '1kz-ma1/Canovia-web',
                'github_source_epoch' => 'e8fb8d38-e329-4a69-a58e-3707d47f8780',
                'github_repository_artifact_id' => $root->id,
                'issue_state' => 'closed',
                'issue_number' => 77,
            ],
        ]);

        $guard = app(CompanionDerivedEvidenceSourceGuard::class);
        $provenance = ['version' => 1, 'github_evidence' => [[
            'id' => (int) $evidence->id,
            'revision' => $guard->revision($evidence),
        ]]];

        return [$plan, $root, $evidence, $provenance];
    }
}
