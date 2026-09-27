<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guided_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_token', 191)->nullable()->index();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_evidence_id')->nullable()->constrained('task_evidences')->nullOnDelete();
            $table->string('status', 24)->default('prepared');
            $table->uuid('prepare_request_id')->nullable()->unique();
            $table->uuid('reflection_request_id')->nullable()->unique();
            $table->text('intent');
            $table->json('focus_points')->nullable();
            $table->json('observation_points')->nullable();
            $table->text('success_signal')->nullable();
            $table->string('outcome_rating', 32)->nullable();
            $table->text('actual_outcome')->nullable();
            $table->text('observations')->nullable();
            $table->text('discoveries')->nullable();
            $table->text('next_adjustment')->nullable();
            $table->timestamp('prepared_at');
            $table->timestamp('reflected_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'status', 'created_at']);
            $table->index(['plan_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guided_executions');
    }
};
