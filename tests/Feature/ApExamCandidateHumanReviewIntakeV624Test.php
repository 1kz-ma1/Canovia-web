<?php

namespace Tests\Feature;

use App\Services\ApExamCandidateHumanReviewIntakeService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ApExamCandidateHumanReviewIntakeV624Test extends TestCase
{
    private function service(): ApExamCandidateHumanReviewIntakeService
    {
        return app(ApExamCandidateHumanReviewIntakeService::class);
    }

    public function test_all_eighty_unpublished_review_rows_are_pending_and_content_bound(): void
    {
        $workbook = $this->service()->worksheet();

        $this->assertSame('canovia.learning.ap_a_80_review_notes.v1', $workbook['schema']);
        $this->assertSame('ap/ap-a-2026-cbt-80-candidate-v2', $workbook['candidate_key']);
        $this->assertSame('0.4.0-review-required', $workbook['candidate_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',
            $workbook['candidate_content_sha256']);
        $this->assertCount(80, $workbook['entries']);
        $this->assertCount(80, array_unique(array_column($workbook['entries'], 'key')));
        $this->assertFalse($workbook['publication_authorized']);
        $this->assertFalse($workbook['independent_human_review_verified']);
        $this->assertFalse($workbook['rights_independently_verified']);
        $this->assertFalse($workbook['database_import_allowed']);
        $this->assertSame(6, count(array_filter($workbook['entries'],
            fn ($row) => $row['priority'] === 'P0')));
        foreach ($workbook['entries'] as $index => $row) {
            $this->assertSame($index + 1, $row['number']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',
                $row['question_content_sha256']);
            $this->assertSame('pending', $row['review_state']);
            $this->assertCount(5, $row['checks']);
            $this->assertSame(['pending'], array_values(array_unique($row['checks'])));
            $this->assertNull($row['quality_reviewer_id']);
            $this->assertNull($row['rights_reviewer_id']);
            $this->assertNull($row['reviewed_at']);
            $this->assertNull($row['rights_evidence_ref']);
        }
        $json = json_encode($workbook, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('response_schema', $json);
        $this->assertStringNotContainsString('grading_rule', $json);
        $this->assertStringNotContainsString('"prompt":', $json);
        $this->assertStringNotContainsString('"explanation":', $json);
        $this->assertStringNotContainsString('source_reference', $json);
    }

    public function test_unmodified_blank_notes_remain_valid_but_are_never_approved(): void
    {
        $report = $this->service()->inspect($this->service()->worksheet());
        $this->assertSame('notes_structurally_valid_not_approved', $report['status']);
        $this->assertSame([], $report['error_codes']);
        $this->assertSame(80, $report['pending_items']);
        $this->assertSame(0, $report['needs_revision_items']);
        $this->assertSame(0, $report['submitted_unverified_claims']);
        $this->assertFalse($report['independent_human_review_verified']);
        $this->assertFalse($report['rights_independently_verified']);
        $this->assertFalse($report['exam_content_approved']);
        $this->assertFalse($report['publication_authorized']);
        $this->assertFalse($report['database_import_allowed']);
    }

    public function test_stale_hash_missing_item_reordered_item_and_forged_publication_flag_block(): void
    {
        $base = $this->service()->worksheet();

        $wrong = $base;
        $wrong['candidate_content_sha256'] = str_repeat('a', 64);
        $this->assertContains('candidate_version_or_fingerprint_mismatch',
            $this->service()->inspect($wrong)['error_codes']);

        $wrong = $base;
        $wrong['entries'][0]['question_content_sha256'] = str_repeat('b', 64);
        $this->assertContains('stale_or_mismatched_review_item',
            $this->service()->inspect($wrong)['error_codes']);

        $wrong = $base;
        array_pop($wrong['entries']);
        $this->assertContains('invalid_item_count_or_order',
            $this->service()->inspect($wrong)['error_codes']);

        $wrong = $base;
        [$wrong['entries'][0], $wrong['entries'][1]] =
            [$wrong['entries'][1], $wrong['entries'][0]];
        $this->assertContains('stale_or_mismatched_review_item',
            $this->service()->inspect($wrong)['error_codes']);

        $wrong = $base;
        $wrong['publication_authorized'] = true;
        $wrong['approved_by_ai'] = true;
        $this->assertContains('invalid_notes_schema_or_approval_claim',
            $this->service()->inspect($wrong)['error_codes']);
    }

    public function test_partial_revision_notes_are_working_records_not_approvals(): void
    {
        $notes = $this->service()->worksheet();
        $notes['entries'][0]['review_state'] = 'needs_revision';
        $notes['entries'][0]['checks']['explanation_accuracy'] = 'needs_revision';
        $notes['entries'][0]['quality_reviewer_id'] = 'reviewer-1';
        $notes['entries'][0]['reviewed_at'] = today()->toDateString();

        $report = $this->service()->inspect($notes);
        $this->assertSame('notes_structurally_valid_not_approved', $report['status']);
        $this->assertSame(79, $report['pending_items']);
        $this->assertSame(1, $report['needs_revision_items']);
        $this->assertSame(0, $report['submitted_unverified_claims']);
        $this->assertFalse($report['exam_content_approved']);

        $notes['entries'][0]['reviewed_at'] = '2099-12-31';
        $this->assertContains('invalid_revision_review',
            $this->service()->inspect($notes)['error_codes']);
    }

    public function test_claimed_five_checks_and_two_distinct_reviewers_still_need_external_verification(): void
    {
        $notes = $this->service()->worksheet();
        $row = &$notes['entries'][0];
        $row['review_state'] = 'submitted_for_external_verification';
        foreach ($row['checks'] as &$value) {
            $value = 'checked_claim';
        }
        unset($value);
        $row['quality_reviewer_id'] = 'reviewer-1';
        $row['rights_reviewer_id'] = 'rights-reviewer-2';
        $row['reviewed_at'] = today()->toDateString();
        $row['rights_evidence_ref'] = 'SYNTHETIC-REVIEW-REF-01';
        unset($row);

        $report = $this->service()->inspect($notes);
        $this->assertSame('notes_structurally_valid_not_approved', $report['status']);
        $this->assertSame(1, $report['submitted_unverified_claims']);
        $this->assertSame(79, $report['pending_items']);
        $this->assertFalse($report['rights_independently_verified']);
        $this->assertFalse($report['publication_authorized']);

        $notes['entries'][0]['rights_reviewer_id'] = 'reviewer-1';
        $this->assertContains('invalid_unverified_review_claim',
            $this->service()->inspect($notes)['error_codes']);
        $notes['entries'][0]['rights_reviewer_id'] = 'rights-reviewer-2';
        $notes['entries'][0]['rights_evidence_ref'] = null;
        $this->assertContains('invalid_unverified_review_claim',
            $this->service()->inspect($notes)['error_codes']);
    }

    public function test_malformed_dimensions_and_forged_pending_reviewer_are_rejected(): void
    {
        $base = $this->service()->worksheet();
        $base['entries'][1]['checks']['explanation_accuracy'] = ['checked_claim'];
        $this->assertContains('invalid_review_dimensions',
            $this->service()->inspect($base)['error_codes']);

        $base = $this->service()->worksheet();
        $base['entries'][0]['quality_reviewer_id'] = 'fake-signer';
        $this->assertContains('invalid_pending_review',
            $this->service()->inspect($base)['error_codes']);
    }

    public function test_local_cli_exports_pending_and_inspects_json_without_leaking_reviewer_identifiers(): void
    {
        $this->assertSame(0, Artisan::call('study:ap80-review-notes'));
        $template = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(80, $template['entries']);
        $file = tempnam(sys_get_temp_dir(), 'canovia-v624-');
        $this->assertNotFalse($file);
        try {
            $template['entries'][0]['review_state'] = 'needs_revision';
            $template['entries'][0]['quality_reviewer_id'] = 'PRIVATE_REVIEWER_123';
            $template['entries'][0]['reviewed_at'] = today()->toDateString();
            $template['entries'][0]['checks']['explanation_accuracy'] = 'needs_revision';
            file_put_contents($file, json_encode($template, JSON_THROW_ON_ERROR));
            $this->assertSame(0, Artisan::call('study:ap80-review-notes', [
                '--review' => $file,
            ]));
            $summary = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('notes_structurally_valid_not_approved', $summary['status']);
            $this->assertSame(1, $summary['needs_revision_items']);
            $this->assertStringNotContainsString('PRIVATE_REVIEWER_123', Artisan::output());
            file_put_contents($file, '{not valid JSON');
            $this->assertSame(1, Artisan::call('study:ap80-review-notes', [
                '--review' => $file,
            ]));
            $this->assertStringNotContainsString($file, Artisan::output());
        } finally {
            unlink($file);
        }
    }
}
