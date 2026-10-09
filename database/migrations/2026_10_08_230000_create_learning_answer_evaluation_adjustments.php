<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'learning_answer_evaluation_adjustments';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            // MySQL can retain a table after a later FK/unique statement failed.
            // Never silently mark an incomplete table as recovered.
            $expected = [
                'id', 'learning_answer_event_id', 'user_id', 'actor_token',
                'reason', 'effect', 'created_at',
            ];
            $missing = array_diff($expected, Schema::getColumnListing(self::TABLE));
            if ($missing !== []) {
                throw new RuntimeException('Existing learning adjustment schema is incomplete.');
            }

            $this->ensureForeignKey(
                'learning_answer_event_id', 'learning_answer_events',
                'laea_answer_event_fk', 'cascade',
            );
            $this->ensureForeignKey(
                'user_id', 'users', 'laea_user_fk', 'set null',
            );
            $this->ensureUnique(['learning_answer_event_id'], 'learning_eval_adjustment_event_unique');

            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learning_answer_event_id');
            $table->foreign('learning_answer_event_id', 'laea_answer_event_fk')
                ->references('id')->on('learning_answer_events')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->foreign('user_id', 'laea_user_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->string('actor_token', 64)->nullable();
            $table->string('reason', 32);
            $table->string('effect', 32)->default('exclude_from_recommendations');
            $table->timestamp('created_at');
            $table->unique('learning_answer_event_id', 'learning_eval_adjustment_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function ensureForeignKey(
        string $column,
        string $referencedTable,
        string $name,
        string $onDelete,
    ): void {
        foreach (Schema::getForeignKeys(self::TABLE) as $foreignKey) {
            if (array_values($foreignKey['columns'] ?? []) !== [$column]) {
                continue;
            }

            if (($foreignKey['foreign_table'] ?? '') !== $referencedTable
                || array_values($foreignKey['foreign_columns'] ?? []) !== ['id']
                || strtolower((string) ($foreignKey['on_delete'] ?? '')) !== $onDelete) {
                throw new RuntimeException('Existing learning adjustment foreign key is incompatible: '.$column);
            }

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use (
            $column, $referencedTable, $name, $onDelete,
        ): void {
            $foreign = $table->foreign($column, $name)->references('id')->on($referencedTable);
            if ($onDelete === 'cascade') {
                $foreign->cascadeOnDelete();
            } else {
                $foreign->nullOnDelete();
            }
        });
    }

    /** @param array<int,string> $columns */
    private function ensureUnique(array $columns, string $name): void
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (array_values($index['columns'] ?? []) === $columns) {
                if (! ($index['unique'] ?? false)) {
                    // A same-column nonunique index is insufficient; unique may
                    // still be safely added, unless its name is already taken.
                    break;
                }

                return;
            }
        }

        Schema::table(self::TABLE, fn (Blueprint $table) => $table->unique($columns, $name));
    }
};
