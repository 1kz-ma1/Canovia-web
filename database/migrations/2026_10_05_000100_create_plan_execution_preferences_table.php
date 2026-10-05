<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plan_execution_preferences')) {
            $expectedColumns = [
                'id',
                'plan_id',
                'capability',
                'provider_key',
                'user_selected',
                'created_at',
                'updated_at',
            ];

            $existingColumns = Schema::getColumnListing('plan_execution_preferences');
            $missingColumns = array_values(array_diff($expectedColumns, $existingColumns));

            if ($missingColumns !== []) {
                throw new RuntimeException(
                    'Existing plan_execution_preferences table is incomplete: missing '
                    .implode(', ', $missingColumns).'.',
                );
            }

            return;
        }

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
