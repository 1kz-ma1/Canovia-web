<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\Plan;
use App\Models\StudyRecallSource;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\StudyRecallExtractionCommitFence;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * No Aiven / Render. Two actual independent PG16 sessions test that source
 * references cannot be changed during the pre-provider authorization fence.
 */
final class RecallInputFileRacePostgresG3Test extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL 16.');
        }

        Storage::fake(config('filesystems.default'));
        config(['canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null]);
    }

    public function test_source_row_remains_locked_while_exact_file_bytes_are_preflight_checked(): void
    {
        [$owner, $plan, $task, $source] = $this->scenario();
        $guard = app(StudyRecallExtractionCommitFence::class);
        $signed = $guard->snapshot($source);
        $authorization = $guard->captureAuthorization($plan, $owner->id);
        $hash = hash('sha256', Storage::get($source->storage_path));
        $rival = $this->rival();

        DB::transaction(function () use (
            $owner, $plan, $task, $source, $signed, $authorization, $hash, $guard, $rival,
        ): void {
            $guard->assertInputMatches(
                $plan, $task, $owner->id,
                [$source->id => $signed], $authorization, [$source->id => $hash],
            );

            // Actual pre-provider locks hold through the caller's unit of
            // work and serialize a competing source retarget or delete.
            $this->expectBlocked($rival,
                'UPDATE study_recall_sources SET storage_path = :path WHERE id = :id',
                ['path' => 'study-recall-sources/tampered.png', 'id' => $source->id],
            );
            $this->expectBlocked($rival,
                'DELETE FROM study_recall_sources WHERE id = :id',
                ['id' => $source->id],
            );
        });

        $this->assertSame('pending', $source->fresh()->status);
        $this->assertDatabaseCount('study_recall_candidates', 0);
    }

    public function test_competing_source_metadata_edit_must_commit_before_old_input_can_be_accepted(): void
    {
        [$owner, $plan, $task, $source] = $this->scenario();
        $guard = app(StudyRecallExtractionCommitFence::class);
        $signed = $guard->snapshot($source);
        $authorization = $guard->captureAuthorization($plan, $owner->id);
        $hash = hash('sha256', Storage::get($source->storage_path));
        $rival = $this->rival();
        $rival->beginTransaction();

        try {
            $this->execute($rival,
                'UPDATE study_recall_sources SET original_name = :name WHERE id = :id',
                ['name' => 'changed.png', 'id' => $source->id],
            );

            try {
                DB::transaction(function () use (
                    $owner, $plan, $task, $source, $signed, $authorization, $hash, $guard,
                ): void {
                    DB::statement("SET LOCAL lock_timeout = '250ms'");
                    $guard->assertInputMatches(
                        $plan, $task, $owner->id,
                        [$source->id => $signed], $authorization,
                        [$source->id => $hash],
                    );
                    $this->fail('Old signature must not bypass the competing source lock.');
                });
                $this->fail('Expected source row lock timeout');
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
            $guard->assertInputMatches(
                $plan, $task, $owner->id,
                [$source->id => $signed], $authorization, [$source->id => $hash],
            );
            $this->fail('Committed source change cannot be signed as original.');
        } catch (NativeAiExecutionException $exception) {
            $this->assertSame('native_ai_source_changed', $exception->errorCode);
        }
        $this->assertDatabaseCount('study_recall_candidates', 0);
    }

    private function scenario(): array
    {
        $owner = User::factory()->create();
        UserProductGrant::query()->create([
            'user_id' => $owner->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'manual', 'starts_at' => now()->subMinute(),
        ]);
        $plan = Plan::query()->create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'Recall race',
            'category' => '資格学習', 'start_date' => today(),
            'deadline' => today()->addMonth(), 'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'Recall cards',
            'status' => 'todo', 'estimated_minutes' => 30,
            'remaining_minutes' => 30,
        ]);
        $path = 'study-recall-sources/'.$plan->id.'/'.$task->id.'/original.png';
        Storage::put($path, 'original image content');
        $source = StudyRecallSource::query()->create([
            'plan_id' => $plan->id, 'task_id' => $task->id,
            'user_id' => $owner->id, 'status' => 'pending',
            'source_type' => 'image', 'mime_type' => 'image/png',
            'original_name' => 'original.png', 'storage_path' => $path,
        ]);

        return [$owner, $plan, $task, $source];
    }

    private function expectBlocked(PDO $rival, string $sql, array $params): void
    {
        $rival->beginTransaction();
        try {
            $rival->exec("SET LOCAL lock_timeout = '250ms'");
            try {
                $this->execute($rival, $sql, $params);
                $this->fail('Independent writer changed a source held by the fence.');
            } catch (PDOException $e) {
                $this->assertSame('55P03', (string) ($e->errorInfo[0] ?? ''));
            }
        } finally {
            $rival->rollBack();
        }
    }

    private function execute(PDO $rival, string $sql, array $params): void
    {
        $stmt = $rival->prepare($sql);
        $stmt->execute($params);
    }

    private function rival(): PDO
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s',
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 5432),
            (string) ($config['database'] ?? ''),
        );
        return new PDO($dsn, (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
