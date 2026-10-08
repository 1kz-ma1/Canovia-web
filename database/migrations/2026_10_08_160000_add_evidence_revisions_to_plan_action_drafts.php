<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_action_drafts', function (Blueprint $table): void {
            $table->json('evidence_snapshots')->nullable();
            $table->unsignedInteger('revision_no')->default(1);
        });

        Schema::create('plan_action_draft_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_action_draft_id')->constrained('plan_action_drafts')->cascadeOnDelete();
            $table->unsignedInteger('from_revision');
            $table->unsignedInteger('to_revision');
            $table->string('before_action', 255);
            $table->string('after_action', 255);
            $table->json('added_evidence_snapshots');
            $table->timestamp('created_at');
            $table->unique(['plan_action_draft_id', 'to_revision'], 'draft_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_action_draft_revisions');
        Schema::table('plan_action_drafts', function (Blueprint $table): void {
            $table->dropColumn(['evidence_snapshots', 'revision_no']);
        });
    }
};
