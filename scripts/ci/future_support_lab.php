<?php

// Synthetic loopback only. Never bootstrap an existing .env or hosted DB.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || ! in_array(getenv('DB_URL'), [false, ''], true)
    || ! str_starts_with((string) getenv('DB_DATABASE'), sys_get_temp_dir().'/canovia-future-')) {
    throw new LogicException('FUTURE_DISPOSABLE_ENV_REQUIRED');
}
$root = dirname(__DIR__, 2);
$db = (string) getenv('DB_DATABASE');
if (is_file($root.'/.env') || is_file($root.'/bootstrap/cache/config.php')
    || getenv('APP_CONFIG_CACHE') || realpath(dirname($db)) !== dirname($db)
    || basename($db) !== 'lab.sqlite' || is_link($db)) {
    throw new LogicException('FUTURE_LAB_FRESH_CHECKOUT_REQUIRED');
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== $db
    || ! in_array(config('database.connections.sqlite.url'), [null, ''], true)
    || config('database.connections.sqlite.read') || config('database.connections.sqlite.write')) {
    throw new LogicException('FUTURE_EFFECTIVE_DISPOSABLE_DB_REQUIRED');
}
config()->set('future_support.enabled', true);
config()->set('future_support.allow_loopback_http', true);
config()->set('release_levels.preparation_only', false);
config()->set('session.driver', 'array');
config()->set('cache.default', 'file');
config()->set('cache.stores.file.path', dirname(getenv('DB_DATABASE')).'/cache');
config()->set('app.debug', false);
// Real parallel lab workers serialize SQLite writers; hosted DB semantics remain separate.
config()->set('database.connections.sqlite.transaction_mode', 'IMMEDIATE');
config()->set('database.connections.sqlite.busy_timeout', 5000);
if (PHP_SAPI === 'cli-server') {
    $app->handleRequest(Illuminate\Http\Request::capture());
    return;
}
if (($argv[1] ?? '') === 'init') {
    if (file_exists(getenv('DB_DATABASE'))) { throw new LogicException('EXCLUSIVE_NEW_DB_REQUIRED'); }
    touch(getenv('DB_DATABASE'));
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    (require __DIR__.'/../../experiments/FutureSupport/database/credentials.php')->up();
    echo "SYNTHETIC_AUTHORITY_INITIALIZED\n";
} else {
    throw new LogicException('UNKNOWN_LAB_COMMAND');
}
