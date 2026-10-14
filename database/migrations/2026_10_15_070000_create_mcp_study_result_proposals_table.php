<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safe to retry after a partial MySQL DDL deployment. Never drop
        // user-approved results merely because the deployment was retried.
        if (Schema::hasTable('mcp_study_result_proposals')) {
            $expected = [
                'id', 'user_id', 'plan_id', 'task_id', 'grant_id',
                'idempotency_key', 'payload_hash', 'question_count',
                'correct_count', 'weakness_topics', 'status',
                'expires_at', 'decided_at', 'created_at', 'updated_at',
            ];
            if (array_diff($expected, Schema::getColumnListing('mcp_study_result_proposals')) !== []) {
                throw new RuntimeException('MCP study result proposals table is incomplete.');
            }

            return;
        }

        Schema::create('mcp_study_result_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grant_id')->constrained('mcp_delegated_grants')->cascadeOnDelete();
            $table->char('idempotency_key', 64);
            $table->char('payload_hash', 64);
            $table->unsignedTinyInteger('question_count');
            $table->unsignedTinyInteger('correct_count');
            $table->json('weakness_topics');
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['grant_id', 'idempotency_key'], 'mcp_study_result_grant_idempotency_uq');
            $table->index(['user_id', 'status', 'created_at'], 'mcp_study_result_owner_pending_idx');
            $table->index(['plan_id', 'status', 'decided_at'], 'mcp_study_result_plan_approved_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_study_result_proposals');
    }
};
