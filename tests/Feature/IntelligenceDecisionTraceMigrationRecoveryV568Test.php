<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntelligenceDecisionTraceMigrationRecoveryV568Test extends TestCase
{
    use RefreshDatabase;

    public function test_existing_complete_decision_trace_table_is_idempotently_accepted(): void
    {
        $migration = require database_path(
            'migrations/2026_10_04_000200_create_intelligence_decision_traces_table.php',
        );

        $migration->up();

        $this->assertTrue(Schema::hasTable('intelligence_decision_traces'));

        foreach ([
            'id',
            'user_id',
            'plan_id',
            'intelligence_state_snapshot_id',
            'domain',
            'state_reference',
            'readiness_fingerprint',
            'decision_reference',
            'input_fingerprint',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('intelligence_decision_traces', $column),
                "Expected intelligence_decision_traces.{$column} to exist.",
            );
        }
    }
}
