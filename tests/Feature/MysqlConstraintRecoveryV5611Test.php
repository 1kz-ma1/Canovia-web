<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MysqlConstraintRecoveryV5611Test extends TestCase
{
    use RefreshDatabase;

    public function test_constraint_reconciliation_is_idempotent_on_complete_schema(): void
    {
        $migration = require database_path(
            'migrations/2026_10_05_000400_reconcile_intelligence_schema_constraints.php',
        );

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasTable('intelligence_reasoning_runs'));
        $this->assertTrue(Schema::hasTable('intelligence_action_projections'));
    }

    public function test_explicit_mysql_constraint_names_stay_within_identifier_limit(): void
    {
        foreach ([
            'irr_decision_trace_fk',
            'irr_user_fk',
            'irr_plan_fk',
            'irr_native_ai_run_fk',
            'iap_decision_trace_fk',
            'iap_user_fk',
            'iap_plan_fk',
            'iap_projected_task_fk',
        ] as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), $name);
        }

        $reasoning = file_get_contents(database_path(
            'migrations/2026_10_04_000300_create_intelligence_reasoning_runs_table.php',
        ));
        $actions = file_get_contents(database_path(
            'migrations/2026_10_04_000500_create_intelligence_action_projections_table.php',
        ));

        $this->assertStringContainsString('irr_decision_trace_fk', (string) $reasoning);
        $this->assertStringContainsString('iap_decision_trace_fk', (string) $actions);
    }
}
