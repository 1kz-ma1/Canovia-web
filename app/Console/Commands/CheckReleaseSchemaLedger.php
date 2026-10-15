<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

final class CheckReleaseSchemaLedger extends Command
{
    protected $signature = 'canovia:release-schema-ready';

    protected $description = 'Read-only migration ledger/source fence; not physical-schema or release approval';

    public function handle(Migrator $migrator): int
    {
        try {
            $repository = $migrator->getRepository();
            if (! $repository->repositoryExists()) {
                $this->error('RELEASE_SCHEMA_MISSING_LEDGER');

                return self::FAILURE;
            }
            $source = array_keys($migrator->getMigrationFiles([
                ...$migrator->paths(), database_path('migrations'),
            ]));
            $ran = $repository->getRan();
            if ($source === [] || count($ran) !== count(array_unique($ran)) || array_diff($ran, $source)) {
                $this->error('RELEASE_SCHEMA_UNKNOWN_HISTORY');

                return self::FAILURE;
            }
            if (array_diff($source, $ran)) {
                $this->error('RELEASE_SCHEMA_PENDING');

                return self::FAILURE;
            }
        } catch (Throwable) {
            // DB exceptions may contain credentials, SQL or private hostnames.
            $this->error('RELEASE_SCHEMA_UNAVAILABLE');

            return self::FAILURE;
        }
        $this->info('RELEASE_SCHEMA_LEDGER_CURRENT_NOT_RELEASE_GO');

        return self::SUCCESS;
    }
}
