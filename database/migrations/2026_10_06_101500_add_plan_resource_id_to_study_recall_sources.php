<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_recall_sources', function (Blueprint $table) {
            $table->foreignId('plan_resource_id')
                ->nullable()
                ->after('task_id')
                ->constrained('plan_resources')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('study_recall_sources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_resource_id');
        });
    }
};
