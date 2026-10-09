<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
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
