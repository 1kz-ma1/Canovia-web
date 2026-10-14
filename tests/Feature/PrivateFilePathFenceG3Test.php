<?php

namespace Tests\Feature;

use App\Services\PrivateFileLifecycleService;
use App\Services\PrivateFilePathFenceService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;

final class PrivateFilePathFenceG3Test extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        config(['canovia.private_file_path_fence_enabled' => true]);
    }

    public function test_fingerprint_is_stable_keyed_redacted_and_row_is_reused(): void
    {
        $fence = app(PrivateFilePathFenceService::class);
        $disk = (string) config('filesystems.default');
        $path = 'inbox/private-user/a.pdf';
        $digest = $fence->fingerprint($disk, $path);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $digest);
        $this->assertSame($digest, $fence->fingerprint($disk, $path));
        $this->assertNotSame($digest, $fence->fingerprint($disk, 'inbox/private-user/b.pdf'));

        DB::transaction(function () use ($fence, $disk, $path): void {
            $fence->acquireInTransaction($disk, $path);
            $fence->acquireInTransaction($disk, $path);
        });

        $this->assertDatabaseCount('private_file_path_fences', 1);
        $stored = DB::table('private_file_path_fences')->first();
        $this->assertSame($digest, $stored->path_digest);
        $this->assertStringNotContainsString('private-user', json_encode($stored, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('a.pdf', json_encode($stored, JSON_THROW_ON_ERROR));
    }

    public function test_fence_requires_valid_key_and_existing_transaction(): void
    {
        $fence = app(PrivateFilePathFenceService::class);
        $disk = (string) config('filesystems.default');
        $this->expectException(RuntimeException::class);
        $fence->acquireInTransaction($disk, 'inbox/valid/a.pdf');
    }

    public function test_invalid_paths_and_wrong_disk_are_rejected_without_persistence(): void
    {
        $fence = app(PrivateFilePathFenceService::class);
        $disk = (string) config('filesystems.default');
        foreach (['../inbox/key.pdf', 'inbox/../key.pdf', 'public/x.png', 'inbox//x.png'] as $path) {
            try {
                $fence->fingerprint($disk, $path);
                $this->fail('Invalid private path must be denied');
            } catch (\InvalidArgumentException) {
                // expected, without the invalid path in the exception
            }
        }
        try {
            $fence->fingerprint('wrong-private-disk', 'inbox/valid/a.pdf');
            $this->fail('Wrong storage disk must not use a valid key');
        } catch (\InvalidArgumentException) {
        }
        $this->assertDatabaseCount('private_file_path_fences', 0);
    }

    public function test_rollback_never_makes_a_fence_row_permanently_visible(): void
    {
        $fence = app(PrivateFilePathFenceService::class);
        $disk = (string) config('filesystems.default');
        try {
            DB::transaction(function () use ($fence, $disk): void {
                $fence->acquireInTransaction($disk, 'inbox/owner/rolled-back.png');
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }
        $this->assertDatabaseCount('private_file_path_fences', 0);
    }

    public function test_opted_in_cleanup_holds_fence_and_still_deletes_unreferenced_bytes(): void
    {
        $path = 'inbox/owner/old.png';
        Storage::put($path, 'sealed version');
        DB::table('private_file_cleanup_entries')->insert([
            'encrypted_path' => Crypt::encryptString($path),
            'disk' => (string) config('filesystems.default'),
            'expected_sha256' => hash('sha256', 'sealed version'),
            'status' => 'pending', 'attempt_count' => 0,
            'next_attempt_at' => now()->subMinute(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(PrivateFileLifecycleService::class)->retryDue();
        $this->assertSame(1, $result['deleted']);
        Storage::assertMissing($path);
        $this->assertDatabaseCount('private_file_cleanup_entries', 0);
        $this->assertDatabaseCount('private_file_path_fences', 1);
    }

    public function test_fence_schema_absence_makes_opted_in_cleanup_non_destructive(): void
    {
        $path = 'inbox/owner/missing-fence-schema.png';
        Storage::put($path, 'original');
        DB::table('private_file_cleanup_entries')->insert([
            'encrypted_path' => Crypt::encryptString($path),
            'disk' => (string) config('filesystems.default'),
            'expected_sha256' => hash('sha256', 'original'),
            'status' => 'pending', 'attempt_count' => 0,
            'next_attempt_at' => now()->subMinute(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $realSchema = Schema::getFacadeRoot();
        Schema::shouldReceive('hasTable')->once()
            ->with('private_file_path_fences')->andReturn(false);
        try {
            $result = app(PrivateFileLifecycleService::class)->retryDue();
        } finally {
            Schema::swap($realSchema);
        }
        $this->assertSame(1, $result['retry']);
        Storage::assertExists($path);
        $this->assertDatabaseHas('private_file_cleanup_entries', [
            'status' => 'pending',
            'last_reason' => 'path_fence_unavailable',
            'attempt_count' => 1,
        ]);
    }

    public function test_independent_postgres_session_cannot_claim_same_path_while_fenced(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Independent lock test requires disposable PG16.');
        }
        $fence = app(PrivateFilePathFenceService::class);
        $disk = (string) config('filesystems.default');
        $path = 'study-recall-sources/owner/locked.pdf';
        $digest = $fence->fingerprint($disk, $path);
        $db = config('database.connections.pgsql');
        $rival = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s',
            (string) ($db['host'] ?? '127.0.0.1'),
            (int) ($db['port'] ?? 5432),
            (string) ($db['database'] ?? ''),
        ), (string) ($db['username'] ?? ''), (string) ($db['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        DB::transaction(function () use ($fence, $disk, $path, $digest, $rival): void {
            $fence->acquireInTransaction($disk, $path);
            $rival->beginTransaction();
            try {
                $rival->exec("SET LOCAL lock_timeout = '250ms'");
                $query = $rival->prepare(
                    'SELECT id FROM private_file_path_fences WHERE path_digest = :digest FOR UPDATE',
                );
                try {
                    $query->execute(['digest' => $digest]);
                    $this->fail('An independent session must not acquire the same path lock.');
                } catch (PDOException $e) {
                    $this->assertSame('55P03', (string) ($e->errorInfo[0] ?? ''));
                }
            } finally {
                $rival->rollBack();
            }
        });

        // Row still exists after commit and can be safely reused.
        DB::transaction(fn () => $fence->acquireInTransaction($disk, $path));
        $this->assertDatabaseCount('private_file_path_fences', 1);
    }
}
