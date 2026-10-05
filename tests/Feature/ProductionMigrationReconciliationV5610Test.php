<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductionMigrationReconciliationV5610Test extends TestCase
{
    use RefreshDatabase;

    public function test_post_v53_migrations_accept_already_applied_schema(): void
    {
        foreach ($this->migrationPaths() as $path) {
            $migration = require database_path($path);
            $migration->up();
        }

        foreach ([
            'intelligence_decision_traces',
            'intelligence_reasoning_runs',
            'study_scope_captures',
            'study_scope_items',
            'intelligence_action_projections',
            'plan_execution_preferences',
            'execution_activities',
            'provider_connections',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected {$table} to exist.");
        }

        $this->assertTrue(
            Schema::hasColumn('github_webhook_deliveries', 'routing_targets'),
        );
        $this->assertTrue(
            Schema::hasColumn('users', 'workspace_mode_preference'),
        );
    }

    public function test_study_scope_migration_can_finish_a_partially_applied_two_table_migration(): void
    {
        Schema::drop('study_scope_items');

        $migration = require database_path(
            'migrations/2026_10_04_000400_create_study_scope_capture_tables.php',
        );

        $migration->up();

        $this->assertTrue(Schema::hasTable('study_scope_captures'));
        $this->assertTrue(Schema::hasTable('study_scope_items'));
    }

    /**
     * @return array<int,string>
     */
    private function migrationPaths(): array
    {
        return [
            'migrations/2026_10_04_000200_create_intelligence_decision_traces_table.php',
            'migrations/2026_10_04_000300_create_intelligence_reasoning_runs_table.php',
            'migrations/2026_10_04_000400_create_study_scope_capture_tables.php',
            'migrations/2026_10_04_000500_create_intelligence_action_projections_table.php',
            'migrations/2026_10_04_000600_add_routing_targets_to_github_webhook_deliveries_table.php',
            'migrations/2026_10_04_000700_add_workspace_mode_preference_to_users_table.php',
            'migrations/2026_10_05_000100_create_plan_execution_preferences_table.php',
            'migrations/2026_10_05_000200_create_execution_activities_table.php',
            'migrations/2026_10_05_000300_create_provider_connections_table.php',
        ];
    }
}
