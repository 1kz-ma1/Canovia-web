<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL may commit the first table before failing the second. Preserve
        // existing link data on retry, and fail if an existing table is partial.
        if (! Schema::hasTable('mcp_linked_subjects')) {
            Schema::create('mcp_linked_subjects', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // HMAC fingerprints, NEVER raw external subjects, OAuth
                // tokens, provider email addresses or credentials.
                $table->char('identity_fingerprint', 64)->unique();
                $table->string('provider_key', 32);
                $table->string('status', 24)->default('linked');
                $table->timestamp('linked_at');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status'], 'mcp_subject_user_status_idx');
            });
        } else {
            $required = [
                'id', 'user_id', 'identity_fingerprint', 'provider_key',
                'status', 'linked_at', 'revoked_at', 'created_at', 'updated_at',
            ];
            if (array_diff($required, Schema::getColumnListing('mcp_linked_subjects')) !== []) {
                throw new RuntimeException('Existing MCP identity link table is incomplete.');
            }
        }

        if (! Schema::hasTable('mcp_delegated_grants')) {
            Schema::create('mcp_delegated_grants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('subject_link_id')
                    ->constrained('mcp_linked_subjects')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
                $table->char('client_resource_fingerprint', 64);
                $table->string('scope', 24);
                $table->string('status', 24)->default('revoked');
                $table->timestamp('consented_at')->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->unique(
                    ['subject_link_id', 'plan_id', 'client_resource_fingerprint'],
                    'mcp_subject_plan_client_resource_unique',
                );
                $table->index(['user_id', 'status', 'expires_at'], 'mcp_grants_owner_status_exp_idx');
            });
        } else {
            $required = [
                'id', 'subject_link_id', 'user_id', 'plan_id',
                'client_resource_fingerprint', 'scope', 'status',
                'consented_at', 'expires_at', 'revoked_at', 'created_at', 'updated_at',
            ];
            if (array_diff($required, Schema::getColumnListing('mcp_delegated_grants')) !== []) {
                throw new RuntimeException('Existing MCP delegated grant table is incomplete.');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_delegated_grants');
        Schema::dropIfExists('mcp_linked_subjects');
    }
};
