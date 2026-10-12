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

    public function test_nullable_source_revision_upgrade_preserves_legacy_observation_and_identity(): void
    {
        $migrationName = '2026_10_12_010000_add_source_revision_to_development_activity_observations';
        $this->assertDatabaseHas('migrations', ['migration' => $migrationName]);
        $this->assertTrue(Schema::hasColumn('development_activity_observations', 'source_updated_at'));
        $this->assertTrue(Schema::hasColumn('development_activity_observations', 'source_revision_id'));

        // Recreate the pre-release column shape ONLY in the CI database;
        // the existing live database is never queried or modified.
        Schema::table('development_activity_observations', function (Blueprint $table): void {
            $table->dropColumn(['source_updated_at', 'source_revision_id']);
        });

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

        // Explicitly apply ONLY the newly-pending additive migration on
        // the synthetic, pre-release table. Never run down() on user data.
        $migration = require database_path('migrations/'.$migrationName.'.php');
        $migration->up();

        $after = DB::table('development_activity_observations')->find($id);
        $this->assertNotNull($after);
        foreach (['plan_id', 'repository_artifact_id', 'provider', 'kind',
            'external_key', 'state', 'last_observed_at', 'resolution_status',
            'created_at', 'updated_at'] as $field) {
            $this->assertSame((string) $before->{$field}, (string) $after->{$field}, $field);
        }
        $this->assertNull($after->source_updated_at);
        $this->assertNull($after->source_revision_id);
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

        // Freshly observed versions can be persisted without rewriting the
        // historical immutable identity or altering unrelated Task progress.
        DB::table('development_activity_observations')->where('id', $id)->update([
            'source_revision_id' => 41, 'source_updated_at' => now()->startOfSecond(),
        ]);
        $this->assertSame(41, (int) DB::table('development_activity_observations')->where('id', $id)
            ->value('source_revision_id'));
        $this->assertDatabaseHas('migrations', ['migration' => $migrationName]);
    }
}
