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
            $table->unsignedInteger('version')->default(1);
            $table->json('domains')->nullable();
            $table->json('common_context')->nullable();
            $table->json('domain_context')->nullable();
            $table->string('guidance_level', 24)->nullable();
            $table->json('recommended_surfaces')->nullable();
            $table->json('feature_readiness')->nullable();
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
