<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_token', 64)->nullable()->index();

            $table->string('provider_key', 191)->index();
            $table->string('capability', 191)->index();
            $table->string('external_key', 191)->nullable();
            $table->string('type', 64)->index();
            $table->string('title');
            $table->string('status', 32)->index();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('duration_seconds')->nullable();
            $table->json('metrics')->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_evidence_id')
                ->nullable()
                ->constrained('task_evidences')
                ->nullOnDelete();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider_key', 'external_key'],
                'execution_activity_provider_external_unique',
            );
            $table->index(
                ['plan_id', 'created_at'],
                'execution_activity_plan_created_idx',
            );
            $table->index(
                ['task_id', 'created_at'],
                'execution_activity_task_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_activities');
    }
};
