<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

$mode = $argv[1] ?? '';
if (! in_array($mode, ['seed', 'verify'], true)) {
    fwrite(STDERR, "pinned_mysql19_fixture: blocked (invalid mode)\n");
    exit(2);
}
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (getenv('CI') !== 'true' || getenv('CANOVIA_P0_DISPOSABLE_MYSQL_CI') !== '1'
    || ! app()->environment('testing')
    || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('database.connections.mysql.database') !== 'canovia_p0_ci'
    || config('database.connections.mysql.username') !== 'canovia_ci'
    || (string) config('database.connections.mysql.url', '') !== '') {
    fwrite(STDERR, "pinned_mysql19_fixture: blocked (non-disposable target)\n");
    exit(2);
}

function must(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException("Pinned disposable upgrade check failed: ".$label);
    }
}

must((int) DB::table('migrations')->count() === ($mode === 'seed' ? 94 : 113), 'exact historical/candidate migration count');
$email = 'pinned-upgrade-owner@example.invalid';
$fingerprintActive = str_repeat('a', 64);
$fingerprintRevoked = str_repeat('b', 64);

if ($mode === 'seed') {
    must(! Schema::hasColumn('plans', 'authorization_epoch'), 'legacy Plan column absent');
    must(! Schema::hasColumn('mcp_linked_subjects', 'active_identity_fingerprint'), 'legacy identity column absent');
    $owner = User::factory()->create(['email' => $email]);
    $other = User::factory()->create(['email' => 'pinned-upgrade-member@example.invalid']);
    $plan = Plan::query()->create([
        'user_id' => $owner->id,
        'owner_token' => Str::random(64),
        'public_slug' => (string) Str::uuid(),
        'title' => 'Pinned MySQL19 historical Plan',
        'description' => 'Only synthetic disposable CI data',
        'category' => '個人開発',
        'priority' => 2,
        'priority_mode' => 'manual',
        'start_date' => today(),
        'deadline' => today()->addWeek(),
        'is_collaborative' => true,
        'is_public' => false,
    ]);
    DB::table('plan_members')->insert([
        'plan_id' => $plan->id, 'user_id' => $other->id, 'role' => 'editor',
        'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $artifact = PlanArtifact::query()->create([
        'plan_id' => $plan->id,
        'created_by_user_id' => $owner->id,
        'provider' => 'github',
        'artifact_type' => 'repository',
        'title' => 'Disposable pinned repo',
        'url' => 'https://github.com/example/synthetic-only',
    ]);
    DB::table('development_activity_observations')->insert([
        'plan_id' => $plan->id, 'repository_artifact_id' => $artifact->id,
        'provider' => 'github', 'kind' => 'issue',
        'external_key' => 'ci-pinned-legacy-observation', 'state' => 'open',
        'last_observed_at' => now(), 'resolution_status' => 'unlinked',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([
        [$owner->id, $fingerprintActive, 'linked', null],
        [$other->id, $fingerprintRevoked, 'revoked', now()],
    ] as [$id, $fp, $status, $revoked]) {
        DB::table('mcp_linked_subjects')->insert([
            'user_id' => $id, 'identity_fingerprint' => $fp,
            'provider_key' => 'synthetic_ci', 'status' => $status,
            'linked_at' => now()->subDay(), 'revoked_at' => $revoked,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $activeId = DB::table('mcp_linked_subjects')
        ->where('identity_fingerprint', $fingerprintActive)->value('id');
    DB::table('mcp_delegated_grants')->insert([
        'subject_link_id' => $activeId, 'user_id' => $owner->id, 'plan_id' => $plan->id,
        'client_resource_fingerprint' => str_repeat('c', 64),
        'scope' => 'overview', 'status' => 'active',
        'consented_at' => now()->subDay(),
        'expires_at' => '2030-01-01 00:00:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    echo "pinned_mysql19_old_94_fixture: pass\n";
    exit(0);
}

// This verification runs against the *candidate* code after upgrading the
// SAME MySQL service from the exact live baseline (never an empty-only trial).
$owner = User::query()->where('email', $email)->firstOrFail();
$plan = Plan::query()->where('user_id', $owner->id)
    ->where('title', 'Pinned MySQL19 historical Plan')->firstOrFail();
must((int) $plan->authorization_epoch === 1, 'existing Plan epoch default and row retained');
must((int) DB::table('plan_members')->where('plan_id', $plan->id)->count() === 1, 'member retained');
must((int) DB::table('plan_members')->where('plan_id', $plan->id)->value('authorization_epoch') === 1, 'member epoch default');
$observation = DB::table('development_activity_observations')->where('plan_id', $plan->id)
    ->where('external_key', 'ci-pinned-legacy-observation')->first();
must($observation !== null, 'legacy observation retained');
foreach (['source_updated_at', 'source_revision_id', 'source_epoch'] as $field) {
    must(Schema::hasColumn('development_activity_observations', $field), 'observation new schema '.$field);
    must($observation->{$field} === null, 'new observation value null '.$field);
}
$active = DB::table('mcp_linked_subjects')->where('identity_fingerprint', $fingerprintActive)->first();
$revoked = DB::table('mcp_linked_subjects')->where('identity_fingerprint', $fingerprintRevoked)->first();
must($active !== null && $revoked !== null, 'both historical subjects retained');
must($active->active_identity_fingerprint === $fingerprintActive, 'active fingerprint retained');
must($revoked->active_identity_fingerprint === null, 'revoked fingerprint deactivated');
$names = array_column(Schema::getIndexes('mcp_linked_subjects'), 'name');
must(in_array('mcp_active_subject_identity_unique', $names, true), 'active unique present');
must(in_array('mcp_subject_identity_history_idx', $names, true), 'history index present');
must(! in_array('mcp_linked_subjects_identity_fingerprint_unique', $names, true), 'old unique retired');
$grant = DB::table('mcp_delegated_grants')->where('subject_link_id', $active->id)->first();
must($grant !== null && $grant->status === 'active', 'finite grant unchanged');
must(substr((string) $grant->expires_at, 0, 10) === '2030-01-01', 'finite expiry unchanged');
must(Schema::hasColumn('mcp_delegated_grants', 'expires_at'), 'grant expiry schema exists');
DB::table('plan_members')->where('plan_id', $plan->id)->update(['role' => 'viewer']);
must((int) DB::table('plan_members')->where('plan_id', $plan->id)->value('authorization_epoch') === 2, 'member trigger active');
DB::table('plans')->where('id', $plan->id)->update(['is_collaborative' => false]);
must((int) DB::table('plans')->where('id', $plan->id)->value('authorization_epoch') === 2, 'plan trigger active');
try {
    DB::table('mcp_linked_subjects')->insert([
        'user_id' => $owner->id,
        'identity_fingerprint' => $fingerprintActive, 'active_identity_fingerprint' => $fingerprintActive,
        'provider_key' => 'synthetic_ci', 'status' => 'linked',
        'linked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    must(false, 'duplicate active identity MUST be rejected');
} catch (QueryException $expected) {
    // MySQL unique index correctly denies a second active record.
}
echo "pinned_mysql19_94_to_113_rows_triggers_identity: pass\n";
