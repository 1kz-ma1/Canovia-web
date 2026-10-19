<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class IntegratedRelease20MigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function planId(): int
    {
        return DB::table('plans')->insertGetId([
            'user_id' => User::factory()->create()->id,
            'owner_token' => bin2hex(random_bytes(32)),
            'public_slug' => (string) \Illuminate\Support\Str::uuid(),
            'title' => 'Synthetic migration safety Plan',
            'category' => '資格学習',
            'start_date' => today(), 'deadline' => today()->addWeek(),
        ]);
    }

    public function test_partial_epoch_table_is_refused_and_its_rows_are_retained(): void
    {
        $planId = $this->planId();
        Schema::drop('learning_context_source_epochs');
        Schema::create('learning_context_source_epochs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('epoch')->default(0);
            $table->string('last_mutation_kind', 32);
            $table->timestamps();
        });
        DB::table('learning_context_source_epochs')->insert([
            'plan_id' => $planId, 'epoch' => 7, 'last_mutation_kind' => 'synthetic_retained',
        ]);
        $migration = require database_path('migrations/2026_10_15_120000_create_learning_context_source_epochs_table.php');
        try {
            $migration->up();
            $this->fail('Partial constraints must not become an applied migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('manual forward recovery', $exception->getMessage());
        }
        $this->assertDatabaseHas('learning_context_source_epochs', ['plan_id' => $planId, 'epoch' => 7]);
        $this->assertDatabaseCount('learning_context_source_epochs', 1);
    }

    public function test_complete_epoch_table_can_replay_without_erasing_existing_epochs(): void
    {
        $planId = $this->planId();
        DB::table('learning_context_source_epochs')->insert([
            'plan_id' => $planId, 'epoch' => 9, 'last_mutation_kind' => 'synthetic_retained',
        ]);
        $migration = require database_path('migrations/2026_10_15_120000_create_learning_context_source_epochs_table.php');
        $migration->up();
        $this->assertDatabaseHas('learning_context_source_epochs', ['plan_id' => $planId, 'epoch' => 9]);
        $this->assertDatabaseCount('learning_context_source_epochs', 1);
    }

    public function test_fresh_schema_rejects_duplicate_plan_epochs(): void
    {
        $planId = $this->planId();
        $row = ['plan_id' => $planId, 'epoch' => 1, 'last_mutation_kind' => 'synthetic'];
        DB::table('learning_context_source_epochs')->insert($row);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('learning_context_source_epochs')->insert($row);
    }

    public function test_epoch_replay_refuses_changed_foreign_update_action(): void
    {
        Schema::table('learning_context_source_epochs', function (Blueprint $table): void {
            $table->dropForeign(['plan_id']);
            $table->foreign('plan_id')->references('id')->on('plans')->cascadeOnUpdate()->cascadeOnDelete();
        });
        $this->expectExceptionMessage('manual forward recovery');
        $migration = require database_path('migrations/2026_10_15_120000_create_learning_context_source_epochs_table.php');
        $migration->up();
    }

    public function test_epoch_replay_refuses_additional_unique_constraint(): void
    {
        Schema::table('learning_context_source_epochs', fn (Blueprint $table) =>
            $table->unique('last_mutation_kind', 'synthetic_extra_epoch_unique'));
        $this->expectExceptionMessage('manual forward recovery');
        $migration = require database_path('migrations/2026_10_15_120000_create_learning_context_source_epochs_table.php');
        $migration->up();
    }
}
