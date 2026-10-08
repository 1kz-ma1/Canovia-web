<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LearningAdjustmentMigrationRecoveryV5884Test extends TestCase
{
    use RefreshDatabase;

    public function test_repeating_adjustment_migration_does_not_delete_or_recreate_existing_table(): void
    {
        $this->assertTrue(Schema::hasTable('learning_answer_evaluation_adjustments'));
        $migration = require database_path('migrations/2026_10_08_230000_create_learning_answer_evaluation_adjustments.php');
        $migration->up();
        $this->assertTrue(Schema::hasTable('learning_answer_evaluation_adjustments'));
        $this->assertTrue(Schema::hasColumn('learning_answer_evaluation_adjustments', 'learning_answer_event_id'));
        $this->assertTrue(Schema::hasColumn('learning_answer_evaluation_adjustments', 'reason'));
    }
}
