<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReleaseSchemaLedgerFenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_ledger_check_is_read_only_and_never_release_go(): void
    {
        DB::enableQueryLog();
        $this->artisan('canovia:release-schema-ready')
            ->expectsOutputToContain('RELEASE_SCHEMA_LEDGER_CURRENT_NOT_RELEASE_GO')->assertSuccessful();
        foreach (DB::getQueryLog() as $query) {
            $this->assertMatchesRegularExpression('/^\s*(select|pragma)\b/i', $query['query']);
        }
    }

    public function test_pending_source_blocks_without_applying_or_deleting_anything(): void
    {
        DB::table('migrations')->where('migration', '2026_10_15_120000_create_learning_context_source_epochs_table')->delete();
        $before = DB::table('migrations')->count();
        $this->artisan('canovia:release-schema-ready')->expectsOutputToContain('RELEASE_SCHEMA_PENDING')->assertFailed();
        $this->assertSame($before, DB::table('migrations')->count());
        $this->assertTrue(Schema::hasTable('learning_context_source_epochs'));
    }

    public function test_unknown_history_blocks_instead_of_assuming_schema_compatibility(): void
    {
        DB::table('migrations')->insert(['migration' => 'unreviewed_future_change', 'batch' => 999]);
        $this->artisan('canovia:release-schema-ready')->expectsOutputToContain('RELEASE_SCHEMA_UNKNOWN_HISTORY')->assertFailed();
        $this->assertDatabaseHas('migrations', ['migration' => 'unreviewed_future_change']);
    }
}
