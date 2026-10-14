<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('development_operational_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->unique()->constrained('plans')->cascadeOnDelete();
            $table->string('stage', 32)->default('unknown');
            $table->string('runtime', 32)->default('unknown');
            $table->string('data_store', 32)->default('unknown');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('development_operational_profiles');
    }
};
