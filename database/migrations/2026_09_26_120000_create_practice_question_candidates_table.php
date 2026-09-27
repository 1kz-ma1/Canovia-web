<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'practice_question_candidates';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            $this->repairPartiallyAppliedTable();

            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 64)->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->string('provider', 32)->default('native_ai')->index();
            $table->string('model', 120)->nullable();
            $table->string('exam_profile_key', 80)->nullable()->index();
            $table->foreignId('first_practice_question_demand_id')->nullable();
            $table->foreignId('latest_practice_question_demand_id')->nullable();
            $table->foreignId('native_ai_run_id')->nullable();
            $table->json('question_payload');
            $table->json('review_hints')->nullable();
            $table->unsignedInteger('generation_count')->default(1);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->json('review_data')->nullable();
            $table->foreignId('promoted_question_pack_id')->nullable();
            $table->foreignId('promoted_question_id')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // MySQL limits identifiers to 64 characters. The default Laravel
            // names for these constraints exceed that limit, so keep explicit
            // short names here and in the recovery path below.
            $table->foreign('first_practice_question_demand_id', 'pqc_first_demand_fk')
                ->references('id')
                ->on('practice_question_demands')
                ->nullOnDelete();
            $table->foreign('latest_practice_question_demand_id', 'pqc_latest_demand_fk')
                ->references('id')
                ->on('practice_question_demands')
                ->nullOnDelete();
            $table->foreign('native_ai_run_id', 'pqc_native_ai_run_fk')
                ->references('id')
                ->on('native_ai_runs')
                ->nullOnDelete();
            $table->foreign('promoted_question_pack_id', 'pqc_pack_fk')
                ->references('id')
                ->on('question_packs')
                ->nullOnDelete();
            $table->foreign('promoted_question_id', 'pqc_question_fk')
                ->references('id')
                ->on('questions')
                ->nullOnDelete();
            $table->foreign('reviewed_by_user_id', 'pqc_reviewer_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['status', 'last_seen_at'], 'practice_candidate_status_seen_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function repairPartiallyAppliedTable(): void
    {
        // The first production attempt created the table before MySQL rejected
        // an overlong auto-generated FK name. Laravel therefore never recorded
        // the migration, and subsequent deploys retried CREATE TABLE forever.
        // Repair the existing shape in place so the migration can finish.
        $this->ensureIndex(['fingerprint'], 'practice_question_candidates_fingerprint_unique', true);
        $this->ensureIndex(['status'], 'practice_question_candidates_status_index');
        $this->ensureIndex(['provider'], 'practice_question_candidates_provider_index');
        $this->ensureIndex(['exam_profile_key'], 'practice_question_candidates_exam_profile_key_index');
        $this->ensureIndex(['last_seen_at'], 'practice_question_candidates_last_seen_at_index');
        $this->ensureIndex(['status', 'last_seen_at'], 'practice_candidate_status_seen_idx');

        $this->ensureForeignKey(
            'first_practice_question_demand_id',
            'practice_question_demands',
            'pqc_first_demand_fk',
        );
        $this->ensureForeignKey(
            'latest_practice_question_demand_id',
            'practice_question_demands',
            'pqc_latest_demand_fk',
        );
        $this->ensureForeignKey('native_ai_run_id', 'native_ai_runs', 'pqc_native_ai_run_fk');
        $this->ensureForeignKey('promoted_question_pack_id', 'question_packs', 'pqc_pack_fk');
        $this->ensureForeignKey('promoted_question_id', 'questions', 'pqc_question_fk');
        $this->ensureForeignKey('reviewed_by_user_id', 'users', 'pqc_reviewer_fk');
    }

    private function ensureIndex(array $columns, string $name, bool $unique = false): void
    {
        $exists = collect(Schema::getIndexes(self::TABLE))->contains(
            function (array $index) use ($columns, $unique): bool {
                $indexColumns = array_values($index['columns'] ?? []);

                return $indexColumns === $columns
                    && (! $unique || (bool) ($index['unique'] ?? false));
            },
        );

        if ($exists) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $name, $unique) {
            if ($unique) {
                $table->unique($columns, $name);

                return;
            }

            $table->index($columns, $name);
        });
    }

    private function ensureForeignKey(string $column, string $referencesTable, string $name): void
    {
        $exists = collect(Schema::getForeignKeys(self::TABLE))->contains(
            fn (array $foreignKey): bool => array_values($foreignKey['columns'] ?? []) === [$column],
        );

        if ($exists) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($column, $referencesTable, $name) {
            $table->foreign($column, $name)
                ->references('id')
                ->on($referencesTable)
                ->nullOnDelete();
        });
    }
};
