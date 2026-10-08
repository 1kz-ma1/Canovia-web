<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('learning_runs', function (Blueprint $table): void {
            $table->string('exam_profile_key', 120)->nullable();
            $table->string('exam_profile_version', 40)->nullable();
            $table->json('exam_profile_snapshot')->nullable();
            $table->timestamp('exam_deadline_at')->nullable();
        });
        Schema::create('learning_exam_response_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learning_run_item_id')->constrained('learning_run_items')->cascadeOnDelete();
            $table->uuid('last_request_id')->unique();
            $table->string('answer_value', 255);
            $table->timestamp('answered_at');
            $table->timestamps();
            $table->unique('learning_run_item_id', 'learning_exam_draft_item_unique');
        });
    }
    public function down(): void {
        Schema::dropIfExists('learning_exam_response_drafts');
        Schema::table('learning_runs', function (Blueprint $table): void {
            $table->dropColumn(['exam_profile_key','exam_profile_version',
                'exam_profile_snapshot','exam_deadline_at']);
        });
    }
};