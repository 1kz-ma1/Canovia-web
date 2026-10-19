<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('CI') !== 'true' || getenv('CANOVIA_P0_DISPOSABLE_MYSQL_CI') !== '1'
    || ! app()->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('database.connections.mysql.database') !== 'canovia_p0_ci'
    || config('database.connections.mysql.username') !== 'canovia_ci'
    || (string) config('database.connections.mysql.url', '') !== '') {
    throw new RuntimeException('TRANSITION_FIXTURE_TARGET_DENIED');
}
$mode = $argv[1] ?? '';
$target = $argv[2] ?? '';
if ($mode === 'verify-legacy') {
    $sealed = json_decode(file_get_contents($target), true, flags: JSON_THROW_ON_ERROR);
    foreach ($sealed as $table => $record) {
        $query = DB::table($table)->select($record['columns']);
        foreach ($record['primary'] as $column) {
            $query->orderBy($column);
        }
        if (! hash_equals($record['digest'], hash('sha256', json_encode($query->get(), JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('TRANSITION_LEGACY_ROWS_CHANGED');
        }
    }
    echo "{\"legacy_rows_retained\":true}\n";
    exit(0);
}
$injected = false;
$ddl = 0;
DB::listen(function ($event) use (&$injected, &$ddl, $mode, $target): void {
    $sql = strtolower(trim($event->sql));
    if (preg_match('/^(create|alter|drop|rename|truncate)\b/', $sql)) {
        $ddl++;
    }
    $match = match ($target) {
        'M03' => str_starts_with($sql, 'alter table `plans` add `authorization_epoch`'),
        'M04' => str_starts_with($sql, 'create trigger canovia_plan_authorization_epoch'),
        'M08_COLUMN' => str_starts_with($sql, 'alter table `mcp_linked_subjects` add `active_identity_fingerprint`'),
        'M08_UNIQUE' => str_starts_with($sql, 'alter table `mcp_linked_subjects` add unique') && str_contains($sql, 'mcp_active_subject_identity_unique'),
        'M08_DROP' => str_starts_with($sql, 'alter table `mcp_linked_subjects` drop index') && str_contains($sql, 'mcp_linked_subjects_identity_fingerprint_unique'),
        'M08_HISTORY' => str_starts_with($sql, 'alter table `mcp_linked_subjects` add index') && str_contains($sql, 'mcp_subject_identity_history_idx'),
        'M18' => str_starts_with($sql, 'create table `mcp_study_result_proposals`'),
        'M19' => str_starts_with($sql, 'create table `learning_context_watch_states`'),
        'M20' => str_starts_with($sql, 'create table `learning_context_source_epochs`'),
        'M20_UNIQUE' => str_starts_with($sql, 'alter table `learning_context_source_epochs` add unique'),
        default => false,
    };
    if ($mode === 'interrupt' && ! $injected && $match) {
        $injected = true;
        throw new RuntimeException('SYNTHETIC_COMMITTED_DDL_STOP');
    }
});
$failed = false;
try {
    $options = ['--force' => true, '--no-interaction' => true];
    if (isset($argv[3])) {
        // Before/after control uses Git-extracted fixed-source files ONLY in
        // this process's guarded disposable target and private temp directory.
        $scope = realpath($argv[3]);
        if ($scope === false || ! str_starts_with($scope, sys_get_temp_dir().'/canovia-transition-private-')) {
            throw new RuntimeException('TRANSITION_SOURCE_SCOPE_DENIED');
        }
        $options['--realpath'] = true;
        $options['--path'] = $scope;
    }
    $code = Artisan::call('migrate', $options);
    $failed = $code !== 0;
} catch (Throwable) {
    $failed = true;
}
if (($mode === 'interrupt' && (! $injected || ! $failed))
    || ($mode === 'block' && (! $failed || $ddl !== 0))
    || ($mode === 'repeat' && ($failed || $ddl !== 0))
    || ! in_array($mode, ['interrupt', 'block', 'repeat'], true)) {
    throw new RuntimeException('TRANSITION_FIXTURE_EXPECTATION_FAILED');
}
echo json_encode(['fixture_verified' => true, 'ddl_count' => $ddl, 'interrupted' => $injected], JSON_THROW_ON_ERROR)."\n";
