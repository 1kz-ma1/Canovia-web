<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_execution_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('capability', 191);
            $table->string('provider_key', 191);
            $table->boolean('user_selected')->default(true);
            $table->timestamps();

            $table->unique(
                ['plan_id', 'capability'],
                'plan_execution_preference_capability_unique',
            );
            $table->index('provider_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_execution_preferences');
    }
};
