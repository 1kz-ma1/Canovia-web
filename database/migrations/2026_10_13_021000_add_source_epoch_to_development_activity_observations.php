<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('development_activity_observations', function (Blueprint $table) {
            // NULL is deliberately unknown for every pre-epoch historical row.
            // No backfill: only a fresh authoritative GitHub fetch can sign it.
            $table->uuid('source_epoch')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('development_activity_observations', function (Blueprint $table) {
            $table->dropColumn('source_epoch');
        });
    }
};
