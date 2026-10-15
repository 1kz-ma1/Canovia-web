<?php

namespace App\Console\Commands;

use App\Services\StudyOfficialSyllabusReviewPacketService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Review preparation only. Not accessible by HTTP or the user-facing Study UI.
 */
final class ExportStudyOfficialSyllabusReviewPacket extends Command
{
    protected $signature = 'study:syllabus-review-packet
        {--pack= : Exact slug of an already published AP subject A Question Pack}';

    protected $description = 'Read-only inventory of candidate question hashes for independent AP syllabus review';

    public function handle(StudyOfficialSyllabusReviewPacketService $packets): int
    {
        // Do not make production question inventories available from a routine
        // command, even though the command itself performs no mutations.
        if (! app()->environment('local', 'testing')) {
            $this->error('Review packets can only be prepared in local/testing.');
            return self::FAILURE;
        }

        $slug = $this->option('pack');
        if (! is_string($slug) || $slug === '') {
            $this->error('An exact --pack slug is required.');
            return self::FAILURE;
        }

        try {
            $packet = $packets->build($slug);
        } catch (Throwable $exception) {
            // Do not disclose raw DB exceptions, Question text, or secrets.
            $this->error('No eligible source-only review packet can be prepared.');
            return self::FAILURE;
        }

        $this->line((string) json_encode(
            $packet,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
