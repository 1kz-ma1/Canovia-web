<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MySQL-only interrupted-DDL regression. Dedicated, disposable CI database.
 * No production connection, backup or real user data is ever used.
 */
final class ProductionMysqlSchemaP0Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CANOVIA_P0_DISPOSABLE_MYSQL_CI') !== '1') {
            $this->markTestSkipped('Requires explicit isolated MySQL CI mode.');
        }

        // A fail-closed hard guard before any ALTER/drop operation.
        if (! app()->environment('testing')
            || config('database.default') !== 'mysql'
            || config('database.connections.mysql.host') !== '127.0.0.1'
            || config('database.connections.mysql.database') !== 'canovia_p0_ci'
            || config('database.connections.mysql.username') !== 'canovia_ci') {
            throw new \RuntimeException('P0 schema test refused: database is not the disposable CI database.');
        }
    }

    public function test_mysql_eight_has_safe_real_fk_names_and_full_migration_schema(): void
    {
        foreach ([
            ['intelligence_decision_traces', 'intelligence_state_snapshot_id', 'idt_snapshot_fk', 'intelligence_state_snapshots', 'cascade'],
            ['learning_answer_evaluation_adjustments', 'learning_answer_event_id', 'laea_answer_event_fk', 'learning_answer_events', 'cascade'],
            ['learning_answer_evaluation_adjustments', 'user_id', 'laea_user_fk', 'users', 'set null'],
        ] as [$table, $column, $name, $parent, $delete]) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertForeignKey($table, $column, $name, $parent, $delete);
        }

        $this->assertIndex(
            'learning_answer_evaluation_adjustments',
            ['learning_answer_event_id'],
            true,
        );
        $this->assertIndex(
            'intelligence_decision_traces',
            ['decision_reference'],
            true,
        );
    }

    public function test_learning_partial_mysql_table_repairs_missing_constraints_idempotently(): void
    {
        Schema::table('learning_answer_evaluation_adjustments', function (Blueprint $table): void {
            $table->dropForeign('laea_answer_event_fk');
            $table->dropForeign('laea_user_fk');
            $table->dropUnique('learning_eval_adjustment_event_unique');
        });

        $migration = require database_path(
            'migrations/2026_10_08_230000_create_learning_answer_evaluation_adjustments.php',
        );
        $migration->up();
        $migration->up();

        $this->assertForeignKey(
            'learning_answer_evaluation_adjustments',
            'learning_answer_event_id',
            'laea_answer_event_fk',
            'learning_answer_events',
            'cascade',
        );
        $this->assertForeignKey(
            'learning_answer_evaluation_adjustments',
            'user_id',
            'laea_user_fk',
            'users',
            'set null',
        );
        $this->assertIndex('learning_answer_evaluation_adjustments', ['learning_answer_event_id'], true);
    }

    public function test_decision_trace_partial_mysql_table_repairs_fk_and_index_idempotently(): void
    {
        Schema::table('intelligence_decision_traces', function (Blueprint $table): void {
            $table->dropForeign('idt_snapshot_fk');
            $table->dropIndex('intelligence_decision_scope_created_idx');
        });

        $migration = require database_path(
            'migrations/2026_10_04_000200_create_intelligence_decision_traces_table.php',
        );
        $migration->up();
        $migration->up();

        $this->assertForeignKey(
            'intelligence_decision_traces',
            'intelligence_state_snapshot_id',
            'idt_snapshot_fk',
            'intelligence_state_snapshots',
            'cascade',
        );
        $this->assertIndex('intelligence_decision_traces', ['domain', 'scope_type', 'scope_id', 'created_at'], false);
    }

    public function test_forward_only_migration_repairs_applied_ledger_drift_without_losing_existing_user(): void
    {
        $historical = [
            '2026_10_04_000200_create_intelligence_decision_traces_table',
            '2026_10_08_230000_create_learning_answer_evaluation_adjustments',
        ];

        foreach ($historical as $name) {
            $this->assertDatabaseHas('migrations', ['migration' => $name]);
        }

        // A pre-existing user row is a sentinel for accidental table resets
        // during forward-only constraint repair. Never use real user data.
        $user = User::factory()->create();
        $before = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotNull($before);

        // Also preserve a *row in the repaired table itself*, not merely an
        // unrelated account. Both rows are synthetic throwaway CI records.
        $now = now();
        $fingerprint = str_repeat('a', 64);
        $snapshotId = DB::table('intelligence_state_snapshots')->insertGetId([
            'user_id' => $user->id,
            'domain' => 'development',
            'scope_type' => 'test',
            'state_fingerprint' => $fingerprint,
            'state_reference' => 'p0-ci-state-reference',
            'captured_at' => $now,
            'metrics' => '{}',
            'facts' => '{}',
            'evidence_references' => '[]',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $traceId = DB::table('intelligence_decision_traces')->insertGetId([
            'user_id' => $user->id,
            'intelligence_state_snapshot_id' => $snapshotId,
            'domain' => 'development',
            'scope_type' => 'test',
            'state_reference' => 'p0-ci-trace-state',
            'state_fingerprint' => $fingerprint,
            'readiness_fingerprint' => str_repeat('b', 64),
            'readiness_level' => 'ready',
            'readiness_confidence' => 0.5,
            'readiness_components' => '{}',
            'readiness_gaps' => '[]',
            'decision_reference' => 'p0-ci-decision-trace',
            'decision_type' => 'recommend',
            'reason_code' => 'ci_only',
            'decision_summary' => 'Disposable database row preservation probe',
            'decision_confidence' => 0.5,
            'input_fingerprint' => str_repeat('c', 64),
            'decision_reasons' => '[]',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $beforeTrace = DB::table('intelligence_decision_traces')->where('id', $traceId)->first();
        $this->assertNotNull($beforeTrace);

        Schema::table('intelligence_decision_traces', function (Blueprint $table): void {
            $table->dropForeign('idt_snapshot_fk');
            $table->dropIndex('intelligence_decision_scope_created_idx');
        });
        Schema::table('learning_answer_evaluation_adjustments', function (Blueprint $table): void {
            $table->dropForeign('laea_answer_event_fk');
            $table->dropUnique('learning_eval_adjustment_event_unique');
        });

        // The ledger already says the old migrations ran. Normal "migrate"
        // would skip them, so a NEW pending forward-only migration is needed.
        $migration = require database_path(
            'migrations/2026_10_09_235959_reconcile_p0_mysql_applied_constraints.php',
        );
        $migration->up();
        $migration->up();

        $this->assertForeignKey(
            'intelligence_decision_traces',
            'intelligence_state_snapshot_id',
            'idt_snapshot_fk',
            'intelligence_state_snapshots',
            'cascade',
        );
        $this->assertIndex(
            'intelligence_decision_traces',
            ['domain', 'scope_type', 'scope_id', 'created_at'],
            false,
        );
        $this->assertForeignKey(
            'learning_answer_evaluation_adjustments',
            'learning_answer_event_id',
            'laea_answer_event_fk',
            'learning_answer_events',
            'cascade',
        );
        $this->assertIndex('learning_answer_evaluation_adjustments', ['learning_answer_event_id'], true);

        $afterTrace = DB::table('intelligence_decision_traces')->where('id', $traceId)->first();
        $this->assertNotNull($afterTrace);
        $this->assertSame($beforeTrace->decision_reference, $afterTrace->decision_reference);
        $this->assertSame($beforeTrace->intelligence_state_snapshot_id, $afterTrace->intelligence_state_snapshot_id);
        $this->assertSame($beforeTrace->decision_summary, $afterTrace->decision_summary);

        $after = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotNull($after);
        $this->assertSame($before->email, $after->email);
        $this->assertSame($before->password, $after->password);
        foreach ($historical as $name) {
            $this->assertDatabaseHas('migrations', ['migration' => $name]);
        }
    }

    public function test_forward_only_migration_blocks_inconsistent_history_before_any_ddl(): void
    {
        $name = '2026_10_08_230000_create_learning_answer_evaluation_adjustments';
        $row = DB::table('migrations')->where('migration', $name)->first();
        $this->assertNotNull($row);

        $migration = require database_path(
            'migrations/2026_10_09_235959_reconcile_p0_mysql_applied_constraints.php',
        );

        try {
            DB::table('migrations')->where('migration', $name)->delete();

            try {
                $migration->up();
                $this->fail('Forward-only repair must not run with missing historical ledger.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'P0 recovery blocked: expected table or migration history missing.',
                    $exception->getMessage(),
                );
            }
        } finally {
            // CI-only synthetic ledger mutation is always rolled back manually.
            DB::table('migrations')->insert([
                'migration' => $name,
                'batch' => $row->batch,
            ]);
        }

        $this->assertDatabaseHas('migrations', ['migration' => $name]);
    }

    public function test_reconciliation_blocks_second_table_orphans_before_first_table_ddl(): void
    {
        // A failed ADD FOREIGN KEY in the second table previously occurred
        // after the first table might already have been modified.
        Schema::table('intelligence_decision_traces', function (Blueprint $table): void {
            $table->dropForeign('idt_snapshot_fk');
        });
        Schema::table('learning_answer_evaluation_adjustments', function (Blueprint $table): void {
            $table->dropForeign('laea_answer_event_fk');
        });

        // A deliberately orphaned FK reference is CI-only synthetic data.
        $id = DB::table('learning_answer_evaluation_adjustments')->insertGetId([
            'learning_answer_event_id' => 987654321,
            'reason' => 'ci_preflight',
            'effect' => 'exclude_from_recommendations',
            'created_at' => now(),
        ]);

        $migration = require database_path(
            'migrations/2026_10_09_235959_reconcile_p0_mysql_applied_constraints.php',
        );

        try {
            try {
                $migration->up();
                $this->fail('Preflight must block an orphan before the first DDL.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'P0 recovery blocked: orphaned foreign-key references.',
                    $exception->getMessage(),
                );
            }

            // If the preflight were to repair the first table first, the FK
            // would already exist here despite the later orphan exception.
            $this->assertSame([], array_values(array_filter(
                Schema::getForeignKeys('intelligence_decision_traces'),
                fn (array $fk): bool => ($fk['name'] ?? '') === 'idt_snapshot_fk',
            )));
        } finally {
            DB::table('learning_answer_evaluation_adjustments')->where('id', $id)->delete();
            $migration->up();
        }

        $this->assertForeignKey(
            'intelligence_decision_traces',
            'intelligence_state_snapshot_id',
            'idt_snapshot_fk',
            'intelligence_state_snapshots',
            'cascade',
        );
        $this->assertForeignKey(
            'learning_answer_evaluation_adjustments',
            'learning_answer_event_id',
            'laea_answer_event_fk',
            'learning_answer_events',
            'cascade',
        );
    }

    public function test_reconciliation_blocks_incomplete_second_table_before_first_table_ddl(): void
    {
        Schema::table('intelligence_decision_traces', function (Blueprint $table): void {
            $table->dropForeign('idt_snapshot_fk');
        });
        Schema::table('learning_answer_evaluation_adjustments', function (Blueprint $table): void {
            $table->dropColumn('effect');
        });

        $migration = require database_path(
            'migrations/2026_10_09_235959_reconcile_p0_mysql_applied_constraints.php',
        );
        try {
            try {
                $migration->up();
                $this->fail('Incomplete second table must fail before first-table DDL.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'P0 recovery blocked: incomplete table contract.',
                    $exception->getMessage(),
                );
            }

            $this->assertSame([], array_values(array_filter(
                Schema::getForeignKeys('intelligence_decision_traces'),
                fn (array $fk): bool => ($fk['name'] ?? '') === 'idt_snapshot_fk',
            )));
        } finally {
            Schema::table('learning_answer_evaluation_adjustments', function (Blueprint $table): void {
                $table->string('effect', 32)->default('exclude_from_recommendations');
            });
            $migration->up();
        }

        $this->assertForeignKey(
            'intelligence_decision_traces',
            'intelligence_state_snapshot_id',
            'idt_snapshot_fk',
            'intelligence_state_snapshots',
            'cascade',
        );
    }

    public function test_reconciliation_blocks_schema_wide_fk_name_collision_before_other_table_ddl(): void
    {
        // Constraint symbols are MySQL schema-wide. A name on another table
        // can stop a later repair after the first table's DDL.
        Schema::table('intelligence_decision_traces', function (Blueprint $table): void {
            $table->dropForeign('idt_snapshot_fk');
        });
        Schema::table('learning_answer_evaluation_adjustments', function (Blueprint $table): void {
            $table->dropForeign('laea_answer_event_fk');
        });

        $probe = 'p0_ci_fk_collision_probe';
        Schema::create($probe, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('learning_answer_event_id')->nullable();
            $table->foreign('learning_answer_event_id', 'laea_answer_event_fk')
                ->references('id')->on('learning_answer_events');
        });

        $migration = require database_path(
            'migrations/2026_10_09_235959_reconcile_p0_mysql_applied_constraints.php',
        );
        try {
            try {
                $migration->up();
                $this->fail('Database-wide collision must block before first-table DDL.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'P0 recovery blocked: database-wide foreign key name collision.',
                    $exception->getMessage(),
                );
            }
            $this->assertSame([], array_values(array_filter(
                Schema::getForeignKeys('intelligence_decision_traces'),
                fn (array $fk): bool => ($fk['name'] ?? '') === 'idt_snapshot_fk',
            )));
        } finally {
            Schema::dropIfExists($probe);
            $migration->up();
        }

        $this->assertForeignKey(
            'intelligence_decision_traces',
            'intelligence_state_snapshot_id',
            'idt_snapshot_fk',
            'intelligence_state_snapshots',
            'cascade',
        );
        $this->assertForeignKey(
            'learning_answer_evaluation_adjustments',
            'learning_answer_event_id',
            'laea_answer_event_fk',
            'learning_answer_events',
            'cascade',
        );
    }

    public function test_reconciliation_blocks_second_table_index_name_collision_before_first_ddl(): void
    {
        Schema::table('intelligence_decision_traces', function (Blueprint $table): void {
            $table->dropForeign('idt_snapshot_fk');
        });
        Schema::table('learning_answer_evaluation_adjustments', function (Blueprint $table): void {
            // InnoDB may use this UNIQUE index to enforce the event FK;
            // remove the CI-only FK first before creating an index collision.
            $table->dropForeign('laea_answer_event_fk');
            $table->dropUnique('learning_eval_adjustment_event_unique');
            $table->index('reason', 'learning_eval_adjustment_event_unique');
        });

        $migration = require database_path(
            'migrations/2026_10_09_235959_reconcile_p0_mysql_applied_constraints.php',
        );
        try {
            try {
                $migration->up();
                $this->fail('Index collision must block before first-table DDL.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'P0 recovery blocked: index name collision.',
                    $exception->getMessage(),
                );
            }
            $this->assertSame([], array_values(array_filter(
                Schema::getForeignKeys('intelligence_decision_traces'),
                fn (array $fk): bool => ($fk['name'] ?? '') === 'idt_snapshot_fk',
            )));
        } finally {
            Schema::table('learning_answer_evaluation_adjustments', function (Blueprint $table): void {
                $table->dropIndex('learning_eval_adjustment_event_unique');
            });
            $migration->up();
        }

        $this->assertForeignKey(
            'intelligence_decision_traces',
            'intelligence_state_snapshot_id',
            'idt_snapshot_fk',
            'intelligence_state_snapshots',
            'cascade',
        );
        $this->assertIndex('learning_answer_evaluation_adjustments', ['learning_answer_event_id'], true);
    }

    public function test_reconciliation_blocks_duplicate_unique_keys_before_restoring_missing_fk(): void
    {
        $user = User::factory()->create();
        $now = now();
        $snapshot = DB::table('intelligence_state_snapshots')->insertGetId([
            'user_id' => $user->id,
            'domain' => 'development',
            'scope_type' => 'test',
            'state_fingerprint' => str_repeat('a', 64),
            'state_reference' => 'p0-ci-unique-preflight-snapshot',
            'captured_at' => $now,
            'metrics' => '{}',
            'facts' => '{}',
            'evidence_references' => '[]',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $decisionReference = 'p0-ci-duplicate-decision-reference';
        Schema::table('intelligence_decision_traces', function (Blueprint $table): void {
            $table->dropForeign('idt_snapshot_fk');
            $table->dropUnique('intelligence_decision_traces_decision_reference_unique');
        });

        $row = [
            'user_id' => $user->id,
            'intelligence_state_snapshot_id' => $snapshot,
            'domain' => 'development',
            'scope_type' => 'test',
            'state_reference' => 'p0-ci-duplicate-state',
            'state_fingerprint' => str_repeat('b', 64),
            'readiness_fingerprint' => str_repeat('c', 64),
            'readiness_level' => 'ready',
            'readiness_confidence' => 0.5,
            'readiness_components' => '{}',
            'readiness_gaps' => '[]',
            'decision_reference' => $decisionReference,
            'decision_type' => 'recommend',
            'reason_code' => 'ci_only',
            'decision_summary' => 'Duplicate-key preflight on CI-only synthetic records',
            'decision_confidence' => 0.5,
            'input_fingerprint' => str_repeat('d', 64),
            'decision_reasons' => '[]',
            'created_at' => $now,
            'updated_at' => $now,
        ];
        DB::table('intelligence_decision_traces')->insert([$row, $row]);

        $migration = require database_path(
            'migrations/2026_10_09_235959_reconcile_p0_mysql_applied_constraints.php',
        );
        try {
            try {
                $migration->up();
                $this->fail('Duplicate values must block before any repair DDL.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'P0 recovery blocked: duplicate values for unique index.',
                    $exception->getMessage(),
                );
            }

            // Even the missing FK in this SAME table must not be repaired
            // before duplicate unique-key values are rejected.
            $this->assertSame([], array_values(array_filter(
                Schema::getForeignKeys('intelligence_decision_traces'),
                fn (array $fk): bool => ($fk['name'] ?? '') === 'idt_snapshot_fk',
            )));
            $this->assertSame(2, DB::table('intelligence_decision_traces')
                ->where('decision_reference', $decisionReference)->count());
        } finally {
            // Clean ONLY the CI-inserted test records and restore the reviewed
            // original FK and UNIQUE index via the forward-only migration.
            DB::table('intelligence_decision_traces')
                ->where('decision_reference', $decisionReference)->delete();
            $migration->up();
            DB::table('intelligence_state_snapshots')->where('id', $snapshot)->delete();
            $user->delete();
        }

        $this->assertIndex('intelligence_decision_traces', ['decision_reference'], true);
        $this->assertForeignKey(
            'intelligence_decision_traces',
            'intelligence_state_snapshot_id',
            'idt_snapshot_fk',
            'intelligence_state_snapshots',
            'cascade',
        );
    }

    public function test_preflight_rejects_second_table_signed_fk_before_first_table_ddl(): void
    {
        // MySQL 8 will reject FK ADD when parent id is UNSIGNED but the
        // child reference is signed. Only touch synthetic disposable CI DB.
        Schema::table('intelligence_decision_traces', function (Blueprint $table): void {
            $table->dropForeign('idt_snapshot_fk');
        });
        Schema::table('learning_answer_evaluation_adjustments', function (Blueprint $table): void {
            $table->dropForeign('laea_user_fk');
            $table->bigInteger('user_id')->nullable()->change();
        });

        $migration = require database_path(
            'migrations/2026_10_09_235959_reconcile_p0_mysql_applied_constraints.php',
        );
        try {
            try {
                $migration->up();
                $this->fail('Signed child referencing unsigned parent must fail before DDL.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'P0 recovery blocked: incompatible foreign-key column types.',
                    $exception->getMessage(),
                );
            }

            $this->assertSame([], array_values(array_filter(
                Schema::getForeignKeys('intelligence_decision_traces'),
                fn (array $fk): bool => ($fk['name'] ?? '') === 'idt_snapshot_fk',
            )));
        } finally {
            Schema::table('learning_answer_evaluation_adjustments', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });
            $migration->up();
        }
        $this->assertForeignKey(
            'intelligence_decision_traces',
            'intelligence_state_snapshot_id',
            'idt_snapshot_fk',
            'intelligence_state_snapshots',
            'cascade',
        );
        $this->assertForeignKey(
            'learning_answer_evaluation_adjustments',
            'user_id',
            'laea_user_fk',
            'users',
            'set null',
        );
    }

    public function test_preflight_rejects_nonnullable_set_null_fk_before_first_table_ddl(): void
    {
        Schema::table('intelligence_decision_traces', function (Blueprint $table): void {
            $table->dropForeign('idt_snapshot_fk');
        });
        Schema::table('learning_answer_evaluation_adjustments', function (Blueprint $table): void {
            $table->dropForeign('laea_user_fk');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        $migration = require database_path(
            'migrations/2026_10_09_235959_reconcile_p0_mysql_applied_constraints.php',
        );
        try {
            try {
                $migration->up();
                $this->fail('SET NULL on a NOT NULL column must fail before DDL.');
            } catch (\RuntimeException $exception) {
                $this->assertSame(
                    'P0 recovery blocked: SET NULL foreign key column is not nullable.',
                    $exception->getMessage(),
                );
            }

            $this->assertSame([], array_values(array_filter(
                Schema::getForeignKeys('intelligence_decision_traces'),
                fn (array $fk): bool => ($fk['name'] ?? '') === 'idt_snapshot_fk',
            )));
        } finally {
            Schema::table('learning_answer_evaluation_adjustments', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });
            $migration->up();
        }
        $this->assertForeignKey(
            'intelligence_decision_traces',
            'intelligence_state_snapshot_id',
            'idt_snapshot_fk',
            'intelligence_state_snapshots',
            'cascade',
        );
        $this->assertForeignKey(
            'learning_answer_evaluation_adjustments',
            'user_id',
            'laea_user_fk',
            'users',
            'set null',
        );
    }

    private function assertForeignKey(
        string $table,
        string $column,
        string $name,
        string $parent,
        string $delete,
    ): void {
        $matches = array_values(array_filter(
            Schema::getForeignKeys($table),
            fn (array $key): bool => array_values($key['columns'] ?? []) === [$column],
        ));
        $this->assertCount(1, $matches, $table.'.'.$column);
        $this->assertSame($name, $matches[0]['name']);
        $this->assertSame($parent, $matches[0]['foreign_table']);
        $this->assertSame(['id'], array_values($matches[0]['foreign_columns'] ?? []));
        $this->assertSame($delete, strtolower((string) ($matches[0]['on_delete'] ?? '')));
        $this->assertLessThanOrEqual(64, strlen($matches[0]['name']));
    }

    /** @param array<int,string> $columns */
    private function assertIndex(string $table, array $columns, bool $unique): void
    {
        $indexes = array_values(array_filter(
            Schema::getIndexes($table),
            fn (array $index): bool => array_values($index['columns'] ?? []) === $columns
                && (bool) ($index['unique'] ?? false) === $unique,
        ));
        $this->assertNotEmpty($indexes, $table.':'.implode(',', $columns));
    }
}
