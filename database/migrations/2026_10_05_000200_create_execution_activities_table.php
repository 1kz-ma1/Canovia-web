<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('execution_activities')) {
            $expectedColumns = [
                'id',
                'user_id',
                'actor_token',
                'provider_key',
                'capability',
                'external_key',
                'type',
                'title',
                'status',
                'started_at',
                'completed_at',
                'duration_seconds',
                'metrics',
                'metadata',
                'plan_id',
                'task_id',
                'task_evidence_id',
                'linked_at',
                'created_at',
                'updated_at',
            ];

            $existingColumns = Schema::getColumnListing('execution_activities');
            $missingColumns = array_values(array_diff($expectedColumns, $existingColumns));

            if ($missingColumns !== []) {
                throw new RuntimeException(
                    'Existing execution_activities table is incomplete: missing '
                    .implode(', ', $missingColumns).'.',
                );
            }

            return;
        }

        Schema::create('execution_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_token', 64)->nullable()->index();

            $table->string('provider_key', 191)->index();
            $table->string('capability', 191)->index();
            $table->string('external_key', 191)->nullable();
            $table->string('type', 64)->index();
            $table->string('title');
            $table->string('status', 32)->index();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('duration_seconds')->nullable();
            $table->json('metrics')->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_evidence_id')
                ->nullable()
                ->constrained('task_evidences')
                ->nullOnDelete();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider_key', 'external_key'],
                'execution_activity_provider_external_unique',
            );
            $table->index(
                ['plan_id', 'created_at'],
                'execution_activity_plan_created_idx',
            );
            $table->index(
                ['task_id', 'created_at'],
                'execution_activity_task_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_activities');
    }
};
