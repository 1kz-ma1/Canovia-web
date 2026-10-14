<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('development_operational_targets', function (Blueprint $table) {
            $table->timestamp('service_checked_at')->nullable();
            $table->unsignedInteger('service_checked_revision')->nullable();
            $table->string('service_checked_environment', 24)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('development_operational_targets', function (Blueprint $table) {
            $table->dropColumn([
                'service_checked_at', 'service_checked_revision',
                'service_checked_environment',
            ]);
        });
    }
};
