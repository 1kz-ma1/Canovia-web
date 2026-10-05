<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_score_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_token', 64)->nullable()->index();
            $table->uuid('request_id')->unique();
            $table->string('metric_key', 64)->index();
            $table->string('metric_label', 120);
            $table->decimal('score_value', 10, 2);
            $table->decimal('scale_min', 10, 2)->nullable();
            $table->decimal('scale_max', 10, 2)->nullable();
            $table->string('unit', 24)->default('score');
            $table->string('source_kind', 32)->index();
            $table->string('source_label', 120)->nullable();
            $table->json('components')->nullable();
            $table->timestamp('observed_at')->index();
            $table->timestamps();

            $table->index(
                ['plan_id', 'observed_at'],
                'study_score_plan_observed_idx',
            );
            $table->index(
                ['plan_id', 'user_id', 'observed_at'],
                'study_score_plan_user_observed_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_score_observations');
    }
};
