<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_action_draft_steps', function (Blueprint $table): void {
            // Nullable: older V58.74 bundles must be explicitly reviewed/rebuilt.
            $table->string('proposal_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plan_action_draft_steps', function (Blueprint $table): void {
            $table->dropColumn('proposal_fingerprint');
        });
    }
};
