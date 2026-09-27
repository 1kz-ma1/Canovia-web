<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goal_contexts', function (Blueprint $table) {
            $table->json('interaction_profile')->nullable()->after('current_state_summary');
        });
    }

    public function down(): void
    {
        Schema::table('goal_contexts', function (Blueprint $table) {
            $table->dropColumn('interaction_profile');
        });
    }
};
