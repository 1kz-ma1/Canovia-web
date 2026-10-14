<?php
declare(strict_types=1);

use App\Models\InboxItem;
use App\Services\PrivateFilePathFenceService;
use App\Services\PrivateFileUploadIntentService;
use App\Services\PrivateFileUploadService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

if (getenv('CI') !== 'true'
    || getenv('G3_R34_EPHEMERAL_ONLY') !== 'true'
    || getenv('APP_ENV') !== 'testing'
    || getenv('DB_CONNECTION') !== 'mysql'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'canovia_g3_r34_ci'
    || getenv('AWS_ENDPOINT') !== 'http://127.0.0.1:9000'
    || getenv('FILESYSTEM_DISK') !== 's3'
    || getenv('AWS_ACCESS_KEY_ID') !== 'g3_r34_emulator_only'
    || getenv('AWS_SECRET_ACCESS_KEY') !== 'g3_r34_dummy_no_cloud'
    || ! preg_match('/^g3-r34-ci-[0-9]+-[0-9]+$/D', (string) getenv('AWS_BUCKET'))
    || ! preg_match('/^[a-f0-9]{32}$/D', (string) getenv('G3_R34_RUN_ID'))) {
    throw new RuntimeException('R34 isolated CI only');
}
foreach (['AWS_PROFILE', 'AWS_SESSION_TOKEN', 'AWS_WEB_IDENTITY_TOKEN_FILE',
    'AWS_ROLE_ARN', 'AWS_CONTAINER_CREDENTIALS_FULL_URI',
    'AWS_CONTAINER_CREDENTIALS_RELATIVE_URI'] as $key) {
    if (getenv($key)) {
        throw new RuntimeException('R34 cloud credentials denied');
    }
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['canovia.private_file_path_fence_enabled' => true,
    'canovia.private_file_upload_journal_enabled' => true]);
$action = $argv[1] ?? '';
$case = $argv[2] ?? '';
if (! in_array($case, ['prewrite', 'midtransaction', 'postcommit', 'success'], true)
    || ! in_array($action, ['run', 'verify'], true)) {
    throw new InvalidArgumentException('Invalid R34 case');
}
$path = 'inbox/g3-r34/'.getenv('G3_R34_RUN_ID').'/'.$case.'.txt';
$bytes = 'r34-integration-fixture-'.$case;
$journal = app(PrivateFileUploadIntentService::class);
$digest = app(PrivateFilePathFenceService::class)->fingerprint('s3', $path);

if ($action === 'run') {
    if ($case === 'prewrite' || $case === 'postcommit') {
        $journal->prepare('s3', [$digest => $path]);
        if ($case === 'postcommit') {
            DB::transaction(function () use ($path, $bytes): void {
                app(PrivateFilePathFenceService::class)->acquireInTransaction('s3', $path);
                if (! Storage::disk('s3')->put($path, $bytes)) {
                    throw new RuntimeException('R34 storage write failed');
                }
                InboxItem::create(['source_type' => 'text', 'status' => 'new',
                    'storage_path' => $path]);
            });
        }
        echo "CRASH_READY\n";
        flush();
        sleep(60);
    } else {
        $temporary = tempnam(sys_get_temp_dir(), 'r34-');
        file_put_contents($temporary, $bytes);
        try {
            app(PrivateFileUploadService::class)->storeAndPersist([
                ['file' => new UploadedFile($temporary, 'data.txt', 'text/plain', null, true),
                 'path' => $path],
            ], function (array $paths) use ($case): InboxItem {
                $item = InboxItem::create(['source_type' => 'text',
                    'status' => 'new', 'storage_path' => $paths[0]]);
                if ($case === 'midtransaction') {
                    echo "CRASH_READY\n";
                    flush();
                    sleep(60);
                }
                return $item;
            });
        } finally {
            @unlink($temporary);
        }
        echo "SUCCESS\n";
    }
    exit(0);
}

$intent = DB::table('private_file_upload_intents')->where('path_digest', $digest)->first();
$actual = [$intent?->status,
    InboxItem::where('storage_path', $path)->count(),
    DB::table('private_file_path_fences')->where('path_digest', $digest)->count(),
    Storage::disk('s3')->exists($path)];
$expected = match ($case) {
    'prewrite' => ['prepared', 0, 0, false],
    'midtransaction' => ['prepared', 0, 0, true],
    'postcommit' => ['prepared', 1, 1, true],
    'success' => ['committed', 1, 1, true],
};
if ($actual !== $expected
    || ($expected[3] && Storage::disk('s3')->get($path) !== $bytes)) {
    throw new RuntimeException('R34 recovery invariant failed');
}
echo "R34_VERIFY_PASS:".$case."\n";
