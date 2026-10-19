<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        \App\Support\Release20PendingSchemaGuard::assertInitialBoundary();

        Schema::table('development_activity_observations', function (Blueprint $table) {
            // GitHub's entity version, not the webhook delivery timestamp.
            // NULL is retained for legacy records and non-versioned sources.
            $table->timestamp('source_updated_at')->nullable();
            // Tie breaker for two Deployment statuses created in one second.
            $table->unsignedBigInteger('source_revision_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('development_activity_observations', function (Blueprint $table) {
            $table->dropColumn(['source_updated_at', 'source_revision_id']);
        });
    }
};
