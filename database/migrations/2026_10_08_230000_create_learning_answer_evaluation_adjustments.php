<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        // MySQL may commit CREATE TABLE before a later index operation fails.
        // Preserve existing answer adjustments when retrying a partial deploy.
        if (Schema::hasTable('learning_answer_evaluation_adjustments')) {
            return;
        }

        Schema::create('learning_answer_evaluation_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learning_answer_event_id')->constrained('learning_answer_events')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_token',64)->nullable();
            $table->string('reason',32);
            $table->string('effect',32)->default('exclude_from_recommendations');
            $table->timestamp('created_at');
            $table->unique('learning_answer_event_id','learning_eval_adjustment_event_unique');
        });
    }
    public function down(): void {
        Schema::dropIfExists('learning_answer_evaluation_adjustments');
    }
};