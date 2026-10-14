<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_file_cleanup_entries', function (Blueprint $table): void {
            $table->id();
            // Encrypted because Inbox paths can contain personal/tenant IDs.
            // Entries are created atomically with the database deletion.
            $table->text('encrypted_path');
            $table->string('disk', 64);
            $table->char('expected_sha256', 64)->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('last_reason', 64)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at', 'id'], 'private_cleanup_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_file_cleanup_entries');
    }
};
