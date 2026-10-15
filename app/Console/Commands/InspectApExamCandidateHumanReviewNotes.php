<?php

namespace App\Console\Commands;

use App\Services\ApExamCandidateHumanReviewIntakeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator-only offline review notes. Never imports or approves a Draft Pack.
 */
final class InspectApExamCandidateHumanReviewNotes extends Command
{
    protected $signature = 'study:ap80-review-notes
        {--review= : Optional path to locally prepared human review notes JSON}';

    protected $description = 'Export blank AP A 80 Draft review notes or inspect a local review submission without approval';

    public function handle(ApExamCandidateHumanReviewIntakeService $intake): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('AP80 review notes are local/testing only.');
            return self::FAILURE;
        }

        try {
            $path = $this->option('review');
            if ($path === null) {
                $result = $intake->worksheet();
            } else {
                if (! is_string($path) || $path === '' || ! is_file($path)
                    || ! is_readable($path) || filesize($path) > 262144) {
                    throw new \DomainException('Invalid local file.');
                }
                $raw = file_get_contents($path);
                if (! is_string($raw) || strlen($raw) > 262144) {
                    throw new \DomainException('Invalid local review JSON.');
                }
                $notes = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($notes) || array_is_list($notes)) {
                    throw new \DomainException('Invalid local review object.');
                }
                $result = $intake->inspect($notes);
            }
            $this->line((string) json_encode($result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if ($path !== null && $result['status'] !== 'notes_structurally_valid_not_approved') {
                return self::FAILURE;
            }
        } catch (Throwable $exception) {
            // No personal reviewer ID, draft question content, local paths or
            // exception text are ever echoed to STDOUT or STDERR.
            $this->line((string) json_encode([
                'schema' => 'canovia.learning.ap_a_80_review_notes_preflight.v1',
                'status' => 'blocked',
                'error_codes' => ['ineligible_candidate_or_invalid_input'],
                'publication_authorized' => false,
                'database_import_allowed' => false,
            ], JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
