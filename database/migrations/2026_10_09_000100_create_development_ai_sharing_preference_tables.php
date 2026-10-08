<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Handle a MySQL partial migration: CREATE TABLE may commit even when
        // creating the following table fails. Never overwrite existing rows.
        if (! Schema::hasTable('development_ai_sharing_preferences')) {
            Schema::create('development_ai_sharing_preferences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
                $table->string('provider_key', 32);
                $table->string('scope', 24);
                $table->string('status', 24)->default('prepared');
                $table->timestamp('expires_at');
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'plan_id', 'provider_key'], 'dev_ai_preference_actor_plan_provider_unique');
            });
        } else {
            $required = ['id', 'user_id', 'plan_id', 'provider_key', 'scope', 'status', 'expires_at', 'revoked_at', 'created_at', 'updated_at'];
            $missing = array_diff($required, Schema::getColumnListing('development_ai_sharing_preferences'));
            if ($missing !== []) {
                throw new RuntimeException('Existing development AI sharing preferences table is incomplete.');
            }
        }

        if (! Schema::hasTable('development_ai_sharing_preference_events')) {
            Schema::create('development_ai_sharing_preference_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('preference_id')->constrained('development_ai_sharing_preferences')->cascadeOnDelete();
                $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('event', 24);
                $table->string('scope', 24);
                $table->timestamp('expires_at');
                $table->timestamp('created_at');
                $table->index(['preference_id', 'created_at'], 'dev_ai_preference_events_timeline_idx');
            });
        } else {
            $required = ['id', 'preference_id', 'actor_user_id', 'event', 'scope', 'expires_at', 'created_at'];
            $missing = array_diff($required, Schema::getColumnListing('development_ai_sharing_preference_events'));
            if ($missing !== []) {
                throw new RuntimeException('Existing development AI sharing events table is incomplete.');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('development_ai_sharing_preference_events');
        Schema::dropIfExists('development_ai_sharing_preferences');
    }
};
