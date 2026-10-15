<?php

namespace App\Console\Commands;

use App\Services\StudyOfficialSyllabusReviewWorkbookService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Single-shot, local-only human handoff preparation: no file persistence,
 * review approval, public endpoint or Question Pack side effect.
 */
final class ExportStudyOfficialSyllabusReviewWorkbook extends Command
{
    protected $signature = 'study:syllabus-review-workbook
        {--pack= : Exact published AP Subject A Question Pack slug}';

    protected $description = 'Export a blank human-review worksheet and current AP question identity snapshots';

    public function handle(StudyOfficialSyllabusReviewWorkbookService $workbooks): int
    {
        // An environment name alone is not an isolated database guarantee.
        // Forbid non-SQLite access before reading any Pack or review source.
        if (! app()->environment('local', 'testing')
            || config('database.default') !== 'sqlite') {
            $this->error('Human review workbooks require local/testing SQLite.');
            return self::FAILURE;
        }

        $slug = $this->option('pack');
        if (! is_string($slug) || $slug === '') {
            $this->error('An exact published AP Subject A pack slug is required.');
            return self::FAILURE;
        }

        try {
            $workbook = $workbooks->build($slug);
            $json = json_encode($workbook,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            // Do not echo Pack content, question text, local paths or
            // exception messages from potentially private source records.
            $this->error('No eligible source-only review workbook can be prepared.');
            return self::FAILURE;
        }

        $this->line($json);
        return self::SUCCESS;
    }
}
