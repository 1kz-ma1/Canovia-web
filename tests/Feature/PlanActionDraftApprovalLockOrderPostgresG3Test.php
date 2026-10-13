<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\PlanActionDraft;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Models\WorkLog;
use App\Services\PlanActionDraftApprovalSourceGuard;
use App\Services\PlanActionDraftTypedEvidenceService;
use App\Services\PlanActionDraftEvidenceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Real independent PG16 sessions, never Aiven. Covers source/Task/repository
 * rows through the owner's final Task insertion transaction.
 */
final class PlanActionDraftApprovalLockOrderPostgresG3Test extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL 16.');
        }

        config(['canovia.super_admin_user_id' => null, 'canovia.admin_email' => null]);
    }

    public function test_approval_holds_task_github_root_and_evidence_locks_through_commit(): void
    {
        [$plan, $task, $root, $evidence, $draft] = $this->githubScenario();
        $rival = $this->rivalConnection();
        $guard = app(PlanActionDraftApprovalSourceGuard::class);

        DB::transaction(function () use ($plan, $task, $root, $evidence, $draft, $rival, $guard): void {
            $this->lockOwnerAndDraft($plan, $draft);
            $guard->assertCurrent($plan, $draft);

            // Revocation, retargeting and evidence edits all serialize
            // behind the same final approval/Task creation transaction.
            $this->expectBlocked($rival,
                'UPDATE tasks SET title = :title WHERE id = :id',
                ['title' => 'concurrent edit', 'id' => $task->id],
            );
            $meta = $root->metadata;
            $meta['github_app_connection']['status'] = 'revoked';
            $this->expectBlocked($rival,
                'UPDATE plan_artifacts SET metadata = :meta WHERE id = :id',
                ['meta' => json_encode($meta), 'id' => $root->id],
            );
            $this->expectBlocked($rival,
                'UPDATE task_evidences SET metadata = :meta WHERE id = :id',
                ['meta' => json_encode(['tampered' => true]), 'id' => $evidence->id],
            );
            $this->assertDatabaseCount('tasks', 1);
        }, 3);

        $meta = $root->metadata;
        $meta['github_app_connection']['status'] = 'revoked';
        $this->exec($rival,
            'UPDATE plan_artifacts SET metadata = :meta WHERE id = :id',
            ['meta' => json_encode($meta), 'id' => $root->id],
        );

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        DB::transaction(function () use ($plan, $draft, $guard): void {
            $this->lockOwnerAndDraft($plan, $draft);
            $guard->assertCurrent($plan, $draft);
        });
    }

    public function test_uncommitted_github_revocation_prevents_approval_from_validating_old_epoch(): void
    {
        [$plan, , $root, , $draft] = $this->githubScenario();
        $rival = $this->rivalConnection();
        $meta = $root->metadata;
        $meta['github_app_connection']['status'] = 'revoked';

        $rival->beginTransaction();
        try {
            $this->exec($rival,
                'UPDATE plan_artifacts SET metadata = :meta WHERE id = :id',
                ['meta' => json_encode($meta), 'id' => $root->id],
            );

            try {
                DB::transaction(function () use ($plan, $draft): void {
                    DB::statement("SET LOCAL lock_timeout = '250ms'");
                    $this->lockOwnerAndDraft($plan, $draft);
                    app(PlanActionDraftApprovalSourceGuard::class)->assertCurrent($plan, $draft);
                    $this->fail('Uncommitted disconnect cannot validate as connected.');
                });
                $this->fail('Expected repository row-lock conflict');
            } catch (QueryException $e) {
                $this->assertSame('55P03', (string) ($e->errorInfo[0] ?? ''));
            }

            $rival->commit();
        } finally {
            if ($rival->inTransaction()) {
                $rival->rollBack();
            }
        }

        try {
            DB::transaction(function () use ($plan, $draft): void {
                $this->lockOwnerAndDraft($plan, $draft);
                app(PlanActionDraftApprovalSourceGuard::class)->assertCurrent($plan, $draft);
            });
            $this->fail('Committed revoked root cannot authorize a Task.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_mixed_worklog_evidence_approval_locks_both_sources_and_task(): void
    {
        [$plan, $task, $root, $evidence] = $this->githubScenario();
        $log = WorkLog::query()->create([
            'plan_id' => $plan->id, 'task_id' => $task->id,
            'task_title_snapshot' => $task->title, 'worked_on' => today(),
            'actual_minutes' => 15, 'progress_delta_percent' => 0,
            'outcome' => '本人確認済み',
        ]);
        $draft = $this->draft($plan, [
            ...app(PlanActionDraftEvidenceService::class)->snapshotsForLogs($plan, [$log->id]),
            ...app(PlanActionDraftTypedEvidenceService::class)->snapshots($plan, [$evidence->id]),
        ]);
        $rival = $this->rivalConnection();

        DB::transaction(function () use ($plan, $draft, $task, $log, $rival): void {
            $this->lockOwnerAndDraft($plan, $draft);
            app(PlanActionDraftApprovalSourceGuard::class)->assertCurrent($plan, $draft);
            $this->expectBlocked($rival, 'UPDATE work_logs SET outcome = :value WHERE id = :id',
                ['value' => 'revoked', 'id' => $log->id]);
            $this->expectBlocked($rival, 'UPDATE tasks SET title = :value WHERE id = :id',
                ['value' => 'retargeted', 'id' => $task->id]);
        });
        $this->assertDatabaseCount('tasks', 1);
    }

    private function githubScenario(): array
    {
        $owner = User::factory()->create();
        UserProductGrant::query()->create([
            'user_id' => $owner->id, 'product_key' => ProductKey::AllAccess,
            'source' => 'manual', 'starts_at' => now()->subMinute(),
        ]);
        $plan = Plan::query()->create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'G3 draft lock order',
            'category' => '個人開発', 'priority' => 3,
            'priority_mode' => 'manual', 'start_date' => today(),
            'deadline' => today()->addMonth(), 'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'Review PR',
            'estimated_minutes' => 30, 'remaining_minutes' => 30,
            'progress_percent' => 10, 'status' => 'doing',
            'priority' => 3, 'activation_cost' => 3, 'sort_order' => 0,
        ]);
        $root = PlanArtifact::query()->create([
            'plan_id' => $plan->id, 'created_by_user_id' => $owner->id,
            'provider' => 'github', 'artifact_type' => 'repository',
            'title' => 'Canovia-web', 'url' => 'https://github.com/1kz-ma1/Canovia-web',
            'metadata' => ['github_app_connection' => [
                'status' => 'connected', 'installation_id' => 777,
                'source_epoch' => (string) Str::uuid(),
            ]],
        ]);
        $evidence = TaskEvidence::query()->create([
            'plan_id' => $plan->id, 'task_id' => $task->id,
            'source' => EvidenceSource::GitHub, 'type' => 'pull_request_merged',
            'occurred_at' => now(), 'metadata' => [
                'repo_full_name' => '1kz-ma1/Canovia-web',
                'github_repository_artifact_id' => $root->id,
                'github_source_epoch' => data_get($root->metadata, 'github_app_connection.source_epoch'),
                'pull_request_number' => 45,
            ],
        ]);
        $snapshots = app(PlanActionDraftTypedEvidenceService::class)
            ->snapshots($plan, [$evidence->id]);
        return [$plan, $task, $root, $evidence, $this->draft($plan, $snapshots)];
    }

    private function draft(Plan $plan, array $snapshots): PlanActionDraft
    {
        return PlanActionDraft::query()->create([
            'plan_id' => $plan->id, 'request_id' => (string) Str::uuid(),
            'source_kind' => 'task_evidence', 'completed_action' => 'Merged',
            'observed_outcome' => 'Shipped', 'suggested_next_action' => 'Verify results',
            'status' => PlanActionDraft::PROPOSED, 'evidence_snapshots' => $snapshots,
            'revision_no' => 1,
        ]);
    }

    private function lockOwnerAndDraft(Plan $plan, PlanActionDraft $draft): void
    {
        Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
        PlanActionDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
    }

    private function expectBlocked(PDO $pdo, string $sql, array $params): void
    {
        $pdo->beginTransaction();
        try {
            $pdo->exec("SET LOCAL lock_timeout = '250ms'");
            try {
                $this->exec($pdo, $sql, $params);
                $this->fail('Competing session modified a locked source.');
            } catch (PDOException $e) {
                $this->assertSame('55P03', (string) ($e->errorInfo[0] ?? ''));
            }
        } finally {
            $pdo->rollBack();
        }
    }

    private function exec(PDO $pdo, string $sql, array $params): void
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function rivalConnection(): PDO
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 5432),
            (string) ($config['database'] ?? ''),
        );

        return new PDO(
            $dsn,
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }
}
