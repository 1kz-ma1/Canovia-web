<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preflight BOTH tables before the first DDL. A prior committed CREATE
        // without its constraints must not be accepted as an applied migration.
        if (Schema::hasTable('learning_context_watch_states') || Schema::hasTable('learning_context_change_records')) {
            throw new RuntimeException('RELEASE20_WATCH_EXISTING_TABLE_BLOCK');
        }

        // MySQL DDL is not atomic across tables. Replays must never drop
        // existing personal data, and partial schemas are rejected.
        if (! Schema::hasTable('learning_context_watch_states')) {
            Schema::create('learning_context_watch_states', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
                $table->string('status', 20)->default('enabled');
                $table->char('source_revision', 64);
                // Only bounded first-party snapshot is stored, encrypted by
                // Laravel's encrypted:array cast; never raw exercise content.
                $table->longText('encrypted_baseline');
                $table->timestamp('consented_at');
                $table->timestamps();
                $table->unique('plan_id', 'learning_watch_plan_unique');
                $table->index(['user_id', 'status'], 'learning_watch_user_status_idx');
            });
        } else {
            $this->requireColumns('learning_context_watch_states', [
                'id', 'user_id', 'plan_id', 'status', 'source_revision',
                'encrypted_baseline', 'consented_at', 'created_at', 'updated_at',
            ]);
        }

        if (! Schema::hasTable('learning_context_change_records')) {
            Schema::create('learning_context_change_records', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
                $table->char('dedupe_fingerprint', 64);
                $table->char('source_revision', 64);
                // Codes only; no Task title, scores, weak-topic text, or raw
                // prior/current context. Not an actual notification outbox.
                $table->json('event_kinds');
                $table->string('status', 20)->default('review_only');
                $table->timestamp('expires_at');
                $table->timestamps();
                $table->unique(['plan_id', 'dedupe_fingerprint'], 'learning_change_pair_unique');
                $table->index(['user_id', 'plan_id', 'status'], 'learning_change_owner_status_idx');
                $table->index(['expires_at'], 'learning_change_expires_idx');
            });
        } else {
            $this->requireColumns('learning_context_change_records', [
                'id', 'user_id', 'plan_id', 'dedupe_fingerprint', 'source_revision',
                'event_kinds', 'status', 'expires_at', 'created_at', 'updated_at',
            ]);
        }
    }

    /** @param list<string> $required */
    private function requireColumns(string $table, array $required): void
    {
        if (array_diff($required, Schema::getColumnListing($table)) !== []) {
            throw new RuntimeException('Existing Learning context persistence table is incomplete: '.$table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_context_change_records');
        Schema::dropIfExists('learning_context_watch_states');
    }
};
