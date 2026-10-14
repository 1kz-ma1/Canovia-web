<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\StudyRecallSource;
use App\Models\Task;
use App\Models\User;
use App\Services\InboxRecallMaterialTransferGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\TestCase;

/** Independent PG16 sessions; tests only disposable database and fake storage. */
final class InboxRecallTransferPostgresG3Test extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL 16.');
        }
        Storage::fake(config('filesystems.default'));
        config(['canovia.super_admin_user_id' => null, 'canovia.admin_email' => null]);
    }

    public function test_transfer_holds_locked_inbox_and_source_parent_rows_through_file_copy(): void
    {
        [$actor, $plan, $task, $item] = $this->scenario();
        $rival = $this->rival();

        DB::transaction(function () use ($actor, $plan, $task, $item, $rival): void {
            $transferred = app(InboxRecallMaterialTransferGuard::class)
                ->copy($item, $plan, $task, $actor, '');
            $source = $transferred['source'];
            $this->assertSame('pending', $source->status);
            $this->assertSame('immutable image A', Storage::get($source->storage_path));

            $this->expectBlocked($rival,
                'UPDATE inbox_items SET content = :content WHERE id = :id',
                ['content' => 'changed independently', 'id' => $item->id],
            );
            $this->expectBlocked($rival,
                'DELETE FROM inbox_items WHERE id = :id',
                ['id' => $item->id],
            );
            $this->expectBlocked($rival,
                'UPDATE tasks SET title = :title WHERE id = :id',
                ['title' => 'moved', 'id' => $task->id],
            );
        });

        $this->assertSame('new', $item->fresh()->status);
        $this->assertSame('original', $item->fresh()->content);
        $this->assertDatabaseCount('study_recall_sources', 1);
    }

    public function test_prior_external_inbox_update_forces_copy_to_wait_then_uses_new_consistent_input(): void
    {
        [$actor, $plan, $task, $item] = $this->scenario();
        $rival = $this->rival();
        $rival->beginTransaction();

        try {
            $this->execute($rival,
                'UPDATE inbox_items SET content = :content WHERE id = :id',
                ['content' => 'corrected before transfer', 'id' => $item->id],
            );

            try {
                DB::transaction(function () use ($actor, $plan, $task, $item): void {
                    DB::statement("SET LOCAL lock_timeout = '250ms'");
                    app(InboxRecallMaterialTransferGuard::class)
                        ->copy($item, $plan, $task, $actor, '');
                    $this->fail('A competing original Inbox update must serialize first.');
                });
                $this->fail('Expected PG source row timeout.');
            } catch (QueryException $e) {
                $this->assertSame('55P03', (string) ($e->errorInfo[0] ?? ''));
            }

            $rival->commit();
        } finally {
            if ($rival->inTransaction()) {
                $rival->rollBack();
            }
        }

        $copy = app(InboxRecallMaterialTransferGuard::class)
            ->copy($item, $plan, $task, $actor, '');
        $this->assertSame('corrected before transfer', $item->fresh()->content);
        $this->assertSame('pending', $copy['source']->status);
        $this->assertSame('immutable image A', Storage::get($copy['source']->storage_path));
        $this->assertDatabaseCount('study_recall_sources', 1);
    }

    public function test_final_confirmation_refuses_prior_external_inbox_change_without_overwriting_it(): void
    {
        [$actor, $plan, $task, $item] = $this->scenario();
        $guard = app(InboxRecallMaterialTransferGuard::class);
        $copy = $guard->copy($item, $plan, $task, $actor, '');

        $source = $copy['source'];
        $source->update(['status' => 'ready']);
        $rival = $this->rival();
        $this->execute($rival,
            'UPDATE inbox_items SET content = :content WHERE id = :id',
            ['content' => 'new original after copy', 'id' => $item->id],
        );

        try {
            $guard->confirmProcessed(
                $item, $plan, $task, $source, $actor, '',
                $copy['signature'], $copy['authorization'],
            );
            $this->fail('A changed original must not be processed under stale routing.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('destination', $e->errors());
        }

        $this->assertSame('new original after copy', $item->fresh()->content);
        $this->assertSame('new', $item->fresh()->status);
        $this->assertDatabaseCount('study_recall_sources', 1);
    }

    private function scenario(): array
    {
        $actor = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $actor->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Inbox transfer PG16', 'category' => '資格学習',
            'start_date' => today(), 'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'Review file',
            'status' => 'todo', 'estimated_minutes' => 30, 'remaining_minutes' => 30,
        ]);
        $path = 'inbox/'.$actor->id.'/source.png';
        Storage::put($path, 'immutable image A');
        $item = InboxItem::query()->create([
            'user_id' => $actor->id, 'plan_id' => $plan->id,
            'source_type' => 'image', 'status' => 'new',
            'storage_path' => $path, 'mime_type' => 'image/png',
            'original_name' => 'source.png', 'content' => 'original',
            'metadata' => ['capture_surface' => 'inbox'],
        ]);
        return [$actor, $plan, $task, $item];
    }

    private function expectBlocked(PDO $rival, string $sql, array $params): void
    {
        $rival->beginTransaction();
        try {
            $rival->exec("SET LOCAL lock_timeout = '250ms'");
            try {
                $this->execute($rival, $sql, $params);
                $this->fail('Competing session mutated locked Inbox routing state.');
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
        $db = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s',
            (string) ($db['host'] ?? '127.0.0.1'),
            (int) ($db['port'] ?? 5432),
            (string) ($db['database'] ?? ''),
        );
        return new PDO($dsn, (string) ($db['username'] ?? ''),
            (string) ($db['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
