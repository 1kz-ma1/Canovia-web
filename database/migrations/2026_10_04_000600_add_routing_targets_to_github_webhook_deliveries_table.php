<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('github_webhook_deliveries', 'routing_targets')) {
            return;
        }

        Schema::table('github_webhook_deliveries', function (Blueprint $table) {
            $table->json('routing_targets')
                ->nullable()
                ->after('pull_request_numbers');
        });
    }

    public function down(): void
    {
        Schema::table('github_webhook_deliveries', function (Blueprint $table) {
            $table->dropColumn('routing_targets');
        });
    }
};
