<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_scenario_fixtures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('plan_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('scenario_key', 64);
            $table->unsignedSmallInteger('scenario_version')
                ->default(1);
            $table->timestamps();

            $table->unique(
                ['user_id', 'scenario_key'],
                'study_scenario_user_key_unique',
            );
            $table->unique('plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_scenario_fixtures');
    }
};
