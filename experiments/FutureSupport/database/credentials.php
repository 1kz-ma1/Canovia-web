<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Deliberately outside database/migrations: no automatic production DDL.
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException('FUTURE_LAB_ONLY');
        }
        Schema::create('future_native_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('FUTURE_LAB_DESTRUCTIVE_ROLLBACK_NOT_PROVIDED');
    }
};
