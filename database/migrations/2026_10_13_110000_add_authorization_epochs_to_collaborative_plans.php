<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pure additive, bounded metadata. Historic authorization epochs
        // cannot be reconstructed; migrated rows start at generation 1.
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('authorization_epoch')->default(1);
        });
        Schema::table('plan_members', function (Blueprint $table): void {
            $table->unsignedBigInteger('authorization_epoch')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('plan_members', function (Blueprint $table): void {
            $table->dropColumn('authorization_epoch');
        });
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('authorization_epoch');
        });
    }
};
