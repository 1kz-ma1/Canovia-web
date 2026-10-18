<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use App\Models\User;

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
if ($mode === 'seed') {
    requireFact(DB::table('migrations')->count() === 94, 'EXPECTED_94_BASELINE');
    $owner = User::factory()->create(['email' => 'integrated20-owner@example.invalid']);
    $other = User::factory()->create(['email' => 'integrated20-member@example.invalid']);
    $plans = [];
    foreach (['資格学習', '個人開発'] as $category) {
        $plans[] = DB::table('plans')->insertGetId([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'Integrated20 '.$category,
            'description' => 'Synthetic retained legacy data', 'category' => $category,
            'priority' => 2, 'priority_mode' => 'manual', 'start_date' => today(),
            'deadline' => today()->addWeek(), 'is_collaborative' => $category === '個人開発',
            'is_public' => false,
        ]);
    }
    DB::table('plan_members')->insert(['plan_id' => $plans[1], 'user_id' => $other->id,
        'role' => 'editor', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $task = DB::table('tasks')->insertGetId(['plan_id' => $plans[0], 'title' => 'Synthetic AP task',
        'status' => 'doing', 'progress_percent' => 35, 'priority' => 1, 'sort_order' => 0]);
    $artifact = DB::table('plan_artifacts')->insertGetId(['plan_id' => $plans[1],
        'created_by_user_id' => $owner->id, 'provider' => 'github', 'artifact_type' => 'repository',
        'title' => 'Synthetic repo', 'url' => 'https://github.com/example/synthetic-only']);
    DB::table('development_activity_observations')->insert(['plan_id' => $plans[1],
        'repository_artifact_id' => $artifact, 'provider' => 'github', 'kind' => 'issue',
        'external_key' => 'integration20-legacy', 'state' => 'open', 'last_observed_at' => now(),
        'resolution_status' => 'unlinked', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('plan_resources')->insert(['plan_id' => $plans[0], 'created_by_user_id' => $owner->id,
        'provider' => 'local', 'resource_type' => 'file', 'title' => 'Synthetic private resource',
        'url' => 'synthetic/private/retained.pdf', 'metadata' => json_encode(['private' => true])]);
    foreach ([[$owner->id, 'a', 'linked', null], [$other->id, 'b', 'revoked', now()]] as [$id, $fp, $status, $revoked]) {
        $link = DB::table('mcp_linked_subjects')->insertGetId(['user_id' => $id,
            'identity_fingerprint' => str_repeat($fp, 64), 'provider_key' => 'chatgpt',
            'status' => $status, 'linked_at' => now()->subDay(), 'revoked_at' => $revoked,
            'created_at' => now(), 'updated_at' => now()]);
        $grant = DB::table('mcp_delegated_grants')->insertGetId(['subject_link_id' => $link,
            'user_id' => $id, 'plan_id' => $plans[0], 'client_resource_fingerprint' => str_repeat($fp, 64),
            'scope' => 'overview', 'status' => $status === 'linked' ? 'active' : 'revoked',
            'consented_at' => now()->subHour(), 'expires_at' => now()->addDay(), 'revoked_at' => $revoked,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('mcp_delegated_access_events')->insert(['actor_user_id' => $id,
            'subject_link_id' => $link, 'grant_id' => $grant, 'event_kind' => 'synthetic_retained',
            'scope' => 'overview', 'created_at' => now()]);
    }
    $session = DB::table('study_practice_sessions')->insertGetId(['plan_id' => $plans[0],
        'task_id' => $task, 'user_id' => $owner->id, 'session_token' => (string) Str::uuid(),
        'prepare_request_id' => (string) Str::uuid(), 'status' => 'completed', 'strategy' => 'synthetic',
        'selector_type' => 'synthetic', 'question_provider' => 'external_ai']);
    DB::table('study_practice_attempts')->insert(['plan_id' => $plans[0], 'task_id' => $task,
        'study_practice_session_id' => $session, 'user_id' => $owner->id, 'request_hash' => str_repeat('d', 64),
        'exercise_title' => 'Synthetic retained attempt', 'questions' => json_encode([['id' => 'q1']]),
        'answers' => json_encode([['question_id' => 'q1', 'answer' => 'synthetic']]),
        'assessment' => json_encode(['source' => 'synthetic_unverified']), 'score_percent' => 40,
        'recommended_task_progress_percent' => 35, 'progress_before_percent' => 35,
        'progress_after_percent' => 35, 'applied_at' => now()]);
    DB::table('sessions')->insert(['id' => str_repeat('s', 40), 'user_id' => $owner->id,
        'payload' => base64_encode(serialize(['integration_marker' => 'before_upgrade'])), 'last_activity' => time()]);
    echo "integration20_synthetic_legacy_seeded: pass\n";
    exit(0);
}

if ($mode === 'interrupt') {
    $injected = false;
    DB::listen(function ($event) use (&$injected): void {
        if (! $injected && str_starts_with(strtolower($event->sql), 'create table `learning_context_source_epochs`')) {
            $injected = true;
            throw new RuntimeException('SYNTHETIC_DDL_INTERRUPTION');
        }
    });
    try {
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_15_120000_create_learning_context_source_epochs_table.php',
            '--force' => true, '--no-interaction' => true]);
        throw new RuntimeException('INJECTION_DID_NOT_INTERRUPT');
    } catch (RuntimeException $error) {
        requireFact($error->getMessage() === 'SYNTHETIC_DDL_INTERRUPTION' && $injected, 'EXPECTED_COMMITTED_DDL_INTERRUPTION');
    }
    requireFact(Schema::hasTable('learning_context_source_epochs') && DB::table('migrations')->count() === 113,
        'DDL_COMMITTED_BUT_LEDGER_NOT_APPLIED');
    DB::table('learning_context_source_epochs')->insert([
        'plan_id' => DB::table('plans')->where('category', '資格学習')->value('id'),
        'epoch' => 7, 'last_mutation_kind' => 'synthetic_recovery_seal',
    ]);
    echo "integration20_interrupted_after_committed_create_before_constraints: pass\n";
    exit(0);
}

if ($mode === 'forward-repair') {
    requireFact(DB::table('migrations')->count() === 113, 'FORWARD_REPAIR_REQUIRES_KNOWN_PREFIX');
    requireFact(Schema::getColumnListing('learning_context_source_epochs') ===
        ['id', 'plan_id', 'epoch', 'last_mutation_kind', 'created_at', 'updated_at'], 'FORWARD_REPAIR_EXACT_COLUMNS');
    requireFact(count(Schema::getForeignKeys('learning_context_source_epochs')) === 0
        && count(Schema::getIndexes('learning_context_source_epochs')) === 1, 'FORWARD_REPAIR_EXACT_PARTIAL_SHAPE');
    // Explicit, fixture-only forward repair. No down(), deletion or automatic
    // retry. A real failure requires its own reviewed state-specific repair.
    Schema::table('learning_context_source_epochs', function (Blueprint $table): void {
        $table->unique('plan_id');
        $table->foreign('plan_id')->references('id')->on('plans')->cascadeOnDelete();
    });
    echo "integration20_explicit_disposable_forward_repair: pass\n";
    exit(0);
}

if ($mode === 'snapshot') {
    requireFact(DB::table('migrations')->count() === 94, 'EXPECTED_94_BASELINE');
    $data = [];
    $tables = array_column(Schema::getTables(), 'name');
    foreach ($tables as $table) {
        if ($table === 'migrations') {
            continue;
        }
        $columns = Schema::getColumnListing($table);
        $primary = collect(Schema::getIndexes($table))->first(fn (array $index): bool => $index['primary']);
        requireFact($primary !== null, 'LEGACY_TABLE_PRIMARY_KEY_REQUIRED');
        $query = DB::table($table)->select($columns);
        foreach ($primary['columns'] as $key) {
            $query->orderBy($key);
        }
        $data[$table] = ['columns' => $columns, 'digest' => hash('sha256',
            json_encode($query->get(), JSON_THROW_ON_ERROR)), 'primary' => $primary['columns']];
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
    foreach ($data as $table => $sealed) {
        $query = DB::table($table)->select($sealed['columns']);
        foreach ($sealed['primary'] as $key) {
            $query->orderBy($key);
        }
        $digest = hash('sha256', json_encode($query->get(), JSON_THROW_ON_ERROR));
        requireFact(hash_equals($data[$table]['digest'], $digest), 'HISTORICAL_ROWS_CHANGED_'.$table);
    }
    requireFact(Schema::hasTable('learning_context_source_epochs'), 'TWENTIETH_MIGRATION_MISSING');
    requireFact(DB::table('learning_context_source_epochs')->count() === 1
        && (int) DB::table('learning_context_source_epochs')->sole()->epoch === 7,
        'RECOVERY_MUST_RETAIN_PARTIAL_TABLE_ROWS');
    requireFact(DB::table('learning_context_watch_states')->count() === 0, 'MIGRATION_MUST_NOT_ENABLE_WATCH');
    echo "integration_94_to_114_auth_session_plan_evidence_identity_rows: pass\n";
    exit(0);
}
if ($mode !== 'fences') {
    throw new RuntimeException('INVALID_INTEGRATION_FIXTURE_MODE');
}
$plan = DB::table('plans')->where('category', '個人開発')->sole();
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
foreach ([
    ['plan_id' => DB::table('plans')->where('category', '資格学習')->value('id'), 'epoch' => 8],
    ['plan_id' => 999999999, 'epoch' => 8],
] as $invalid) {
    try {
        DB::table('learning_context_source_epochs')->insert($invalid + ['last_mutation_kind' => 'synthetic_invalid']);
        throw new RuntimeException('SOURCE_EPOCH_UNIQUE_OR_FOREIGN_FENCE_MISSING');
    } catch (QueryException) {
        // Actual engine must reject duplicate Plan epochs and orphan Plan IDs.
    }
}
echo "integration_legacy_writer_epoch_and_identity_fences: pass\n";
