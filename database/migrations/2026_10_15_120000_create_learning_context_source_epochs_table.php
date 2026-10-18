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
            $uniquePlan = collect($indexes)->contains(fn (array $index): bool =>
                $index['unique'] && $index['columns'] === ['plan_id']);
            $primary = collect($indexes)->contains(fn (array $index): bool =>
                $index['primary'] && $index['columns'] === ['id']);
            $foreignPlan = collect(Schema::getForeignKeys('learning_context_source_epochs'))
                ->contains(fn (array $key): bool => $key['columns'] === ['plan_id']
                    && $key['foreign_table'] === 'plans' && $key['foreign_columns'] === ['id']
                    && strtolower($key['on_delete']) === 'cascade');
            if (! $uniquePlan || ! $primary || ! $foreignPlan) {
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

    public function down(): void
    {
        // No automatic down() invoked in a real release; rollback requires
        // explicit independent database backup/impact review.
        Schema::dropIfExists('learning_context_source_epochs');
    }
};
