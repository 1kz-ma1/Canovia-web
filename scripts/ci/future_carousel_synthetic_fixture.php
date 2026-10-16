<?php

use App\Models\RoadmapFeature;
use App\Models\RoadmapVote;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('CI') !== 'true' || ! $app->environment('testing')
    || getenv('CANOVIA_FUTURE_FIXTURE') !== '1'
    || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== getenv('RUNNER_TEMP').'/synthetic.sqlite'
    || (string) getenv('DB_URL') !== '' || config('app.url') !== 'http://127.0.0.1:8797'
    || getenv('NATIVE_AI_DRIVER') !== 'disabled' || (count($argv) !== 2 || $argv[1] !== '--empty')) {
    fwrite(STDERR, "FUTURE_NONDISPOSABLE_DENIED\n");
    exit(2);
}
// Test-only visibility, never creates/deletes a feature, user or vote.
RoadmapFeature::query()->update(['is_published' => false]);
echo json_encode(['vote_count' => RoadmapVote::count()], JSON_THROW_ON_ERROR).PHP_EOL;
