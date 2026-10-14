<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_file_upload_intents', function (Blueprint $table): void {
            $table->id();
            // Keyed digest (never a raw path), unique for the lifetime of
            // this pilot so a historical intent cannot silently be reused.
            $table->char('path_digest', 64)->unique('private_upload_intent_digest_unique');
            $table->text('encrypted_path');
            $table->string('disk', 64);
            $table->string('status', 24)->default('prepared')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_file_upload_intents');
    }
};
