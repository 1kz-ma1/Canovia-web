<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Forward-only repair of already-applied migrations after interrupted DDL.
     *
     * MySQL DDL is not transactional. Preflight BOTH target contracts and any
     * existing rows that would violate a missing FK/unique constraint BEFORE
     * making the first alteration. This reduces partial repairs, but cannot
     * guarantee atomicity or protect against concurrent production writes.
     */
    public function up(): void
    {
        $this->preflight();

        foreach ([
            '2026_10_04_000200_create_intelligence_decision_traces_table',
            '2026_10_08_230000_create_learning_answer_evaluation_adjustments',
        ] as $migrationName) {
            $migration = require database_path('migrations/'.$migrationName.'.php');
            $migration->up();
        }
    }

    /**
     * Perform SELECT-only validation of both target contracts with NO DDL.
     * Public solely to support an operator-controlled read-only diagnostic.
     * A passing preflight never grants authorization to run up().
     */
    public function preflight(): void
    {
        $contracts = [
            [
                'intelligence_decision_traces',
                '2026_10_04_000200_create_intelligence_decision_traces_table',
                [
                    'id', 'user_id', 'plan_id', 'intelligence_state_snapshot_id',
                    'domain', 'scope_type', 'scope_id', 'state_reference',
                    'state_fingerprint', 'readiness_fingerprint',
                    'readiness_score', 'readiness_level', 'readiness_confidence',
                    'readiness_components', 'readiness_gaps',
                    'readiness_metadata', 'decision_reference', 'decision_type',
                    'reason_code', 'decision_summary', 'decision_confidence',
                    'input_fingerprint', 'decision_reasons',
                    'decision_metadata', 'metadata', 'created_at', 'updated_at',
                ],
                [
                    ['user_id', 'users', 'idt_user_fk', 'set null'],
                    ['plan_id', 'plans', 'idt_plan_fk', 'set null'],
                    ['intelligence_state_snapshot_id', 'intelligence_state_snapshots', 'idt_snapshot_fk', 'cascade'],
                ],
                [
                    [['domain'], 'intelligence_decision_traces_domain_index', false],
                    [['state_reference'], 'intelligence_decision_traces_state_reference_index', false],
                    [['state_fingerprint'], 'intelligence_decision_traces_state_fingerprint_index', false],
                    [['readiness_fingerprint'], 'intelligence_decision_traces_readiness_fingerprint_index', false],
                    [['decision_reference'], 'intelligence_decision_traces_decision_reference_unique', true],
                    [['decision_type'], 'intelligence_decision_traces_decision_type_index', false],
                    [['reason_code'], 'intelligence_decision_traces_reason_code_index', false],
                    [['input_fingerprint'], 'intelligence_decision_traces_input_fingerprint_index', false],
                    [['domain', 'scope_type', 'scope_id', 'created_at'], 'intelligence_decision_scope_created_idx', false],
                    [['plan_id', 'domain', 'created_at'], 'intelligence_decision_plan_domain_idx', false],
                ],
            ],
            [
                'learning_answer_evaluation_adjustments',
                '2026_10_08_230000_create_learning_answer_evaluation_adjustments',
                [
                    'id', 'learning_answer_event_id', 'user_id', 'actor_token',
                    'reason', 'effect', 'created_at',
                ],
                [
                    ['learning_answer_event_id', 'learning_answer_events', 'laea_answer_event_fk', 'cascade'],
                    ['user_id', 'users', 'laea_user_fk', 'set null'],
                ],
                [
                    [['learning_answer_event_id'], 'learning_eval_adjustment_event_unique', true],
                ],
            ],
        ];

        if (! Schema::hasTable('migrations')) {
            throw new RuntimeException('P0 recovery blocked: migration ledger missing.');
        }

        // Validate the entire repair surface in a read-only first pass.
        // Do not let a structural/data problem in table 2 appear after the
        // historical migration for table 1 has already made a DDL change.
        foreach ($contracts as [$table, $migrationName, $columns, $foreignKeys, $indexes]) {
            if (! Schema::hasTable($table)
                || ! DB::table('migrations')->where('migration', $migrationName)->exists()) {
                throw new RuntimeException('P0 recovery blocked: expected table or migration history missing.');
            }

            if (array_diff($columns, Schema::getColumnListing($table)) !== []) {
                throw new RuntimeException('P0 recovery blocked: incomplete table contract.');
            }

            $this->preflightForeignKeys($table, $foreignKeys);
            $this->preflightIndexes($table, $indexes);
        }

    }

    /**
     * @param array<int,array{string,string,string,string}> $definitions
     */
    private function preflightForeignKeys(string $table, array $definitions): void
    {
        $existingKeys = Schema::getForeignKeys($table);

        foreach ($definitions as [$column, $parent, $expectedName, $deleteRule]) {
            if (! Schema::hasTable($parent) || ! Schema::hasColumn($parent, 'id')) {
                throw new RuntimeException('P0 recovery blocked: missing referenced table or column.');
            }

            $matching = array_values(array_filter(
                $existingKeys,
                fn (array $key): bool => array_values($key['columns'] ?? []) === [$column],
            ));
            if (count($matching) > 1) {
                throw new RuntimeException('P0 recovery blocked: ambiguous foreign key.');
            }

            if ($matching !== []) {
                $found = $matching[0];
                if (($found['foreign_table'] ?? '') !== $parent
                    || array_values($found['foreign_columns'] ?? []) !== ['id']
                    || strtolower((string) ($found['on_delete'] ?? '')) !== $deleteRule) {
                    throw new RuntimeException('P0 recovery blocked: incompatible foreign key.');
                }

                continue;
            }

            // A new named FK cannot be added if the name is already in use.
            foreach ($existingKeys as $key) {
                if (($key['name'] ?? '') === $expectedName) {
                    throw new RuntimeException('P0 recovery blocked: foreign key name collision.');
                }
            }

            // InnoDB constraint symbols must be unique in the DATABASE,
            // not merely within this table. A same-name FK on a different
            // table can make the second ALTER fail after earlier repairs.
            // Check metadata only: never inspect or output customer rows.
            if (DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
                ->where('CONSTRAINT_NAME', $expectedName)
                ->exists()) {
                throw new RuntimeException('P0 recovery blocked: database-wide foreign key name collision.');
            }

            // InnoDB requires compatible child/parent column types,
            // including integer signedness. A nullable child is also
            // mandatory when this new FK uses ON DELETE SET NULL.
            // Verify metadata for both sides before the first table's DDL.
            $schemaName = DB::connection()->getDatabaseName();
            $childMetadata = DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', $schemaName)
                ->where('TABLE_NAME', $table)
                ->where('COLUMN_NAME', $column)
                ->first(['COLUMN_TYPE', 'IS_NULLABLE']);
            $parentMetadata = DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', $schemaName)
                ->where('TABLE_NAME', $parent)
                ->where('COLUMN_NAME', 'id')
                ->first(['COLUMN_TYPE']);
            if ($childMetadata === null
                || $parentMetadata === null
                || strtolower((string) $childMetadata->COLUMN_TYPE)
                    !== strtolower((string) $parentMetadata->COLUMN_TYPE)) {
                throw new RuntimeException('P0 recovery blocked: incompatible foreign-key column types.');
            }

            if ($deleteRule === 'set null'
                && strtoupper((string) $childMetadata->IS_NULLABLE) !== 'YES') {
                throw new RuntimeException('P0 recovery blocked: SET NULL foreign key column is not nullable.');
            }

            // Orphaned rows make an ADD FOREIGN KEY fail after earlier DDL.
            // Check existence only: no user identifiers or row contents leave DB.
            if (DB::table($table.' as child')
                ->leftJoin($parent.' as parent', 'child.'.$column, '=', 'parent.id')
                ->whereNotNull('child.'.$column)
                ->whereNull('parent.id')
                ->exists()) {
                throw new RuntimeException('P0 recovery blocked: orphaned foreign-key references.');
            }
        }
    }

    /**
     * @param array<int,array{array<int,string>,string,bool}> $definitions
     */
    private function preflightIndexes(string $table, array $definitions): void
    {
        $existing = Schema::getIndexes($table);

        foreach ($definitions as [$columns, $expectedName, $unique]) {
            $sameColumns = array_values(array_filter(
                $existing,
                fn (array $idx): bool => array_values($idx['columns'] ?? []) === $columns,
            ));
            $satisfies = array_filter(
                $sameColumns,
                fn (array $idx): bool => (bool) ($idx['unique'] ?? false) === $unique,
            );
            if ($satisfies !== []) {
                continue;
            }

            // The historical migration refuses a unique index in place of a
            // required nonunique lookup index, so block it before *any* DDL.
            if (! $unique && $sameColumns !== []) {
                throw new RuntimeException('P0 recovery blocked: incompatible index.');
            }

            foreach ($existing as $idx) {
                if (($idx['name'] ?? '') === $expectedName) {
                    throw new RuntimeException('P0 recovery blocked: index name collision.');
                }
            }

            // The missing unique constraint cannot be safely added if rows
            // already share the same key. Avoid issuing the DDL at all.
            if ($unique && DB::table($table)
                ->select($columns)
                ->groupBy($columns)
                ->havingRaw('COUNT(*) > 1')
                ->exists()) {
                throw new RuntimeException('P0 recovery blocked: duplicate values for unique index.');
            }
        }
    }

    public function down(): void
    {
        // Forward-only reconciliation. Never drop repaired constraints during rollback.
    }
};
