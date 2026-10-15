<?php

namespace App\Console\Commands;

use App\Services\ApExamCandidateOwnerReviewWavesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Local-only AP80 owner handoff; metadata and content hashes only.
 * Never emits full IPA question text, stored answers or explanations.
 */
final class ShowApExamCandidateOwnerReviewWave extends Command
{
    protected $signature = 'study:ap80-review-wave
        {--batch= : Batch number 1..8; absent lists all eight compact batches}';

    protected $description = 'View 74 unreviewed AP80 question IDs in reproducible, source-bound batches';

    public function handle(ApExamCandidateOwnerReviewWavesService $waves): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Review-wave handoff is available only in local/testing.');
            return self::FAILURE;
        }

        try {
            $option = $this->option('batch');
            if ($option !== null && (! is_string($option)
                || preg_match('/^[1-8]$/D', $option) !== 1)) {
                throw new \DomainException('Invalid batch number.');
            }
            $report = $waves->describe($option === null ? null : (int) $option);
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'schema' => 'canovia.learning.ap_a_80_remaining_review_waves.v1',
                'status' => 'blocked',
                'error_codes' => ['candidate_source_or_owner_packet_mismatch'],
                'independent_human_review_verified' => false,
                'rights_independently_verified' => false,
                'publication_authorized' => false,
                'database_import_allowed' => false,
                'new_draft_written' => false,
            ], JSON_THROW_ON_ERROR));
            return self::FAILURE;
        }
    }
}
