<?php

// Synthetic loopback only. Never bootstrap an existing .env or hosted DB.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || ! str_starts_with((string) getenv('DB_DATABASE'), sys_get_temp_dir().'/canovia-future-')) {
    throw new LogicException('FUTURE_DISPOSABLE_ENV_REQUIRED');
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config()->set('future_support.enabled', true);
config()->set('future_support.allow_loopback_http', true);
config()->set('release_levels.preparation_only', false);
config()->set('session.driver', 'array');
config()->set('cache.default', 'file');
config()->set('cache.stores.file.path', dirname(getenv('DB_DATABASE')).'/cache');
config()->set('app.debug', false);
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
