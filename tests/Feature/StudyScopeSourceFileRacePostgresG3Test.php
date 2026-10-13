<?php

namespace Tests\Feature;

use App\Exceptions\NativeAiExecutionException;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\StudyScopeCapture;
use App\Models\User;
use App\Services\StudyScopeCaptureCommitFence;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Two real independent PostgreSQL 16 sessions; no Aiven/Render access.
 * DB source ownership serializes physical cleanup with AI source checks.
 */
final class StudyScopeSourceFileRacePostgresG3Test extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires isolated PostgreSQL 16.');
        }
        $this->withoutVite();
        Storage::fake(config('filesystems.default'));
        config(['canovia.super_admin_user_id' => null, 'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled']);
    }

    public function test_ai_source_locks_block_independent_correction_or_delete_until_transaction_commits(): void
    {
        [$owner, $plan, $source, $capture] = $this->scenario();
        $rival = $this->rival();

        DB::transaction(function () use ($plan, $capture, $source, $rival): void {
            Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            StudyScopeCapture::query()->whereKey($capture->id)->lockForUpdate()->firstOrFail();
            InboxItem::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();

            $this->expectBlocked($rival,
                'UPDATE inbox_items SET content = :content WHERE id = :id',
                ['content' => 'changed', 'id' => $source->id],
            );
            $this->expectBlocked($rival,
                'DELETE FROM study_scope_captures WHERE id = :id',
                ['id' => $capture->id],
            );
        });

        $this->assertDatabaseCount('study_scope_captures', 1);
        $this->assertSame('original', $source->fresh()->content);
        Storage::assertExists($source->storage_path);
    }

    public function test_rival_inbox_edit_wins_first_and_discard_waits_for_commit(): void
    {
        [$owner, $plan, $source, $capture] = $this->scenario();
        $rival = $this->rival();
        // Without this, Laravel converts the expected lock timeout into
        // an HTTP error response and the test cannot inspect SQLSTATE 55P03.
        $this->withoutExceptionHandling();
        $rival->beginTransaction();

        try {
            $this->execute($rival,
                'UPDATE inbox_items SET content = :content WHERE id = :id',
                ['content' => 'changed first', 'id' => $source->id],
            );

            try {
                DB::transaction(function () use ($owner, $plan, $capture): void {
                    DB::statement("SET LOCAL lock_timeout = '250ms'");
                    $this->actingAs($owner)->delete(
                        route('plans.study_scope.destroy', [$plan, $capture]),
                    );
                    $this->fail('Discard must wait for the competing Inbox lock.');
                });
            } catch (QueryException $exception) {
                $this->assertSame('55P03', (string) ($exception->errorInfo[0] ?? ''));
            }
            $rival->commit();
        } finally {
            if ($rival->inTransaction()) {
                $rival->rollBack();
            }
        }

        $this->assertDatabaseCount('study_scope_captures', 1);
        Storage::assertExists($source->storage_path);

        $this->actingAs($owner)
            ->delete(route('plans.study_scope.destroy', [$plan, $capture]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('study_scope_captures', 0);
        $this->assertDatabaseCount('inbox_items', 0);
        Storage::assertMissing($source->storage_path);
    }

    public function test_rival_removes_source_before_provider_input_seal_and_old_signature_fails(): void
    {
        [$owner, $plan, $source, $capture] = $this->scenario();
        // Study Scope capture itself is free, even with native AI disabled.
        $guard = app(StudyScopeCaptureCommitFence::class);
        $signed = $guard->prepare($capture, $plan, $owner->id);
        $inputDigest = hash('sha256', Storage::get($source->storage_path));
        $rival = $this->rival();

        $rival->beginTransaction();
        try {
            $this->execute($rival,
                'UPDATE inbox_items SET content = :content WHERE id = :id',
                ['content' => 'new source note', 'id' => $source->id],
            );

            try {
                DB::transaction(function () use (
                    $guard, $capture, $plan, $owner, $signed, $inputDigest,
                ): void {
                    DB::statement("SET LOCAL lock_timeout = '250ms'");
                    $guard->assertInputMatches(
                        $capture, $plan, $owner->id, $signed, $inputDigest,
                    );
                    $this->fail('Pre-provider source seal must wait for edit.');
                });
            } catch (QueryException $exception) {
                $this->assertSame('55P03', (string) ($exception->errorInfo[0] ?? ''));
            }
            $rival->commit();
        } finally {
            if ($rival->inTransaction()) {
                $rival->rollBack();
            }
        }

        try {
            $guard->assertInputMatches($capture, $plan, $owner->id, $signed, $inputDigest);
            $this->fail('Source note edit must invalidate original provider input.');
        } catch (NativeAiExecutionException $exception) {
            $this->assertSame('native_ai_source_changed', $exception->errorCode);
        }
        $this->assertNull($capture->fresh()->draft_data);
        $this->assertDatabaseCount('study_scope_items', 0);
    }

    private function scenario(): array
    {
        $owner = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'Study Scope PG16',
            'category' => '試験勉強', 'start_date' => today(),
            'deadline' => today()->addMonth(), 'is_public' => false,
        ]);
        $path = 'inbox/'.$owner->id.'/race.png';
        Storage::put($path, 'initial source bytes');
        $source = InboxItem::query()->create([
            'plan_id' => $plan->id, 'user_id' => $owner->id,
            'source_type' => 'image', 'status' => 'new',
            'storage_path' => $path, 'original_name' => 'race.png',
            'mime_type' => 'image/png', 'content' => 'original',
            'metadata' => ['study_scope_capture' => true, 'capture_surface' => 'study_scope'],
        ]);
        $capture = StudyScopeCapture::query()->create([
            'plan_id' => $plan->id, 'user_id' => $owner->id,
            'inbox_item_id' => $source->id, 'status' => 'captured',
            'extraction_version' => 'study_scope_v1',
        ]);

        return [$owner, $plan, $source, $capture];
    }

    private function expectBlocked(PDO $rival, string $sql, array $params): void
    {
        $rival->beginTransaction();
        try {
            $rival->exec("SET LOCAL lock_timeout = '250ms'");
            try {
                $this->execute($rival, $sql, $params);
                $this->fail('Independent writer changed locked source.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', (string) ($exception->errorInfo[0] ?? ''));
            }
        } finally {
            $rival->rollBack();
        }
    }

    private function execute(PDO $pdo, string $sql, array $params): void
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function rival(): PDO
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s',
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 5432), (string) ($config['database'] ?? ''));

        return new PDO($dsn, (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
