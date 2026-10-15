<?php

namespace Tests\Feature;

use App\Services\ApExamCandidateHumanReviewIntakeService;
use App\Services\ApExamCandidateRevisionComparisonService;
use App\Services\ApExamCandidateRevisionPreviewService;
use App\Services\QuestionPackCatalogService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ApExamCandidateRevisionComparisonV625Test extends TestCase
{
    private function service(): ApExamCandidateRevisionComparisonService
    {
        return app(ApExamCandidateRevisionComparisonService::class);
    }

    /** @return array{array<string,mixed>,array<string,mixed>} */
    private function example(string $key = 'sec-dkim-023'): array
    {
        $notes = app(ApExamCandidateHumanReviewIntakeService::class)->worksheet();
        $index = array_search($key, array_column($notes['entries'], 'key'), true);
        $this->assertNotFalse($index);
        $notes['entries'][$index]['review_state'] = 'needs_revision';
        $notes['entries'][$index]['checks']['explanation_accuracy'] = 'needs_revision';
        $notes['entries'][$index]['quality_reviewer_id'] = 'synthetic-reviewer-1';
        $notes['entries'][$index]['reviewed_at'] = today()->toDateString();

        $source = app(QuestionPackCatalogService::class)
            ->payload(ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $question = $source['questions'][$index];
        $proposal = [
            'schema' => 'canovia.learning.ap_a_80_revision_proposal.v1',
            'candidate_key' => $notes['candidate_key'],
            'candidate_version' => $notes['candidate_version'],
            'base_content_sha256' => $notes['candidate_content_sha256'],
            'review_stage' => 'unapproved_revision_comparison',
            'changes' => [[
                'key' => $key,
                'base_question_sha256' => $notes['entries'][$index]['question_content_sha256'],
                'reason' => 'Synthetic explanation clarity review; not a signoff.',
                'fields' => [
                    'explanation' => $question['explanation'].'（独立審査前の未承認提案）',
                ],
            ]],
            'publication_authorized' => false,
        ];

        return [$notes, $proposal];
    }

    public function test_valid_revision_yields_before_after_hashes_and_five_fresh_review_dimensions(): void
    {
        [$notes, $proposal] = $this->example();
        $path = resource_path('question_packs/ap/ap-a-2026-cbt-80-candidate-v2.json');
        $beforeFile = hash_file('sha256', $path);

        $report = $this->service()->inspect($notes, $proposal);
        $this->assertSame('draft_comparison_rereview_required', $report['status']);
        $this->assertSame([], $report['error_codes']);
        $this->assertSame(1, $report['revision_items_requested']);
        $this->assertSame(1, $report['revision_items_compared']);
        $this->assertSame($notes['candidate_content_sha256'],
            $report['original_content_sha256']);
        $this->assertNotSame($report['original_content_sha256'],
            $report['hypothetical_content_sha256']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',
            $report['hypothetical_content_sha256']);
        $this->assertCount(1, $report['differences']);
        $diff = $report['differences'][0];
        $this->assertSame('sec-dkim-023', $diff['key']);
        $this->assertSame('P0', $diff['priority']);
        $this->assertSame(['explanation'], $diff['changed_fields']);
        $this->assertNotSame($diff['before_question_sha256'], $diff['proposed_question_sha256']);
        $this->assertSame('pending_full_independent_rereview', $diff['review_reset_to']);
        $this->assertCount(5, $diff['required_rechecks']);
        $this->assertFalse($diff['old_review_transferred']);
        $this->assertFalse($report['new_draft_written']);
        $this->assertFalse($report['existing_candidate_changed']);
        $this->assertFalse($report['human_rereview_verified']);
        $this->assertFalse($report['rights_independently_verified']);
        $this->assertFalse($report['exam_content_approved']);
        $this->assertFalse($report['publication_authorized']);
        $this->assertFalse($report['database_import_allowed']);
        $this->assertTrue($report['read_only']);
        $this->assertSame($beforeFile, hash_file('sha256', $path));

        $serialized = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('独立審査前', $serialized);
        $this->assertStringNotContainsString('Synthetic explanation clarity review', $serialized);
        $this->assertStringNotContainsString('\"prompt\":', $serialized);
        $this->assertStringNotContainsString('\"explanation\":', $serialized);
    }

    public function test_ipa_source_is_immutable_even_when_notes_request_changes(): void
    {
        [$notes, $proposal] = $this->example('ipa-2025-autumn-ap-am-q03');
        $report = $this->service()->inspect($notes, $proposal);
        $this->assertSame('blocked', $report['status']);
        $this->assertContains('official_or_unowned_source_is_immutable', $report['error_codes']);
        $this->assertNull($report['hypothetical_content_sha256']);
        $this->assertSame([], $report['differences']);
    }

    public function test_stale_revision_and_question_hash_are_rejected(): void
    {
        [$notes, $proposal] = $this->example();

        $bad = $proposal;
        $bad['base_content_sha256'] = str_repeat('a', 64);
        $result = $this->service()->inspect($notes, $bad);
        $this->assertContains('stale_or_wrong_candidate_revision', $result['error_codes']);

        $bad = $proposal;
        $bad['changes'][0]['base_question_sha256'] = str_repeat('b', 64);
        $this->assertContains('stale_question_snapshot',
            $this->service()->inspect($notes, $bad)['error_codes']);

        $badNotes = $notes;
        $badNotes['candidate_content_sha256'] = str_repeat('c', 64);
        $this->assertContains('stale_or_invalid_review_notes',
            $this->service()->inspect($badNotes, $proposal)['error_codes']);
    }

    public function test_missing_or_extra_requested_edits_and_duplicate_edits_block(): void
    {
        [$notes, $proposal] = $this->example();

        $bad = $proposal;
        $bad['changes'] = [];
        $this->assertContains('missing_or_extra_revision_proposals',
            $this->service()->inspect($notes, $bad)['error_codes']);

        $bad = $proposal;
        $bad['changes'][] = $bad['changes'][0];
        $result = $this->service()->inspect($notes, $bad);
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('unrequested_or_duplicate_revision', $result['error_codes']);
        $this->assertSame([], $result['differences']);

        $bad = $proposal;
        $bad['changes'][0]['key'] = 'no-such-question';
        $this->assertContains('unrequested_or_duplicate_revision',
            $this->service()->inspect($notes, $bad)['error_codes']);
    }

    public function test_noop_and_unauthorized_correct_answer_option_edits_are_rejected(): void
    {
        [$notes, $proposal] = $this->example();
        $candidate = app(QuestionPackCatalogService::class)
            ->payload(ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $key = $proposal['changes'][0]['key'];
        $original = collect($candidate['questions'])->firstWhere('external_key', $key);

        $bad = $proposal;
        $bad['changes'][0]['fields']['explanation'] = $original['explanation'];
        $this->assertContains('empty_overlong_or_unchanged_edit',
            $this->service()->inspect($notes, $bad)['error_codes']);

        $bad = $proposal;
        $bad['changes'][0]['fields']['grading_rule'] = ['answer' => 'ア'];
        $this->assertContains('invalid_revision_fields_or_reason',
            $this->service()->inspect($notes, $bad)['error_codes']);

        $bad = $proposal;
        $bad['changes'][0]['fields']['response_schema'] = [];
        $this->assertContains('invalid_revision_fields_or_reason',
            $this->service()->inspect($notes, $bad)['error_codes']);

        $bad = $proposal;
        $bad['changes'][0]['fields']['prompt'] = str_repeat('a', 12001);
        $this->assertContains('empty_overlong_or_unchanged_edit',
            $this->service()->inspect($notes, $bad)['error_codes']);
    }

    public function test_forged_approval_and_review_fields_are_rejected(): void
    {
        [$notes, $proposal] = $this->example();

        $bad = $proposal;
        $bad['publication_authorized'] = true;
        $bad['human_review_complete'] = true;
        $this->assertContains('invalid_proposal_schema_or_approval_claim',
            $this->service()->inspect($notes, $bad)['error_codes']);

        $bad = $proposal;
        $bad['changes'][0]['rights_approved'] = true;
        $this->assertContains('invalid_edit_schema',
            $this->service()->inspect($notes, $bad)['error_codes']);

        $bad = $proposal;
        $bad['changes'][0]['fields']['explanation'] = null;
        $this->assertContains('empty_overlong_or_unchanged_edit',
            $this->service()->inspect($notes, $bad)['error_codes']);
    }

    public function test_local_cli_reports_only_hash_diffs_and_blocks_invalid_json(): void
    {
        [$notes, $proposal] = $this->example();
        $notesFile = tempnam(sys_get_temp_dir(), 'ap80-v625-notes-');
        $proposalFile = tempnam(sys_get_temp_dir(), 'ap80-v625-proposal-');
        $this->assertNotFalse($notesFile);
        $this->assertNotFalse($proposalFile);

        try {
            file_put_contents($notesFile, json_encode($notes, JSON_THROW_ON_ERROR));
            file_put_contents($proposalFile, json_encode($proposal, JSON_THROW_ON_ERROR));
            $this->assertSame(0, Artisan::call('study:ap80-revision-compare', [
                '--notes' => $notesFile, '--proposal' => $proposalFile,
            ]));
            $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('draft_comparison_rereview_required', $result['status']);
            $this->assertFalse($result['publication_authorized']);
            $this->assertStringNotContainsString('独立審査前', Artisan::output());
            $this->assertStringNotContainsString($proposalFile, Artisan::output());

            file_put_contents($proposalFile, '{bad JSON');
            $this->assertSame(1, Artisan::call('study:ap80-revision-compare', [
                '--notes' => $notesFile, '--proposal' => $proposalFile,
            ]));
            $this->assertStringNotContainsString($proposalFile, Artisan::output());
            $this->assertStringNotContainsString('独立審査前', Artisan::output());
        } finally {
            unlink($notesFile);
            unlink($proposalFile);
        }
    }
}
