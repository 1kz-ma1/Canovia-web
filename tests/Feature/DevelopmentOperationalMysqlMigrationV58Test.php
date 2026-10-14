<?php

namespace Tests\Feature;

use App\Models\DevelopmentOperationalTarget;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rehearses the exact V5.3 then V5.7 additive migrations on disposable MySQL.
 * No refresh-database or migrate:fresh: the existing disposable ledger remains.
 */
final class DevelopmentOperationalMysqlMigrationV58Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CANOVIA_P0_DISPOSABLE_MYSQL_CI') !== '1') {
            $this->markTestSkipped('Requires isolated disposable MySQL CI.');
        }

        if (! app()->environment('testing')
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.host') !== '127.0.0.1'
            || config('database.connections.mysql.database') !== 'canovia_p0_ci'
            || config('database.connections.mysql.username') !== 'canovia_ci'
            || (string) config('database.connections.mysql.url', '') !== '') {
            throw new \RuntimeException('V5.8 refused: not the disposable MySQL CI database.');
        }
    }

    public function test_ordered_additive_migrations_keep_legacy_rows_and_mark_old_attestations_stale(): void
    {
        $v53 = '2026_10_14_185000_add_manual_service_check_to_development_operational_targets_table';
        $v57 = '2026_10_14_201000_add_storage_revision_fence_to_development_operational_records';
        $this->assertDatabaseHas('migrations', ['migration' => $v53]);
        $this->assertDatabaseHas('migrations', ['migration' => $v57]);

        // Reconstruct ONLY the pre-V5.3/5.7 column shape in the
        // disposable CI database; no user data or production connection.
        Schema::table('development_operational_targets', function (Blueprint $table): void {
            $table->dropColumn(['backup_store_revision', 'restore_store_revision']);
        });
        Schema::table('development_operational_profiles', function (Blueprint $table): void {
            $table->dropColumn('data_store_revision');
        });
        Schema::table('development_operational_targets', function (Blueprint $table): void {
            $table->dropColumn(['service_checked_at', 'service_checked_revision', 'service_checked_environment']);
        });

        $user = User::factory()->create();
        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Disposable V5.8 historical Plan',
            'description' => 'CI-only row retention',
            'category' => '個人開発',
            'priority' => 2, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addWeek(),
            'is_public' => false,
        ]);

        $timestamp = now()->subHours(2)->startOfSecond();
        $profileId = DB::table('development_operational_profiles')->insertGetId([
            'plan_id' => $plan->id,
            'stage' => 'public_launch',
            'runtime' => 'web',
            'data_store' => 'server_db',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $targetId = DB::table('development_operational_targets')->insertGetId([
            'plan_id' => $plan->id,
            'provider' => 'render',
            'environment' => 'staging',
            'resource_ref' => 'srv-v58legacy',
            'revision' => 1,
            'backup_confirmed_at' => $timestamp,
            'backup_revision' => 1,
            'backup_store' => 'server_db',
            'restore_confirmed_at' => $timestamp,
            'restore_revision' => 1,
            'restore_store' => 'server_db',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $beforeProfile = DB::table('development_operational_profiles')->where('id', $profileId)->first();
        $beforeTarget = DB::table('development_operational_targets')->where('id', $targetId)->first();

        // Explicit, ordered up() replay on old table shape. Never run down()
        // against real data. No ledger tampering is required for rehearsal.
        (require database_path('migrations/'.$v53.'.php'))->up();
        (require database_path('migrations/'.$v57.'.php'))->up();

        $afterProfile = DB::table('development_operational_profiles')->where('id', $profileId)->first();
        $afterTarget = DB::table('development_operational_targets')->where('id', $targetId)->first();
        foreach (['plan_id', 'stage', 'runtime', 'data_store', 'created_at', 'updated_at'] as $field) {
            $this->assertSame((string) $beforeProfile->{$field}, (string) $afterProfile->{$field}, $field);
        }
        foreach (['plan_id', 'provider', 'environment', 'resource_ref', 'revision',
            'backup_confirmed_at', 'backup_revision', 'backup_store',
            'restore_confirmed_at', 'restore_revision', 'restore_store',
            'created_at', 'updated_at'] as $field) {
            $this->assertSame((string) $beforeTarget->{$field}, (string) $afterTarget->{$field}, $field);
        }
        $this->assertSame(1, (int) $afterProfile->data_store_revision);
        foreach (['service_checked_at', 'service_checked_revision', 'service_checked_environment',
            'backup_store_revision', 'restore_store_revision'] as $field) {
            $this->assertNull($afterTarget->{$field}, $field);
        }

        $target = DevelopmentOperationalTarget::query()->findOrFail($targetId);
        $service = app(\App\Services\DevelopmentOperationalAttestationService::class);
        $projected = $service->project($plan, ['data_store' => 'server_db', 'data_store_revision' => 1], $target);
        $this->assertSame('stale', $projected['backup']['status']);
        $this->assertSame('stale', $projected['restore']['status']);

        $columns = collect(DB::select(<<<'SQL'
            SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
              FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND (
                  (TABLE_NAME = 'development_operational_profiles' AND COLUMN_NAME = 'data_store_revision')
                  OR (TABLE_NAME = 'development_operational_targets'
                     AND COLUMN_NAME IN ('service_checked_at','service_checked_revision',
                                         'service_checked_environment','backup_store_revision','restore_store_revision'))
               )
        SQL))->keyBy('COLUMN_NAME');

        $this->assertCount(6, $columns);
        $this->assertSame('int unsigned', $columns['data_store_revision']->COLUMN_TYPE);
        $this->assertSame('NO', $columns['data_store_revision']->IS_NULLABLE);
        $this->assertSame('1', (string) $columns['data_store_revision']->COLUMN_DEFAULT);
        foreach (['service_checked_revision', 'backup_store_revision', 'restore_store_revision'] as $column) {
            $this->assertSame('int unsigned', $columns[$column]->COLUMN_TYPE);
            $this->assertSame('YES', $columns[$column]->IS_NULLABLE);
            $this->assertNull($columns[$column]->COLUMN_DEFAULT);
        }
        $this->assertSame('timestamp', $columns['service_checked_at']->DATA_TYPE);
        $this->assertSame('YES', $columns['service_checked_at']->IS_NULLABLE);
        $this->assertSame('varchar(24)', $columns['service_checked_environment']->COLUMN_TYPE);
        $this->assertSame('YES', $columns['service_checked_environment']->IS_NULLABLE);

        foreach ([$v53, $v57] as $migration) {
            $this->assertDatabaseHas('migrations', ['migration' => $migration]);
        }
        $this->assertSame(1, DB::table('development_operational_profiles')->where('id', $profileId)->count());
        $this->assertSame(1, DB::table('development_operational_targets')->where('id', $targetId)->count());
    }
}
