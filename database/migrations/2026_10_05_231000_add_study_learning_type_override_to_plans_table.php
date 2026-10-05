<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('study_learning_type_override', 32)
                ->nullable()
                ->after('roadmap_world');
            $table->timestamp('study_learning_type_confirmed_at')
                ->nullable()
                ->after('study_learning_type_override');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'study_learning_type_override',
                'study_learning_type_confirmed_at',
            ]);
        });
    }
};
