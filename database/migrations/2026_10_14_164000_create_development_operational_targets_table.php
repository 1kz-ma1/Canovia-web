<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('development_operational_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->unique()->constrained('plans')->cascadeOnDelete();
            $table->string('provider', 24);
            $table->string('environment', 24);
            $table->string('resource_ref', 80);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('backup_confirmed_at')->nullable();
            $table->unsignedInteger('backup_revision')->nullable();
            $table->string('backup_store', 32)->nullable();
            $table->timestamp('restore_confirmed_at')->nullable();
            $table->unsignedInteger('restore_revision')->nullable();
            $table->string('restore_store', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('development_operational_targets');
    }
};
