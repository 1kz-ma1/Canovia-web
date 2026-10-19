<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('learning_context_source_epochs')) {
            $required = ['id', 'plan_id', 'epoch', 'last_mutation_kind', 'created_at', 'updated_at'];
            if (array_diff($required, Schema::getColumnListing('learning_context_source_epochs')) !== []) {
                throw new RuntimeException('Incomplete Learning source epoch table; manual schema review required.');
            }

            // MySQL CREATE TABLE can commit before Laravel's separate FK and
            // unique-index statements. Columns alone cannot prove a completed
            // migration: refuse to log a partial table as successfully applied.
            $indexes = Schema::getIndexes('learning_context_source_epochs');
            $uniquePlan = count($indexes) === 2 && collect($indexes)->contains(fn (array $index): bool =>
                $index['unique'] && ! $index['primary'] && $index['columns'] === ['plan_id']);
            $primary = collect($indexes)->contains(fn (array $index): bool =>
                $index['primary'] && $index['columns'] === ['id']);
            $foreignKeys = Schema::getForeignKeys('learning_context_source_epochs');
            $foreignPlan = count($foreignKeys) === 1 && collect($foreignKeys)
                ->contains(fn (array $key): bool => $key['columns'] === ['plan_id']
                    && $key['foreign_table'] === 'plans' && $key['foreign_columns'] === ['id']
                    && strtolower($key['on_delete']) === 'cascade'
                    && in_array(strtolower($key['on_update']), ['restrict', 'no action'], true)
                    && (Schema::getConnection()->getDriverName() !== 'mysql'
                        || $key['foreign_schema'] === Schema::getConnection()->getDatabaseName()));
            if (! $uniquePlan || ! $primary || ! $foreignPlan
                || (Schema::getConnection()->getDriverName() === 'mysql' && ! $this->exactMysqlShape())) {
                throw new RuntimeException('Incomplete Learning source epoch constraints; manual forward recovery required.');
            }

            return; // Replaying MySQL DDL never erases an existing source epoch.
        }

        Schema::create('learning_context_source_epochs', function (Blueprint $table): void {
            $table->id();
            // unique() belongs to the column; constrained() returns a foreign
            // key definition and would silently discard a later unique().
            $table->foreignId('plan_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('epoch')->default(0);
            // Only non-sensitive source-kind codes; no raw Task title,
            // evidence, practice question, score, owner identity or payload.
            $table->string('last_mutation_kind', 32);
            $table->timestamps();
        });
    }

    /** Read-only, source-defined shape check; never repairs or selects a DROP. */
    private function exactMysqlShape(): bool
    {
        $connection = Schema::getConnection();
        $scope = fn (string $view) => $connection->table('information_schema.'.$view)
            ->where('TABLE_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', 'learning_context_source_epochs');
        $table = $scope('TABLES')->first();
        if ($table === null || $table->ENGINE !== 'InnoDB'
            || $table->TABLE_COLLATION !== $connection->getConfig('collation')
            || $scope('TABLE_CONSTRAINTS')->where('CONSTRAINT_TYPE', 'CHECK')->exists()
            || $connection->table('information_schema.TRIGGERS')
                ->where('TRIGGER_SCHEMA', $connection->getDatabaseName())
                ->where('EVENT_OBJECT_TABLE', 'learning_context_source_epochs')->exists()) {
            return false;
        }
        // Invisible triggers remain a separate privileged operator gate.
        $foreign = $connection->table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', 'learning_context_source_epochs')->get();
        if ($foreign->count() !== 1 || $foreign->first()->MATCH_OPTION !== 'NONE') {
            return false;
        }
        $expected = [
            'id' => ['bigint unsigned', 'NO', null, 'auto_increment'],
            'plan_id' => ['bigint unsigned', 'NO', null, ''],
            'epoch' => ['bigint unsigned', 'NO', '0', ''],
            'last_mutation_kind' => ['varchar(32)', 'NO', null, ''],
            'created_at' => ['timestamp', 'YES', null, ''],
            'updated_at' => ['timestamp', 'YES', null, ''],
        ];
        $columns = $scope('COLUMNS')->orderBy('ORDINAL_POSITION')->get();
        if ($columns->pluck('COLUMN_NAME')->all() !== array_keys($expected)) {
            return false;
        }
        foreach ($columns as $column) {
            if ([$column->COLUMN_TYPE, $column->IS_NULLABLE, $column->COLUMN_DEFAULT, $column->EXTRA]
                !== $expected[$column->COLUMN_NAME] || $column->GENERATION_EXPRESSION !== '') {
                return false;
            }
            // This is the fixed migration's configured charset/collation,
            // not a normalization or waiver of the original physical audit.
            if ($column->COLUMN_NAME === 'last_mutation_kind'
                && ($column->CHARACTER_SET_NAME !== $connection->getConfig('charset')
                    || $column->COLLATION_NAME !== $connection->getConfig('collation'))) {
                return false;
            }
        }
        $parts = $scope('STATISTICS')->get();
        if ($parts->count() !== 2) {
            return false;
        }
        foreach ($parts as $part) {
            if ((int) $part->SEQ_IN_INDEX !== 1 || (int) $part->NON_UNIQUE !== 0
                || $part->SUB_PART !== null || $part->EXPRESSION !== null
                || $part->INDEX_TYPE !== 'BTREE' || $part->IS_VISIBLE !== 'YES' || $part->COLLATION !== 'A') {
                return false;
            }
        }
        return true;
    }

    public function down(): void
    {
        // No automatic down() invoked in a real release; rollback requires
        // explicit independent database backup/impact review.
        Schema::dropIfExists('learning_context_source_epochs');
    }
};
