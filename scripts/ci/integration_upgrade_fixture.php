<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (getenv('CI') !== 'true' || getenv('CANOVIA_P0_DISPOSABLE_MYSQL_CI') !== '1'
    || ! app()->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('database.connections.mysql.database') !== 'canovia_p0_ci'
    || config('database.connections.mysql.username') !== 'canovia_ci'
    || (string) config('database.connections.mysql.url', '') !== '') {
    throw new RuntimeException('INTEGRATION_FIXTURE_NONDISPOSABLE_DENIED');
}

function requireFact(bool $condition, string $code): void
{
    if (! $condition) {
        throw new RuntimeException($code);
    }
}

$mode = $argv[1] ?? '';
$snapshot = $argv[2] ?? '';
$tables = ['users', 'plans', 'plan_members', 'plan_artifacts', 'development_activity_observations',
    'mcp_linked_subjects', 'mcp_delegated_grants', 'sessions'];

if ($mode === 'snapshot') {
    requireFact(DB::table('migrations')->count() === 94, 'EXPECTED_94_BASELINE');
    $owner = DB::table('users')->where('email', 'pinned-upgrade-owner@example.invalid')->sole();
    DB::table('sessions')->insert(['id' => str_repeat('s', 40), 'user_id' => $owner->id,
        'payload' => base64_encode(serialize(['integration_marker' => 'before_upgrade'])), 'last_activity' => time()]);
    $data = [];
    foreach ($tables as $table) {
        $columns = Schema::getColumnListing($table);
        $data[$table] = ['columns' => $columns, 'digest' => hash('sha256',
            json_encode(DB::table($table)->select($columns)->orderBy('id')->get(), JSON_THROW_ON_ERROR))];
    }
    file_put_contents($snapshot, json_encode($data, JSON_THROW_ON_ERROR));
    echo "integration_baseline_rows_sealed: pass\n";
    exit(0);
}

$files = app('migrator')->getMigrationFiles(database_path('migrations'));
$ran = app('migration.repository')->getRan();
requireFact(count($files) === 114 && count($ran) === 114 && ! array_diff(array_keys($files), $ran), 'EXACT_114_SOURCE_LEDGER');
if ($mode === 'verify') {
    $data = json_decode(file_get_contents($snapshot), true, flags: JSON_THROW_ON_ERROR);
    foreach ($tables as $table) {
        $digest = hash('sha256', json_encode(DB::table($table)->select($data[$table]['columns'])
            ->orderBy('id')->get(), JSON_THROW_ON_ERROR));
        requireFact(hash_equals($data[$table]['digest'], $digest), 'HISTORICAL_ROWS_CHANGED_'.$table);
    }
    requireFact(Schema::hasTable('learning_context_source_epochs'), 'TWENTIETH_MIGRATION_MISSING');
    requireFact(DB::table('learning_context_source_epochs')->count() === 0, 'SOURCE_EPOCH_MUST_NOT_ENABLE_WATCH');
    echo "integration_94_to_114_auth_session_plan_evidence_identity_rows: pass\n";
    exit(0);
}
if ($mode !== 'fences') {
    throw new RuntimeException('INVALID_INTEGRATION_FIXTURE_MODE');
}
$plan = DB::table('plans')->where('title', 'Pinned MySQL19 historical Plan')->sole();
requireFact((int) $plan->authorization_epoch === 1, 'PLAN_EPOCH_BACKFILL');
$member = DB::table('plan_members')->where('plan_id', $plan->id)->sole();
DB::table('plan_members')->where('id', $member->id)->update(['role' => 'viewer']);
requireFact((int) DB::table('plan_members')->where('id', $member->id)->value('authorization_epoch') === 2, 'LEGACY_MEMBER_WRITER_EPOCH_FENCE');
DB::table('plans')->where('id', $plan->id)->update(['is_collaborative' => false]);
requireFact((int) DB::table('plans')->where('id', $plan->id)->value('authorization_epoch') === 2, 'LEGACY_PLAN_WRITER_EPOCH_FENCE');
$active = DB::table('mcp_linked_subjects')->where('identity_fingerprint', str_repeat('a', 64))->sole();
$revoked = DB::table('mcp_linked_subjects')->where('identity_fingerprint', str_repeat('b', 64))->sole();
requireFact($active->active_identity_fingerprint === str_repeat('a', 64)
    && $revoked->active_identity_fingerprint === null, 'IDENTITY_HISTORY_PRESERVED');
try {
    DB::table('plan_members')->where('id', $member->id)->update(['user_id' => $plan->user_id]);
    throw new RuntimeException('MEMBERSHIP_REBIND_ACCEPTED');
} catch (QueryException) {
    // Actual MySQL trigger/constraint must reject the old writer's rebind.
}
echo "integration_legacy_writer_epoch_and_identity_fences: pass\n";
