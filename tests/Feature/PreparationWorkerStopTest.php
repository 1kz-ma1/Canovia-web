<?php

namespace Tests\Feature;

use App\Services\ReleaseLevelService;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PreparationWorkerStopTest extends TestCase
{
    public function test_runtime_restriction_cannot_be_hidden_by_false_cached_configuration(): void
    {
        $old = getenv('CANOVIA_PREPARATION_ONLY');
        try {
            config(['release_levels.preparation_only' => false]);
            foreach (['true', '(true)', 'invalid', '1'] as $raw) {
                putenv('CANOVIA_PREPARATION_ONLY='.$raw);
                $this->assertTrue(app(ReleaseLevelService::class)->preparationOnly());
            }
            foreach (['false', 'FALSE', '(false)', '(FALSE)'] as $raw) {
                putenv('CANOVIA_PREPARATION_ONLY='.$raw);
                $this->assertFalse(app(ReleaseLevelService::class)->preparationOnly());
            }
            config(['release_levels.preparation_only' => true]);
            $this->assertTrue(app(ReleaseLevelService::class)->preparationOnly(), 'Raw false must not widen restrictive config');
        } finally {
            $old === false ? putenv('CANOVIA_PREPARATION_ONLY') : putenv('CANOVIA_PREPARATION_ONLY='.$old);
        }
    }

    public function test_one_stopped_application_cannot_resume_dispatch_after_config_changes(): void
    {
        config(['release_levels.preparation_only' => true]);
        foreach ([true, false] as $profile) {
            config(['release_levels.preparation_only' => $profile]);
            $ran = false;
            try {
                Bus::dispatchSync(function () use (&$ran): void { $ran = true; });
                $this->fail('Stopped process resumed a handler');
            } catch (\RuntimeException $error) {
                $this->assertSame('PREPARATION_EXECUTION_CLOSED', $error->getMessage());
            }
            $this->assertFalse($ran);
        }
    }

    public function test_queue_stop_is_shared_with_direct_job_handler_after_profile_flip(): void
    {
        config(['release_levels.preparation_only' => true]);
        $job = $this->createMock(\Illuminate\Contracts\Queue\Job::class);
        try {
            event(new \Illuminate\Queue\Events\JobProcessing('synthetic', $job));
            $this->fail('Queue STOP was missed');
        } catch (\RuntimeException $error) {
            $this->assertSame('PREPARATION_QUEUE_CLOSED', $error->getMessage());
        }
        config(['release_levels.preparation_only' => false]);
        $this->expectExceptionMessage('PREPARATION_EXECUTION_CLOSED');
        app()->call([new \App\Jobs\BootstrapGitHubDevelopmentActivity(999), 'handle']);
    }

    public function test_console_stop_cannot_resume_scheduler_in_same_application(): void
    {
        foreach ([true, false] as $profile) {
            config(['release_levels.preparation_only' => $profile]);
            try {
                event(new \Illuminate\Console\Events\CommandStarting('schedule:run',
                    new \Symfony\Component\Console\Input\ArrayInput([]),
                    new \Symfony\Component\Console\Output\BufferedOutput));
                $this->fail('Console STOP was missed');
            } catch (\RuntimeException $error) {
                $this->assertSame('PREPARATION_CONSOLE_CLOSED', $error->getMessage());
            }
        }
    }

    public function test_managed_daemon_exits_before_resolving_broker_or_reserving_old_job(): void
    {
        $manager = $this->createMock(\Illuminate\Queue\QueueManager::class);
        $manager->expects($this->never())->method('connection');
        $worker = new PreparationSyntheticWorker($manager, $this->app['events'],
            $this->app[\Illuminate\Contracts\Debug\ExceptionHandler::class], fn () => false);
        $worker->setCache($this->app['cache']->store('array'));
        $this->app->instance('queue.worker', $worker);
        config(['release_levels.preparation_only' => false]);
        $this->assertNull($this->app['events']->until(new \Illuminate\Queue\Events\Looping('synthetic', 'default')));
        $this->assertFalse($worker->shouldQuit);
        config(['release_levels.preparation_only' => true]);
        $this->assertSame(0, $worker->daemon('synthetic', 'default', new \Illuminate\Queue\WorkerOptions(stopWhenEmpty: true)));
        $this->assertTrue($worker->shouldQuit);
        config(['release_levels.preparation_only' => false]);
        $this->assertFalse($this->app['events']->until(new \Illuminate\Queue\Events\Looping('synthetic', 'default')));
        $this->assertTrue($worker->shouldQuit);
    }

    public function test_independent_synthetic_process_models_stale_cache_stop_and_no_restart(): void
    {
        // No queue daemon, DB, external service or production process is started.
        $program = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['release_levels.preparation_only' => false]);
$results = [];
foreach (['false', 'true', 'false'] as $value) {
    putenv('CANOVIA_PREPARATION_ONLY='.$value);
    $ran = false;
    try {
        Illuminate\Support\Facades\Bus::dispatchNow(function () use (&$ran) { $ran = true; });
        $results[] = $ran ? 'HANDLER_RAN' : 'INVALID';
    } catch (RuntimeException $error) {
        $results[] = $error->getMessage();
    }
}
echo json_encode($results);
PHP;
        $process = new Process([PHP_BINARY, '-r', $program], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'CANOVIA_PREPARATION_ONLY' => 'false',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        ]);
        $process->setTimeout(15)->mustRun();
        $this->assertSame(['HANDLER_RAN', 'PREPARATION_EXECUTION_CLOSED', 'PREPARATION_EXECUTION_CLOSED'],
            json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR));
    }
}

// The framework daemon algorithm is unchanged. Only OS signals/sleep are removed
// from this owned in-memory fixture; any broker resolution fails the test.
final class PreparationSyntheticWorker extends \Illuminate\Queue\Worker
{
    protected function supportsAsyncSignals()
    {
        return false;
    }

    public function sleep($seconds) {}
}
