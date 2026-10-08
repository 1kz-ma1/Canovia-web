<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL may already be committed after a partial deployment.
        // Never destroy existing audit rows during a retry.
        if (Schema::hasTable('mcp_delegated_access_events')) {
            $required = [
                'id', 'actor_user_id', 'subject_link_id', 'grant_id',
                'event_kind', 'scope', 'created_at',
            ];
            if (array_diff($required, Schema::getColumnListing('mcp_delegated_access_events')) !== []) {
                throw new RuntimeException('Existing MCP access audit table is incomplete.');
            }

            return;
        }

        Schema::create('mcp_delegated_access_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subject_link_id')
                ->constrained('mcp_linked_subjects')->cascadeOnDelete();
            $table->foreignId('grant_id')->nullable()
                ->constrained('mcp_delegated_grants')->cascadeOnDelete();
            $table->string('event_kind', 40);
            $table->string('scope', 24)->nullable();
            $table->timestamp('created_at');
            $table->index(
                ['actor_user_id', 'created_at'],
                'mcp_access_audit_actor_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_delegated_access_events');
    }
};
