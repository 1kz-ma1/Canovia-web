<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Null is an explicit, owner-approved "until revoked" consent.
        // Existing finite grants and their expiry timestamps remain unchanged.
        Schema::table('mcp_delegated_grants', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Do not silently terminate or rewrite an indefinite permission as a
        // finite permission when a rollback is requested.
        if (DB::table('mcp_delegated_grants')->whereNull('expires_at')->exists()) {
            throw new RuntimeException('Revoke or explicitly reconsent nonexpiring MCP grants before schema rollback.');
        }

        Schema::table('mcp_delegated_grants', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable(false)->change();
        });
    }
};
