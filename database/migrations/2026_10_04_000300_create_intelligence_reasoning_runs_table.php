<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intelligence_reasoning_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('intelligence_decision_trace_id')
                ->nullable()
                ->constrained('intelligence_decision_traces')
                ->nullOnDelete();
            $table->foreignId('native_ai_run_id')
                ->nullable()
                ->constrained('native_ai_runs')
                ->nullOnDelete();

            $table->string('domain', 32)->index();
            $table->string('scope_type', 64);
            $table->string('scope_id', 120)->nullable();
            $table->string('state_reference', 128)->index();
            $table->char('readiness_fingerprint', 64)->index();
            $table->char('input_fingerprint', 64)->index();
            $table->char('request_fingerprint', 64)->index();

            $table->string('mode_requested', 24);
            $table->string('route_selected', 24)->index();
            $table->string('provider', 32)->nullable()->index();
            $table->string('model', 120)->nullable();
            $table->string('status', 24)->index();

            $table->string('baseline_decision_type', 64);
            $table->string('selected_decision_type', 64);
            $table->decimal('baseline_confidence', 5, 4);
            $table->decimal('selected_confidence', 5, 4);
            $table->boolean('agrees_with_baseline');
            $table->boolean('used_fallback')->default(false);
            $table->string('fallback_reason', 80)->nullable();
            $table->unsignedSmallInteger('candidate_count');

            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->decimal('estimated_cost_usd', 14, 8)->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['plan_id', 'domain', 'created_at'],
                'intelligence_reasoning_plan_domain_idx',
            );
            $table->index(
                ['domain', 'scope_type', 'scope_id', 'created_at'],
                'intelligence_reasoning_scope_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_reasoning_runs');
    }
};
