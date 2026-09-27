<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companion_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('active');
            $table->string('title')->nullable();
            $table->json('context_scope')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'last_message_at']);
            $table->index(['plan_id', 'status']);
        });

        Schema::create('companion_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('companion_thread_id')->constrained('companion_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('native_ai_run_id')->nullable()->constrained('native_ai_runs')->nullOnDelete();
            $table->uuid('request_id')->nullable()->unique();
            $table->string('role', 16);
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['companion_thread_id', 'id']);
        });

        Schema::create('companion_mutation_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('companion_thread_id')->constrained('companion_threads')->cascadeOnDelete();
            $table->foreignId('companion_message_id')->nullable()->constrained('companion_messages')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('candidate_key')->unique();
            $table->string('type', 48);
            $table->string('status', 24)->default('pending');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->json('payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['companion_thread_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companion_mutation_candidates');
        Schema::dropIfExists('companion_messages');
        Schema::dropIfExists('companion_threads');
    }
};
