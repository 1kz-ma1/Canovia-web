<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('learning_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_token', 64)->nullable();
            $table->uuid('start_request_id')->unique();
            $table->foreignId('question_pack_id')->nullable()->constrained('question_packs')->nullOnDelete();
            $table->string('pack_title_snapshot', 180);
            $table->string('pack_version_snapshot', 40);
            $table->string('mode', 24);
            $table->string('status', 24)->default('active')->index();
            $table->unsignedInteger('current_ordinal')->default(1);
            $table->string('queue_policy_version', 32)->default('bank_locked_v1');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['plan_id', 'task_id', 'user_id', 'status'], 'learning_run_scope_idx');
        });
        Schema::create('learning_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learning_run_id')->constrained('learning_runs')->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('questions')->nullOnDelete();
            $table->unsignedInteger('ordinal');
            $table->json('question_snapshot');
            $table->json('grading_rule_snapshot');
            $table->text('explanation_snapshot')->nullable();
            $table->timestamp('presented_at')->nullable();
            $table->timestamps();
            $table->unique(['learning_run_id', 'ordinal'], 'learning_item_ordinal_unique');
            $table->unique(['learning_run_id', 'question_id'], 'learning_item_question_unique');
        });
        Schema::create('learning_answer_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learning_run_item_id')->constrained('learning_run_items')->cascadeOnDelete();
            $table->uuid('request_id')->unique();
            $table->string('answer_value', 255);
            $table->boolean('was_correct');
            $table->string('grading_method', 48)->default('question_bank_exact_choice');
            $table->timestamp('answered_at');
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->timestamp('explanation_seen_at')->nullable();
            $table->string('evaluation_contribution', 32)->nullable();
            $table->string('evaluation_confidence', 32)->nullable();
            $table->timestamps();
            $table->unique('learning_run_item_id', 'learning_answer_item_unique');
        });
    }
    public function down(): void {
        Schema::dropIfExists('learning_answer_events');
        Schema::dropIfExists('learning_run_items');
        Schema::dropIfExists('learning_runs');
    }
};