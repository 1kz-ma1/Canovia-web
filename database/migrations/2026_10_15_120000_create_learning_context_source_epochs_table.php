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

            return; // Replaying MySQL DDL never erases an existing source epoch.
        }

        Schema::create('learning_context_source_epochs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete()->unique();
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