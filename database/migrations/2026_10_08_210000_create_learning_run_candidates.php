<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('learning_runs', function (Blueprint $table): void {
            $table->unsignedInteger('candidate_generation')->default(0);
        });
        Schema::create('learning_run_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learning_run_id')->constrained('learning_runs')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->unsignedInteger('generation');
            $table->unsignedSmallInteger('position');
            $table->string('reason', 48);
            $table->timestamps();
            $table->unique(['learning_run_id','question_id'], 'learning_candidate_question_unique');
            $table->unique(['learning_run_id','position'], 'learning_candidate_position_unique');
        });
    }
    public function down(): void {
        Schema::dropIfExists('learning_run_candidates');
        Schema::table('learning_runs', function (Blueprint $table): void {
            $table->dropColumn('candidate_generation');
        });
    }
};