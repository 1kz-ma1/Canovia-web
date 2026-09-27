<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('first_run_completed_at')
                ->nullable()
                ->after('onboarding_skipped_at');
        });

        // This gate is introduced after Canovia already has users. Existing
        // accounts must never be mistaken for brand-new accounts on deploy.
        DB::table('users')
            ->whereNull('first_run_completed_at')
            ->update(['first_run_completed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('first_run_completed_at');
        });
    }
};
