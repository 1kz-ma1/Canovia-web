<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_file_path_fences', function (Blueprint $table): void {
            $table->id();
            // HMAC of disk + private path, never the raw storage key/user ID.
            // The unique row is retained to prevent delete/recreate ABA locks.
            $table->char('path_digest', 64)->unique('private_file_path_fence_key_unique');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_file_path_fences');
    }
};
