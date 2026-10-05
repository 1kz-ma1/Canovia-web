<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $capturesExist = Schema::hasTable('study_scope_captures');
        $itemsExist = Schema::hasTable('study_scope_items');

        if ($capturesExist) {
            $this->assertCompleteTable('study_scope_captures', [
                'id',
                'plan_id',
                'user_id',
                'inbox_item_id',
                'native_ai_run_id',
                'status',
                'exam_title',
                'exam_date_text',
                'exam_date',
                'draft_data',
                'confidence',
                'extraction_version',
                'failure_code',
                'analyzed_at',
                'confirmed_at',
                'created_at',
                'updated_at',
            ]);
        } else {
            Schema::create('study_scope_captures', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('inbox_item_id')->nullable()->constrained('inbox_items')->nullOnDelete();
                $table->foreignId('native_ai_run_id')->nullable()->constrained('native_ai_runs')->nullOnDelete();

                $table->string('status', 24)->default('captured')->index();
                $table->string('exam_title', 255)->nullable();
                $table->string('exam_date_text', 255)->nullable();
                $table->date('exam_date')->nullable()->index();
                $table->json('draft_data')->nullable();
                $table->decimal('confidence', 5, 4)->nullable();
                $table->string('extraction_version', 32)->default('v1');
                $table->string('failure_code', 80)->nullable();
                $table->timestamp('analyzed_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();

                $table->index(
                    ['plan_id', 'status', 'created_at'],
                    'study_scope_capture_plan_status_idx',
                );
            });
        }

        if ($itemsExist) {
            $this->assertCompleteTable('study_scope_items', [
                'id',
                'study_scope_capture_id',
                'plan_id',
                'subject',
                'unit',
                'range_text',
                'page_start',
                'page_end',
                'source_excerpt',
                'confidence',
                'sort_order',
                'created_at',
                'updated_at',
            ]);
        } else {
            Schema::create('study_scope_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('study_scope_capture_id')
                    ->constrained('study_scope_captures')
                    ->cascadeOnDelete();
                $table->foreignId('plan_id')->constrained()->cascadeOnDelete();

                $table->string('subject', 120);
                $table->string('unit', 255)->nullable();
                $table->text('range_text')->nullable();
                $table->unsignedInteger('page_start')->nullable();
                $table->unsignedInteger('page_end')->nullable();
                $table->text('source_excerpt')->nullable();
                $table->decimal('confidence', 5, 4)->default(1.0);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(
                    ['plan_id', 'subject', 'sort_order'],
                    'study_scope_item_plan_subject_idx',
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('study_scope_items');
        Schema::dropIfExists('study_scope_captures');
    }

    /**
     * @param array<int,string> $expectedColumns
     */
    private function assertCompleteTable(string $table, array $expectedColumns): void
    {
        $existingColumns = Schema::getColumnListing($table);
        $missingColumns = array_values(array_diff($expectedColumns, $existingColumns));

        if ($missingColumns !== []) {
            throw new RuntimeException(
                "Existing {$table} table is incomplete: missing "
                .implode(', ', $missingColumns).'.',
            );
        }
    }
};
