<?php

namespace Tests\Feature;

use App\Services\PrivateFileLifecycleService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Isolated MySQL 8.0/8.4. This is NOT a test against Aiven.
 */
final class PrivateFileCleanupMysqlCurrentReadG3Test extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires disposable MySQL 8.');
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
            $this->assertSame(0, DB::table('inbox_items')->count());

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
    }

    public function test_current_read_does_not_invent_reference(): void
    {
        DB::transaction(function (): void {
            $this->assertFalse(app(PrivateFileLifecycleService::class)
                ->isReferenced('inbox/unreferenced/no-file.png'));
        });
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
