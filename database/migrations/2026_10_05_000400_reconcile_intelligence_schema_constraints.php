<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('intelligence_reasoning_runs')) {
            foreach ([
                ['user_id', 'users', 'irr_user_fk', 'null'],
                ['plan_id', 'plans', 'irr_plan_fk', 'null'],
                ['intelligence_decision_trace_id', 'intelligence_decision_traces', 'irr_decision_trace_fk', 'null'],
                ['native_ai_run_id', 'native_ai_runs', 'irr_native_ai_run_fk', 'null'],
            ] as [$column, $foreignTable, $name, $onDelete]) {
                $this->ensureForeignKey(
                    'intelligence_reasoning_runs',
                    $column,
                    $foreignTable,
                    $name,
                    $onDelete,
                );
            }

            foreach ([
                [['domain'], 'irr_domain_idx', false],
                [['state_reference'], 'irr_state_ref_idx', false],
                [['readiness_fingerprint'], 'irr_readiness_fp_idx', false],
                [['input_fingerprint'], 'irr_input_fp_idx', false],
                [['request_fingerprint'], 'irr_request_fp_idx', false],
                [['route_selected'], 'irr_route_idx', false],
                [['provider'], 'irr_provider_idx', false],
                [['status'], 'irr_status_idx', false],
                [['plan_id', 'domain', 'created_at'], 'intelligence_reasoning_plan_domain_idx', false],
                [['domain', 'scope_type', 'scope_id', 'created_at'], 'intelligence_reasoning_scope_created_idx', false],
            ] as [$columns, $name, $unique]) {
                $this->ensureIndex(
                    'intelligence_reasoning_runs',
                    $columns,
                    $name,
                    $unique,
                );
            }
        }

        if (Schema::hasTable('intelligence_action_projections')) {
            foreach ([
                ['user_id', 'users', 'iap_user_fk', 'null'],
                ['plan_id', 'plans', 'iap_plan_fk', 'cascade'],
                ['intelligence_decision_trace_id', 'intelligence_decision_traces', 'iap_decision_trace_fk', 'null'],
                ['projected_task_id', 'tasks', 'iap_projected_task_fk', 'null'],
            ] as [$column, $foreignTable, $name, $onDelete]) {
                $this->ensureForeignKey(
                    'intelligence_action_projections',
                    $column,
                    $foreignTable,
                    $name,
                    $onDelete,
                );
            }

            foreach ([
                [['domain'], 'iap_domain_idx', false],
                [['state_fingerprint'], 'iap_state_fp_idx', false],
                [['action_fingerprint'], 'iap_action_fp_idx', false],
                [['action_reference'], 'iap_action_ref_unique', true],
                [['kind'], 'iap_kind_idx', false],
                [['status'], 'iap_status_idx', false],
                [['plan_id', 'domain', 'scope_type', 'scope_id', 'status'], 'intelligence_action_scope_status_idx', false],
                [['plan_id', 'domain', 'created_at'], 'intelligence_action_plan_domain_created_idx', false],
            ] as [$columns, $name, $unique]) {
                $this->ensureIndex(
                    'intelligence_action_projections',
                    $columns,
                    $name,
                    $unique,
                );
            }
        }
    }

    public function down(): void
    {
        // This migration only restores constraints/indexes that are part of
        // the historical table contract. Removing them during rollback would
        // make already-applied historical migrations inconsistent.
    }

    private function ensureForeignKey(
        string $table,
        string $column,
        string $foreignTable,
        string $name,
        string $onDelete,
    ): void {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (array_values($foreignKey['columns'] ?? []) === [$column]) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use (
            $column,
            $foreignTable,
            $name,
            $onDelete,
        ) {
            $foreign = $blueprint
                ->foreign($column, $name)
                ->references('id')
                ->on($foreignTable);

            if ($onDelete === 'cascade') {
                $foreign->cascadeOnDelete();
            } else {
                $foreign->nullOnDelete();
            }
        });
    }

    /**
     * @param array<int,string> $columns
     */
    private function ensureIndex(
        string $table,
        array $columns,
        string $name,
        bool $unique,
    ): void {
        foreach (Schema::getIndexes($table) as $index) {
            if (
                array_values($index['columns'] ?? []) === $columns
                && (! $unique || (bool) ($index['unique'] ?? false))
            ) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $blueprint) use (
            $columns,
            $name,
            $unique,
        ) {
            if ($unique) {
                $blueprint->unique($columns, $name);

                return;
            }

            $blueprint->index($columns, $name);
        });
    }
};
