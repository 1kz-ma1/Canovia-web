<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_recall_candidates', function (Blueprint $table) {
            // NULL is historical (unverifiable) provenance. Never fabricate
            // a generation epoch by backfilling from today's membership.
            $table->json('review_provenance')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('study_recall_candidates', function (Blueprint $table) {
            $table->dropColumn('review_provenance');
        });
    }
};
