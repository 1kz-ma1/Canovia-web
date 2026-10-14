<?php

namespace Tests\Feature;

use App\Services\PrivateFileLifecycleService;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Isolated MySQL 8.0/8.4. This is NOT a test against Aiven.
 */
final class PrivateFileCleanupMysqlCurrentReadG3Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CANOVIA_G3_DISPOSABLE_MYSQL_CI') !== '1'
            || ! app()->environment('testing')
            || DB::connection()->getDriverName() !== 'mysql'
            || config('database.connections.mysql.host') !== '127.0.0.1'
            || config('database.connections.mysql.database') !== 'canovia_g3_ci'
            || config('database.connections.mysql.username') !== 'canovia_ci'
            || (string) config('database.connections.mysql.url', '') !== '') {
            throw new \RuntimeException('G3 test refused: not isolated disposable MySQL.');
        }
    }

    public function test_committed_reference_is_visible_after_stale_repeatable_read_snapshot(): void
    {
        $path = 'inbox/other-owner/committed-after-snapshot.pdf';
        $rival = $this->rival();

        $this->assertSame('REPEATABLE-READ',
            (string) DB::selectOne('SELECT @@transaction_isolation AS level')->level);

        DB::transaction(function () use ($path, $rival): void {
            // Ordinary SELECT opens a snapshot before the other connection commits.
            $this->assertFalse(DB::table('inbox_items')
                ->where('storage_path', $path)->exists());

            $stmt = $rival->prepare(
                'INSERT INTO inbox_items (source_type, status, storage_path, created_at, updated_at) '
                ."VALUES ('pdf', 'new', :path, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
            );
            $stmt->execute(['path' => $path]);

            // Previous exists() implementation incorrectly sees no owner.
            $this->assertFalse(DB::table('inbox_items')
                ->where('storage_path', $path)->exists());
            // Locking SELECT FOR UPDATE must see the freshly committed owner.
            $this->assertTrue(app(PrivateFileLifecycleService::class)
                ->isReferenced($path));
        });

        $this->assertDatabaseHas('inbox_items', ['storage_path' => $path]);
        // Only this throwaway CI database is accessed. Clean up the rival's
        // committed row without using a production account or storage key.
        DB::table('inbox_items')->where('storage_path', $path)->delete();
    }

    public function test_current_read_does_not_invent_reference(): void
    {
        DB::transaction(function (): void {
            $this->assertFalse(app(PrivateFileLifecycleService::class)
                ->isReferenced('inbox/unreferenced/no-file.png'));
        });
    }

    public function test_opt_in_row_fence_serializes_two_independent_mysql_connections(): void
    {
        config(['canovia.private_file_path_fence_enabled' => true]);
        $fence = app(\App\Services\PrivateFilePathFenceService::class);
        $disk = (string) config('filesystems.default');
        $path = 'study-recall-sources/synthetic/'.\Illuminate\Support\Str::uuid().'/lock.png';
        $digest = $fence->fingerprint($disk, $path);
        $rival = $this->rival();

        // Persist the key before entering the competing transactions.
        DB::transaction(fn () => $fence->acquireInTransaction($disk, $path));
        DB::transaction(function () use ($fence, $disk, $path, $digest, $rival): void {
            $fence->acquireInTransaction($disk, $path);
            $rival->beginTransaction();
            try {
                $rival->exec('SET SESSION innodb_lock_wait_timeout = 1');
                $stmt = $rival->prepare(
                    'SELECT id FROM private_file_path_fences WHERE path_digest = :digest FOR UPDATE',
                );
                try {
                    $stmt->execute(['digest' => $digest]);
                    $this->fail('Second MySQL session bypassed the active path fence.');
                } catch (\PDOException $e) {
                    $this->assertSame(1205, (int) ($e->errorInfo[1] ?? 0));
                }
            } finally {
                $rival->rollBack();
            }
        });

        DB::transaction(fn () => $fence->acquireInTransaction($disk, $path));
        $this->assertDatabaseHas('private_file_path_fences', ['path_digest' => $digest]);
        $this->assertDatabaseMissing('private_file_path_fences', ['path_digest' => $path]);
    }

    private function rival(): PDO
    {
        $db = config('database.connections.mysql');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) ($db['host'] ?? '127.0.0.1'),
            (int) ($db['port'] ?? 3306),
            (string) ($db['database'] ?? ''),
        );
        return new PDO($dsn, (string) ($db['username'] ?? ''),
            (string) ($db['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
