<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('development_operational_profiles', function (Blueprint $table) {
            $table->unsignedInteger('data_store_revision')->default(1);
        });
        Schema::table('development_operational_targets', function (Blueprint $table) {
            $table->unsignedInteger('backup_store_revision')->nullable();
            $table->unsignedInteger('restore_store_revision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('development_operational_targets', function (Blueprint $table) {
            $table->dropColumn(['backup_store_revision', 'restore_store_revision']);
        });
        Schema::table('development_operational_profiles', function (Blueprint $table) {
            $table->dropColumn('data_store_revision');
        });
    }
};
