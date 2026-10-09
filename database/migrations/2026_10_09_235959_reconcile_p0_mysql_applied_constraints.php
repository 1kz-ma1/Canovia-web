<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Forward-only P0 recovery for already-applied historical migrations.
     *
     * MySQL DDL is not transactional: prior deployments can record historical
     * migrations while leaving some FK/index DDL missing. Laravel will never
     * re-execute those completed migrations on a normal migrate invocation.
     *
     * Reuse the explicit, fail-closed historical table contracts rather than
     * guessing SQL from the production database or changing user data.
     */
    public function up(): void
    {
        $contracts = [
            [
                'intelligence_decision_traces',
                '2026_10_04_000200_create_intelligence_decision_traces_table',
            ],
            [
                'learning_answer_evaluation_adjustments',
                '2026_10_08_230000_create_learning_answer_evaluation_adjustments',
            ],
        ];

        if (! Schema::hasTable('migrations')) {
            throw new RuntimeException('P0 recovery blocked: migration ledger missing.');
        }

        // Fail BEFORE any DDL when required history / tables are inconsistent.
        foreach ($contracts as [$table, $migrationName]) {
            if (! Schema::hasTable($table)
                || ! DB::table('migrations')->where('migration', $migrationName)->exists()) {
                throw new RuntimeException('P0 recovery blocked: expected table or migration history missing.');
            }
        }

        foreach ($contracts as [, $migrationName]) {
            $migration = require database_path('migrations/'.$migrationName.'.php');
            $migration->up();
        }
    }

    public function down(): void
    {
        // Forward-only reconciliation. Deleting repaired foreign keys or
        // unique indexes during rollback would damage historical contracts.
    }
};
