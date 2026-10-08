<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_action_draft_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_action_draft_id')->constrained('plan_action_drafts')->cascadeOnDelete();
            $table->unsignedTinyInteger('sort_order');
            $table->unsignedInteger('evidence_revision');
            $table->string('title', 255);
            $table->foreignId('accepted_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->timestamps();

            $table->unique(['plan_action_draft_id', 'sort_order'], 'action_draft_step_position_unique');
            $table->index(['plan_action_draft_id', 'evidence_revision'], 'action_draft_step_revision_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_action_draft_steps');
    }
};
