<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_personalization_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            // Schema version and living-context revision are intentionally
            // separate. Initial Diagnosis is only the first hypothesis.
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('context_revision')->default(1);

            // Keep source provenance explicit so future observed/inferred
            // context never overwrites what the user actually reported.
            $table->json('self_reported_context')->nullable();
            $table->json('observed_context')->nullable();
            $table->json('inferred_context')->nullable();

            // Derived presentation / recommendation state. These can be
            // recalculated later from the three source contexts.
            $table->string('guidance_level', 24)->nullable();
            $table->json('recommended_surfaces')->nullable();
            $table->json('feature_readiness')->nullable();

            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_personalization_contexts');
    }
};
