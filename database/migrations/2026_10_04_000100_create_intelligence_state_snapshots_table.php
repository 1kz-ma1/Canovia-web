<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intelligence_state_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('domain', 32)->index();
            $table->string('scope_type', 64);
            $table->string('scope_id', 120)->nullable();
            $table->char('state_fingerprint', 64)->index();
            $table->string('state_reference', 128)->unique();
            $table->timestamp('captured_at')->index();
            $table->json('metrics');
            $table->json('facts');
            $table->json('evidence_references');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['domain', 'scope_type', 'scope_id', 'captured_at'],
                'intelligence_state_scope_captured_idx',
            );
            $table->index(
                ['plan_id', 'domain', 'captured_at'],
                'intelligence_state_plan_domain_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_state_snapshots');
    }
};
