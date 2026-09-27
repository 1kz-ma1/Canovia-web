<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companion_mutation_candidates', function (Blueprint $table) {
            $table->uuid('apply_request_id')->nullable()->unique()->after('candidate_key');
            $table->string('applied_target_type', 64)->nullable()->after('metadata');
            $table->unsignedBigInteger('applied_target_id')->nullable()->after('applied_target_type');
        });
    }

    public function down(): void
    {
        Schema::table('companion_mutation_candidates', function (Blueprint $table) {
            $table->dropUnique(['apply_request_id']);
            $table->dropColumn([
                'apply_request_id',
                'applied_target_type',
                'applied_target_id',
            ]);
        });
    }
};
