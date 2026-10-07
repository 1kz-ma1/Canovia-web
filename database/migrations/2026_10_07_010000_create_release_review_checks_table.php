<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_review_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('release_level');
            $table->string('check_key', 120);
            $table->string('status', 16);
            $table->text('note')->nullable();
            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['release_level', 'check_key'],
                'release_review_level_key_unique',
            );
            $table->index(
                ['release_level', 'status'],
                'release_review_level_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_review_checks');
    }
};
