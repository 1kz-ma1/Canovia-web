<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_id', 100)->unique();
            $table->string('event_name', 80);
            $table->string('action', 80)->nullable();
            $table->string('repo_full_name', 255)->index();
            $table->unsignedBigInteger('installation_id')->nullable()->index();
            $table->json('pull_request_numbers')->nullable();
            $table->string('status', 32)->default('accepted')->index();
            $table->unsignedInteger('matched_artifacts')->default(0);
            $table->unsignedInteger('synced_tasks')->default(0);
            $table->unsignedInteger('skipped_entitlement')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 1000)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_webhook_deliveries');
    }
};
