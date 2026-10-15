<?php

namespace App\Console\Commands;

use App\Services\ApExamCandidateRevisionComparisonService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Only locally compares an unapproved v0.4 bundled Draft against manually
 * proposed wording edits. Never creates a Pack, grants approval or deploys.
 */
final class CompareApExamCandidateHumanRevisions extends Command
{
    protected $signature = 'study:ap80-revision-compare
        {--notes= : Exact V6.24 local reviewer notes JSON path}
        {--proposal= : Local unapproved corrections JSON path}';

    protected $description = 'Compare human-requested AP80 Draft wording fixes and reset review proofs';

    public function handle(ApExamCandidateRevisionComparisonService $comparison): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('AP80 revision comparison is only permitted in local/testing.');
            return self::FAILURE;
        }

        try {
            $notes = $this->readLocalJson($this->option('notes'));
            $proposal = $this->readLocalJson($this->option('proposal'));
            $report = $comparison->inspect($notes, $proposal);
            $this->line((string) json_encode($report,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return $report['status'] === 'draft_comparison_rereview_required'
                ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            // No exception string, file paths, editor notes or complete
            // question/explanation/answer text in logs or CLI responses.
            $this->line((string) json_encode([
                'schema' => 'canovia.learning.ap_a_80_revision_comparison.v1',
                'status' => 'blocked',
                'error_codes' => ['ineligible_source_or_invalid_local_json'],
                'new_draft_written' => false,
                'publication_authorized' => false,
                'database_import_allowed' => false,
            ], JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
    }

    /** @return array<string,mixed> */
    private function readLocalJson(mixed $path): array
    {
        if (! is_string($path) || $path === '' || ! is_file($path)
            || ! is_readable($path) || filesize($path) > 1048576) {
            throw new \DomainException('Invalid local review file.');
        }
        $raw = file_get_contents($path);
        if (! is_string($raw) || strlen($raw) > 1048576) {
            throw new \DomainException('Invalid review payload size.');
        }
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new \DomainException('Expected a JSON object.');
        }
        return $decoded;
    }
}
