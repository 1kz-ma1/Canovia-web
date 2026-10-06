<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('development_activity_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_artifact_id')
                ->constrained('plan_artifacts')
                ->cascadeOnDelete();

            $table->string('provider', 32)->default('github');
            $table->string('kind', 32)->index();
            $table->string('external_key', 191);
            $table->unsignedBigInteger('provider_number')->nullable();
            $table->text('url')->nullable();
            $table->string('title')->nullable();
            $table->string('state', 64)->nullable();
            $table->string('ref', 255)->nullable();
            $table->string('sha', 64)->nullable();

            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamp('last_observed_at')->index();

            $table->foreignId('suggested_task_id')
                ->nullable()
                ->constrained('tasks')
                ->nullOnDelete();
            $table->decimal('suggestion_confidence', 5, 4)->nullable();
            $table->json('suggestion_basis')->nullable();

            $table->string('resolution_status', 24)->default('unlinked')->index();
            $table->foreignId('resolved_artifact_id')
                ->nullable()
                ->constrained('plan_artifacts')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['plan_id', 'provider', 'external_key'],
                'dev_activity_observation_identity_unique',
            );
            $table->index(
                ['repository_artifact_id', 'resolution_status', 'last_observed_at'],
                'dev_activity_repo_resolution_observed_idx',
            );
            $table->index(
                ['plan_id', 'last_observed_at'],
                'dev_activity_plan_observed_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('development_activity_observations');
    }
};
