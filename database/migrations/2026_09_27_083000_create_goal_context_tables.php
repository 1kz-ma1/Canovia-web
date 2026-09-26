<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goal_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_token', 191)->nullable()->index();
            $table->foreignId('plan_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->text('desired_state');
            $table->text('current_state_summary')->nullable();
            $table->unsignedTinyInteger('readiness_score')->default(0);
            $table->string('readiness_state', 24)->default('low');
            $table->string('status', 24)->default('discovery');
            $table->timestamp('last_assessed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['actor_token', 'status']);
        });

        Schema::create('goal_context_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_context_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('key', 128)->nullable();
            $table->string('label', 255);
            $table->json('value_json')->nullable();
            $table->string('source', 32);
            $table->string('state', 24)->default('confirmed');
            $table->decimal('confidence', 4, 3)->default(1);
            $table->unsignedTinyInteger('importance')->default(3);
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['goal_context_id', 'type', 'state']);
            $table->index(['goal_context_id', 'source']);
            $table->index(['goal_context_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_context_facts');
        Schema::dropIfExists('goal_contexts');
    }
};
