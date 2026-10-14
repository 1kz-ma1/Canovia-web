<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_file_write_attempts', function (Blueprint $table): void {
            $table->id();
            // One immutable prewrite attempt per historical upload-intent.
            // Never delete/reinsert to recycle a key after a crash.
            $table->foreignId('upload_intent_id')->unique()
                ->constrained('private_file_upload_intents')->restrictOnDelete();
            $table->char('path_digest', 64);
            $table->char('bucket_digest', 64);
            $table->char('request_token_digest', 64);
            $table->string('key_epoch', 48);
            $table->string('status', 36)->default('reserved')->index();
            // A fixture receipt is NEVER an authenticated provider receipt.
            $table->text('encrypted_synthetic_version_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_file_write_attempts');
    }
};
