<?php

/**
 * Synthetic existing account for the disposable HTTP session CI only.
 * No production data, config changes or credential dumps.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$actual = realpath((string) config('database.connections.sqlite.database'));
$required = realpath(database_path('p0_auth_http_ci.sqlite'));

if (getenv('CANOVIA_P0_DISPOSABLE_HTTP_CI') !== '1'
    || $actual === false
    || $required === false
    || $actual !== $required
    || app()->environment() !== 'testing'
    || config('database.default') !== 'sqlite'
    || config('session.driver') !== 'database'
    || (bool) config('session.secure') !== false) {
    throw new RuntimeException('P0 disposable HTTP seed refused unsafe environment.');
}

if (DB::table('users')->where('email', 'p0-http-ci@example.com')->exists()) {
    throw new RuntimeException('P0 disposable HTTP seed must only be invoked once.');
}

DB::table('users')->insert([
    'name' => 'Synthetic HTTP CI Only',
    'email' => 'p0-http-ci@example.com',
    'email_verified_at' => now(),
    'password' => Hash::make('synthetic-http-ci-password'),
    'first_run_completed_at' => now()->subDay(),
    'created_at' => now(),
    'updated_at' => now(),
]);

echo "p0_synthetic_http_account_seeded: pass\n";
