<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Non-destructive additive migration. Existing choice answers remain
        // in answer_value and do not need any backfill or reinterpretation.
        if (! Schema::hasColumn('learning_answer_events', 'answer_payload')) {
            Schema::table('learning_answer_events', function (Blueprint $table): void {
                $table->json('answer_payload')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('learning_answer_events', 'answer_payload')) {
            Schema::table('learning_answer_events', function (Blueprint $table): void {
                $table->dropColumn('answer_payload');
            });
        }
    }
};
