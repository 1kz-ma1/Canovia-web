<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goal_discovery_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_context_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('native_ai_run_id')->nullable()->constrained('native_ai_runs')->nullOnDelete();
            $table->string('role', 16);
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['goal_context_id', 'id']);
        });

        Schema::table('future_memos', function (Blueprint $table) {
            $table->string('source', 40)->default('manual')->after('sort_order');
            $table->string('source_context_type', 40)->nullable()->after('source');
            $table->unsignedBigInteger('source_context_id')->nullable()->after('source_context_type');
            $table->timestamp('captured_at')->nullable()->after('source_context_id');

            $table->index(['user_id', 'source']);
            $table->index(['source_context_type', 'source_context_id']);
        });
    }

    public function down(): void
    {
        Schema::table('future_memos', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'source']);
            $table->dropIndex(['source_context_type', 'source_context_id']);
            $table->dropColumn([
                'source',
                'source_context_type',
                'source_context_id',
                'captured_at',
            ]);
        });

        Schema::dropIfExists('goal_discovery_messages');
    }
};
