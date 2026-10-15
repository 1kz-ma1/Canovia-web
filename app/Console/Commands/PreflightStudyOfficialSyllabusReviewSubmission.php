<?php

namespace App\Console\Commands;

use App\Services\StudyOfficialSyllabusReviewSubmissionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Strict offline preflight: never reads/writes a production database, never
 * modifies the official registry, and never returns submitted source text.
 */
final class PreflightStudyOfficialSyllabusReviewSubmission extends Command
{
    protected $signature = 'study:syllabus-review-preflight
        {--pack= : Exact published AP subject A Question Pack slug}
        {--review= : Local JSON path to a proposed human review submission}';

    protected $description = 'Validate human-review submission structure without publishing official AP mappings';

    public function handle(StudyOfficialSyllabusReviewSubmissionService $preflight): int
    {
        // For local disposable SQLite snapshots only. An environment name
        // alone cannot guarantee the database is not connected to production.
        if (! app()->environment('local', 'testing')
            || config('database.default') !== 'sqlite') {
            $this->error('Only local/testing SQLite review preflight is permitted.');
            return self::FAILURE;
        }

        $pack = $this->option('pack');
        $path = $this->option('review');
        if (! is_string($pack) || trim($pack) === ''
            || ! is_string($path) || $path === '' || ! is_file($path)
            || ! is_readable($path) || filesize($path) > 262144) {
            $this->error('An exact pack and a readable JSON review file up to 256 KiB are required.');
            return self::FAILURE;
        }

        try {
            $raw = file_get_contents($path);
            if (! is_string($raw) || strlen($raw) > 262144) {
                throw new \RuntimeException('Invalid input.');
            }
            $submission = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($submission) || array_is_list($submission)) {
                throw new \RuntimeException('Invalid JSON document.');
            }
            $report = $preflight->inspect($pack, $submission);
        } catch (Throwable $exception) {
            // No user-authored labels, rights evidence, exception traces,
            // question text or local paths are ever printed.
            $this->line((string) json_encode([
                'schema' => 'canovia.learning.syllabus_review_preflight.v1',
                'status' => 'blocked',
                'error_codes' => ['unreadable_or_ineligible_review'],
                'eligible_for_registry_import' => false,
                'production_authorized' => false,
            ], JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }

        $this->line((string) json_encode(
            $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        return $report['status'] === 'structurally_complete_manual_evidence_review_required'
            ? self::SUCCESS : self::FAILURE;
    }
}
