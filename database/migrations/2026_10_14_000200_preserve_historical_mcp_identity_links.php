<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_UNIQUE = 'mcp_linked_subjects_identity_fingerprint_unique';
    private const ACTIVE_UNIQUE = 'mcp_active_subject_identity_unique';
    private const HISTORY_INDEX = 'mcp_subject_identity_history_idx';

    public function up(): void
    {
        // No DDL/backfill until names AND complete MySQL index definitions
        // agree. Alternate names require separate operator review, not DROP.
        \App\Support\Release20IdentityMigrationGuard::assertCompatible();

        // Preserve immutable original records and grant/audit foreign keys.
        // A NULL active fingerprint releases exclusivity only after explicit
        // subject unlink; DB uniqueness still forbids two active owners.
        if (! Schema::hasColumn('mcp_linked_subjects', 'active_identity_fingerprint')) {
            Schema::table('mcp_linked_subjects', function (Blueprint $table): void {
                $table->char('active_identity_fingerprint', 64)->nullable();
            });
        }

        DB::table('mcp_linked_subjects')
            ->where('status', 'linked')->whereNull('revoked_at')
            ->update(['active_identity_fingerprint' => DB::raw('identity_fingerprint')]);
        DB::table('mcp_linked_subjects')
            ->where(static fn ($query) => $query
                ->where('status', '!=', 'linked')->orWhereNotNull('revoked_at'))
            ->update(['active_identity_fingerprint' => null]);

        // Critical order for MySQL's non-transactional DDL: the new unique
        // constraint MUST exist before the historical unique is removed.
        if (! $this->hasIndex(self::ACTIVE_UNIQUE)) {
            Schema::table('mcp_linked_subjects', function (Blueprint $table): void {
                $table->unique('active_identity_fingerprint', self::ACTIVE_UNIQUE);
            });
        }
        if ($this->hasIndex(self::OLD_UNIQUE)) {
            \App\Support\Release20IdentityMigrationGuard::assertCompatible();
            Schema::table('mcp_linked_subjects', function (Blueprint $table): void {
                $table->dropUnique(self::OLD_UNIQUE);
            });
        }
        if (! $this->hasIndex(self::HISTORY_INDEX)) {
            Schema::table('mcp_linked_subjects', function (Blueprint $table): void {
                $table->index('identity_fingerprint', self::HISTORY_INDEX);
            });
        }
    }

    public function down(): void
    {
        // A migration rollback must never destroy historical audit identity.
        $duplicateHistory = DB::table('mcp_linked_subjects')
            ->select('identity_fingerprint')
            ->groupBy('identity_fingerprint')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicateHistory) {
            throw new RuntimeException('Historical MCP identity has been reused; cannot restore global unique index safely.');
        }

        if (! $this->hasIndex(self::OLD_UNIQUE)) {
            Schema::table('mcp_linked_subjects', function (Blueprint $table): void {
                $table->unique('identity_fingerprint', self::OLD_UNIQUE);
            });
        }
        if ($this->hasIndex(self::ACTIVE_UNIQUE)) {
            Schema::table('mcp_linked_subjects', function (Blueprint $table): void {
                $table->dropUnique(self::ACTIVE_UNIQUE);
            });
        }
        if ($this->hasIndex(self::HISTORY_INDEX)) {
            Schema::table('mcp_linked_subjects', function (Blueprint $table): void {
                $table->dropIndex(self::HISTORY_INDEX);
            });
        }
        if (Schema::hasColumn('mcp_linked_subjects', 'active_identity_fingerprint')) {
            Schema::table('mcp_linked_subjects', function (Blueprint $table): void {
                $table->dropColumn('active_identity_fingerprint');
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        foreach (Schema::getIndexes('mcp_linked_subjects') as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
