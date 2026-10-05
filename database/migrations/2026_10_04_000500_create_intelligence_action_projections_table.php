<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('intelligence_action_projections')) {
            $expectedColumns = [
                'id',
                'user_id',
                'plan_id',
                'intelligence_decision_trace_id',
                'projected_task_id',
                'domain',
                'scope_type',
                'scope_id',
                'state_fingerprint',
                'action_fingerprint',
                'action_reference',
                'kind',
                'title',
                'intent',
                'confidence',
                'estimated_minutes',
                'success_signals',
                'metadata',
                'status',
                'superseded_at',
                'dismissed_at',
                'created_at',
                'updated_at',
            ];

            $existingColumns = Schema::getColumnListing('intelligence_action_projections');
            $missingColumns = array_values(array_diff($expectedColumns, $existingColumns));

            if ($missingColumns !== []) {
                throw new RuntimeException(
                    'Existing intelligence_action_projections table is incomplete: missing '
                    .implode(', ', $missingColumns).'.',
                );
            }

            return;
        }

        Schema::create('intelligence_action_projections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('intelligence_decision_trace_id')
                ->nullable()
                ->constrained('intelligence_decision_traces')
                ->nullOnDelete();
            $table->foreignId('projected_task_id')
                ->nullable()
                ->constrained('tasks')
                ->nullOnDelete();

            $table->string('domain', 32)->index();
            $table->string('scope_type', 64);
            $table->string('scope_id', 120)->nullable();

            $table->char('state_fingerprint', 64)->index();
            $table->char('action_fingerprint', 64)->index();
            $table->string('action_reference', 128)->unique();

            $table->string('kind', 64)->index();
            $table->string('title', 255);
            $table->text('intent');
            $table->decimal('confidence', 5, 4);
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->json('success_signals');
            $table->json('metadata')->nullable();

            $table->string('status', 32)->default('active')->index();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['plan_id', 'domain', 'scope_type', 'scope_id', 'status'],
                'intelligence_action_scope_status_idx',
            );
            $table->index(
                ['plan_id', 'domain', 'created_at'],
                'intelligence_action_plan_domain_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_action_projections');
    }
};
