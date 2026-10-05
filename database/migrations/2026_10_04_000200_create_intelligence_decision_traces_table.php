<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('intelligence_decision_traces')) {
            $expectedColumns = [
                'id',
                'user_id',
                'plan_id',
                'intelligence_state_snapshot_id',
                'domain',
                'scope_type',
                'scope_id',
                'state_reference',
                'state_fingerprint',
                'readiness_fingerprint',
                'readiness_score',
                'readiness_level',
                'readiness_confidence',
                'readiness_components',
                'readiness_gaps',
                'readiness_metadata',
                'decision_reference',
                'decision_type',
                'reason_code',
                'decision_summary',
                'decision_confidence',
                'input_fingerprint',
                'decision_reasons',
                'decision_metadata',
                'metadata',
                'created_at',
                'updated_at',
            ];

            $existingColumns = Schema::getColumnListing('intelligence_decision_traces');
            $missingColumns = array_values(array_diff($expectedColumns, $existingColumns));

            if ($missingColumns !== []) {
                throw new RuntimeException(
                    'Existing intelligence_decision_traces table is incomplete: missing '
                    .implode(', ', $missingColumns).'.',
                );
            }

            return;
        }

        Schema::create('intelligence_decision_traces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('intelligence_state_snapshot_id')
                ->constrained('intelligence_state_snapshots')
                ->cascadeOnDelete();
            $table->string('domain', 32)->index();
            $table->string('scope_type', 64);
            $table->string('scope_id', 120)->nullable();
            $table->string('state_reference', 128)->index();
            $table->char('state_fingerprint', 64)->index();
            $table->char('readiness_fingerprint', 64)->index();
            $table->unsignedTinyInteger('readiness_score')->nullable();
            $table->string('readiness_level', 32);
            $table->decimal('readiness_confidence', 5, 4);
            $table->json('readiness_components');
            $table->json('readiness_gaps');
            $table->json('readiness_metadata')->nullable();
            $table->string('decision_reference', 128)->unique();
            $table->string('decision_type', 64)->index();
            $table->string('reason_code', 64)->index();
            $table->string('decision_summary', 255);
            $table->decimal('decision_confidence', 5, 4);
            $table->char('input_fingerprint', 64)->index();
            $table->json('decision_reasons');
            $table->json('decision_metadata')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['domain', 'scope_type', 'scope_id', 'created_at'],
                'intelligence_decision_scope_created_idx',
            );
            $table->index(
                ['plan_id', 'domain', 'created_at'],
                'intelligence_decision_plan_domain_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_decision_traces');
    }
};
