<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_file_generation_evidence', function (Blueprint $table): void {
            $table->id();
            // A permanent journal FK, not a mutable APP_KEY-derived
            // identifier, anchors this synthetic evidence to one write.
            $table->foreignId('upload_intent_id')->unique()
                ->constrained('private_file_upload_intents')->restrictOnDelete();
            $table->char('path_digest', 64);
            $table->char('bucket_digest', 64);
            $table->string('provider', 16);
            $table->string('owner_kind', 24);
            $table->unsignedBigInteger('owner_id');
            $table->text('encrypted_version_id');
            $table->string('key_epoch', 48);
            // This is never an attestation that an actual provider API ran.
            $table->string('evidence_origin', 32)->default('synthetic_fixture');
            $table->timestamps();
            $table->index(['owner_kind', 'owner_id'], 'generation_evidence_owner_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_file_generation_evidence');
    }
};
