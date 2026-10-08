<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('plan_action_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->uuid('request_id');
            $table->foreignId('source_work_log_id')->nullable()->constrained('work_logs')->nullOnDelete();
            $table->string('source_kind', 24);
            $table->string('completed_action', 255);
            $table->text('observed_outcome');
            $table->string('suggested_next_action', 255);
            $table->string('status', 24)->default('proposed');
            $table->foreignId('accepted_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'request_id']);
            $table->index(['plan_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('plan_action_drafts'); }
};
