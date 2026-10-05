<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('provider_connections')) {
            $expectedColumns = [
                'id',
                'user_id',
                'provider_key',
                'public_id',
                'secret_ciphertext',
                'label',
                'status',
                'last_used_at',
                'revoked_at',
                'created_at',
                'updated_at',
            ];

            $existingColumns = Schema::getColumnListing('provider_connections');
            $missingColumns = array_values(array_diff($expectedColumns, $existingColumns));

            if ($missingColumns !== []) {
                throw new RuntimeException(
                    'Existing provider_connections table is incomplete: missing '
                    .implode(', ', $missingColumns).'.',
                );
            }

            return;
        }

        Schema::create('provider_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('provider_key', 191);
            $table->uuid('public_id')->unique();
            $table->text('secret_ciphertext');
            $table->string('label', 191)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(
                ['user_id', 'provider_key', 'status'],
                'provider_connections_owner_provider_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_connections');
    }
};
