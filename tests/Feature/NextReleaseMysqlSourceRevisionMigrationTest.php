<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Disposable MySQL 8.0/8.4 only. A release rehearsal, not a production DB check.
 */
final class NextReleaseMysqlSourceRevisionMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CANOVIA_P0_DISPOSABLE_MYSQL_CI') !== '1') {
            $this->markTestSkipped('Requires disposable MySQL CI.');
        }

        if (! app()->environment('testing')
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.host') !== '127.0.0.1'
            || config('database.connections.mysql.database') !== 'canovia_p0_ci'
            || config('database.connections.mysql.username') !== 'canovia_ci'
            || (string) config('database.connections.mysql.url', '') !== '') {
            throw new \RuntimeException('Release migration test refused: not isolated disposable MySQL.');
        }
    }

    public function test_completed_source_observation_replay_preserves_identity_without_ddl(): void
    {
        $migrationName = '2026_10_12_010000_add_source_revision_to_development_activity_observations';
        $this->assertDatabaseHas('migrations', ['migration' => $migrationName]);
        $this->assertTrue(Schema::hasColumn('development_activity_observations', 'source_updated_at'));
        $this->assertTrue(Schema::hasColumn('development_activity_observations', 'source_revision_id'));
        $this->assertDatabaseHas('migrations', ['migration' => '2026_10_13_021000_add_source_epoch_to_development_activity_observations']);
        $this->assertTrue(Schema::hasColumn('development_activity_observations', 'source_epoch'));

        // Full94->114 is covered by the new rehearsal. Do not manufacture a
        // ledger114 database with the initial-release columns removed here.

        $user = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $user->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'Disposable release gate',
            'category' => '個人開発', 'priority' => 3, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addWeek(),
        ]);
        $artifact = PlanArtifact::query()->create([
            'plan_id' => $plan->id, 'created_by_user_id' => $user->id,
            'provider' => 'github', 'artifact_type' => 'repository',
            'title' => 'CI-only repository', 'url' => 'https://github.com/example/disposable',
        ]);
        $key = 'ci-legacy-'.Str::lower(Str::random(20));
        $created = now()->subDay()->startOfSecond();
        $id = DB::table('development_activity_observations')->insertGetId([
            'plan_id' => $plan->id, 'repository_artifact_id' => $artifact->id,
            'provider' => 'github', 'kind' => 'issue', 'external_key' => $key,
            'state' => 'open', 'last_observed_at' => $created,
            'resolution_status' => 'unlinked', 'created_at' => $created,
            'updated_at' => $created,
        ]);
        $before = DB::table('development_activity_observations')->find($id);

        $ddl = 0;
        DB::listen(function ($query) use (&$ddl): void {
            if (preg_match('/^(create|alter|drop|rename)\b/i', $query->sql)) {
                $ddl++;
            }
        });
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame(0, $ddl);

        $after = DB::table('development_activity_observations')->find($id);
        $this->assertNotNull($after);
        foreach (['plan_id', 'repository_artifact_id', 'provider', 'kind',
            'external_key', 'state', 'last_observed_at', 'resolution_status',
            'created_at', 'updated_at'] as $field) {
            $this->assertSame((string) $before->{$field}, (string) $after->{$field}, $field);
        }
        $this->assertNull($after->source_updated_at);
        $this->assertNull($after->source_revision_id);
        $this->assertNull($after->source_epoch);
        $this->assertSame(1, DB::table('development_activity_observations')
            ->where('plan_id', $plan->id)->where('provider', 'github')
            ->where('external_key', $key)->count());

        $columns = collect(DB::select(<<<'SQL'
            SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE
              FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'development_activity_observations'
               AND COLUMN_NAME IN ('source_updated_at', 'source_revision_id')
             ORDER BY COLUMN_NAME
        SQL))->keyBy('COLUMN_NAME');
        $this->assertCount(2, $columns);
        foreach (['source_updated_at', 'source_revision_id'] as $column) {
            $this->assertSame('YES', $columns[$column]->IS_NULLABLE);
            $this->assertNull($columns[$column]->COLUMN_DEFAULT);
        }
        $this->assertSame('bigint', $columns['source_revision_id']->DATA_TYPE);
        $this->assertSame('timestamp', $columns['source_updated_at']->DATA_TYPE);

        $epochColumns = DB::select(<<<'SQL'
            SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT
              FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'development_activity_observations'
               AND COLUMN_NAME = 'source_epoch'
        SQL);
        $this->assertCount(1, $epochColumns);
        $this->assertSame('char', $epochColumns[0]->DATA_TYPE);
        $this->assertSame(36, (int) $epochColumns[0]->CHARACTER_MAXIMUM_LENGTH);
        $this->assertSame('YES', $epochColumns[0]->IS_NULLABLE);
        $this->assertNull($epochColumns[0]->COLUMN_DEFAULT);
        $epochIndex = DB::select(<<<'SQL'
            SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME
              FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'development_activity_observations'
               AND INDEX_NAME = 'development_activity_observations_source_epoch_index'
        SQL);
        $this->assertCount(1, $epochIndex);
        $this->assertSame('source_epoch', $epochIndex[0]->COLUMN_NAME);
        $this->assertSame(1, (int) $epochIndex[0]->NON_UNIQUE);

        // Freshly observed versions can be persisted without rewriting the
        // historical immutable identity or altering unrelated Task progress.
        DB::table('development_activity_observations')->where('id', $id)->update([
            'source_revision_id' => 41, 'source_updated_at' => now()->startOfSecond(),
        ]);
        $this->assertSame(41, (int) DB::table('development_activity_observations')->where('id', $id)
            ->value('source_revision_id'));
        $epochValue = (string) Str::uuid();
        DB::table('development_activity_observations')->where('id', $id)
            ->update(['source_epoch' => $epochValue]);
        $this->assertSame($epochValue, DB::table('development_activity_observations')
            ->where('id', $id)->value('source_epoch'));
        $this->assertDatabaseHas('migrations', ['migration' => $migrationName]);
    }
}
