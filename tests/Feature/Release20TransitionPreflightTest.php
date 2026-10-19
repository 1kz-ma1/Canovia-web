<?php

namespace Tests\Feature;

use App\Support\Release20IdentityMigrationGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class Release20TransitionPreflightTest extends TestCase
{
    use RefreshDatabase;

    private function initialIdentity(): void
    {
        Schema::table('mcp_linked_subjects', function (Blueprint $table): void {
            $table->dropUnique('mcp_active_subject_identity_unique');
            $table->dropIndex('mcp_subject_identity_history_idx');
            $table->dropColumn('active_identity_fingerprint');
            $table->unique('identity_fingerprint', 'mcp_linked_subjects_identity_fingerprint_unique');
        });
    }

    public function test_complete_identity_replay_is_read_only_and_compatible(): void
    {
        $ddl = 0;
        DB::listen(function ($query) use (&$ddl): void {
            if (preg_match('/^(create|alter|drop|rename)\b/i', $query->sql)) {
                $ddl++;
            }
        });
        Release20IdentityMigrationGuard::assertCompatible();
        $this->assertSame(0, $ddl);
    }

    public function test_historical_unique_alias_is_rejected_before_backfill_or_ddl(): void
    {
        $this->initialIdentity();
        Schema::table('mcp_linked_subjects', function (Blueprint $table): void {
            $table->dropUnique('mcp_linked_subjects_identity_fingerprint_unique');
            $table->unique('identity_fingerprint', 'synthetic_alias');
        });
        try {
            $migration = require database_path('migrations/2026_10_14_000200_preserve_historical_mcp_identity_links.php');
            $migration->up();
            $this->fail('Alias must not be silently retained or dropped.');
        } catch (RuntimeException $e) {
            $this->assertSame('RELEASE20_IDENTITY_PREFLIGHT_BLOCK', $e->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('mcp_linked_subjects', 'active_identity_fingerprint'));
        $this->assertTrue(collect(Schema::getIndexes('mcp_linked_subjects'))->contains('name', 'synthetic_alias'));
    }

    public function test_new_unique_name_with_wrong_definition_is_rejected(): void
    {
        $this->initialIdentity();
        Schema::table('mcp_linked_subjects', fn (Blueprint $table) => $table->index('provider_key', 'mcp_active_subject_identity_unique'));
        $this->expectExceptionMessage('RELEASE20_IDENTITY_PREFLIGHT_BLOCK');
        Release20IdentityMigrationGuard::assertCompatible();
    }

    public function test_duplicate_identity_unique_is_ambiguous(): void
    {
        $this->initialIdentity();
        Schema::table('mcp_linked_subjects', fn (Blueprint $table) => $table->unique('identity_fingerprint', 'synthetic_duplicate_unique'));
        $this->expectExceptionMessage('RELEASE20_IDENTITY_PREFLIGHT_BLOCK');
        Release20IdentityMigrationGuard::assertCompatible();
    }

    public function test_proposal_partial_table_is_not_adopted_or_erased(): void
    {
        Schema::drop('mcp_study_result_proposals');
        Schema::create('mcp_study_result_proposals', function (Blueprint $table): void {
            $table->id();
            $table->string('synthetic_marker');
        });
        DB::table('mcp_study_result_proposals')->insert(['synthetic_marker' => 'retain']);
        try {
            $migration = require database_path('migrations/2026_10_15_070000_create_mcp_study_result_proposals_table.php');
            $migration->up();
            $this->fail('A table without an applied ledger must not be adopted.');
        } catch (RuntimeException $e) {
            $this->assertSame('RELEASE20_PROPOSALS_EXISTING_TABLE_BLOCK', $e->getMessage());
        }
        $this->assertSame('retain', DB::table('mcp_study_result_proposals')->sole()->synthetic_marker);
    }

    public function test_watch_second_table_collision_stops_before_first_create(): void
    {
        Schema::drop('learning_context_watch_states');
        try {
            $migration = require database_path('migrations/2026_10_15_110000_create_learning_context_watch_persistence.php');
            $migration->up();
            $this->fail('Both tables must be screened before any DDL.');
        } catch (RuntimeException $e) {
            $this->assertSame('RELEASE20_WATCH_EXISTING_TABLE_BLOCK', $e->getMessage());
        }
        $this->assertFalse(Schema::hasTable('learning_context_watch_states'));
        $this->assertTrue(Schema::hasTable('learning_context_change_records'));
    }
}
