<?php

namespace Tests\Feature;

use App\Models\QuestionPack;
use App\Services\AdaptiveExamPackReadinessService;
use App\Services\AdaptiveExamProfileRegistry;
use App\Services\ApExamCandidateDraftPreparationService;
use App\Services\ApExamCandidateHumanReviewIntakeService;
use App\Services\ApExamCandidateRevisionComparisonService;
use App\Services\ApExamCandidateRevisionPreviewService;
use App\Services\QuestionPackCatalogService;
use App\Services\QuestionPackImportService;
use App\Services\QuestionPackPublicationReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ApExamCandidateDraftPreparationV626Test extends TestCase
{
    use RefreshDatabase;

    /** @return array{array<string,mixed>,array<string,mixed>} */
    private function sample(): array
    {
        $notes = app(ApExamCandidateHumanReviewIntakeService::class)->worksheet();
        $index = array_search('sec-dkim-023', array_column($notes['entries'], 'key'), true);
        $this->assertNotFalse($index);
        $notes['entries'][$index]['review_state'] = 'needs_revision';
        $notes['entries'][$index]['checks']['explanation_accuracy'] = 'needs_revision';
        $notes['entries'][$index]['quality_reviewer_id'] = 'synthetic-reviewer-v626';
        $notes['entries'][$index]['reviewed_at'] = today()->toDateString();

        $pack = app(QuestionPackCatalogService::class)->payload(
            ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $question = $pack['questions'][$index];
        $proposal = [
            'schema' => 'canovia.learning.ap_a_80_revision_proposal.v1',
            'candidate_key' => $notes['candidate_key'],
            'candidate_version' => $notes['candidate_version'],
            'base_content_sha256' => $notes['candidate_content_sha256'],
            'review_stage' => 'unapproved_revision_comparison',
            'changes' => [[
                'key' => 'sec-dkim-023',
                'base_question_sha256' => $notes['entries'][$index]['question_content_sha256'],
                'reason' => 'Synthetic editorial correction; this is not independent approval.',
                'fields' => [
                    'explanation' => $question['explanation'].'（v0.5の未監修修正草案）',
                ],
            ]],
            'publication_authorized' => false,
        ];
        return [$notes, $proposal];
    }

    public function test_distinct_v05_draft_preserves_ipa_and_all_protected_fields(): void
    {
        [$notes, $proposal] = $this->sample();
        $before = app(QuestionPackCatalogService::class)->payload(
            ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $sourcePath = resource_path('question_packs/ap/ap-a-2026-cbt-80-candidate-v2.json');
        $sourceFileHash = hash_file('sha256', $sourcePath);

        $output = app(ApExamCandidateDraftPreparationService::class)->prepare($notes, $proposal);
        $draft = $output['candidate'];
        $summary = $output['summary'];
        $this->assertSame('1.0', $draft['schema_version']);
        $this->assertSame('ap-a-2026-cbt-80-candidate-v3', $draft['pack']['slug']);
        $this->assertSame('0.5.0-review-required', $draft['pack']['version']);
        $this->assertSame('AP', $draft['pack']['exam_code']);
        $this->assertSame('科目A', $draft['pack']['subject']);
        $this->assertFalse($draft['pack']['downloadable']);
        $this->assertCount(80, $draft['questions']);
        $this->assertCount(80, array_unique(array_column($draft['questions'], 'external_key')));
        $this->assertSame(array_column($before['questions'], 'external_key'),
            array_column($draft['questions'], 'external_key'));
        $this->assertSame(['sec-dkim-023'],
            $draft['pack']['metadata']['revision_changed_keys']);
        $this->assertSame(1,
            $draft['pack']['metadata']['revision_changed_count']);
        $this->assertSame('pending_human_content_and_rights_review',
            $draft['pack']['metadata']['review_state']);
        $this->assertSame('pending_human_subject_review',
            $draft['pack']['metadata']['explanation_review_state']);
        $this->assertSame('all_items_pending_new_version_review',
            $draft['pack']['metadata']['review_evidence_state']);
        $this->assertFalse($draft['pack']['metadata']['prior_revision_evidence_transferred']);
        $this->assertFalse($draft['pack']['metadata']['prior_review_signoffs_transferred']);
        $this->assertArrayNotHasKey('exam_simulation_review', $draft['pack']['metadata']);
        $this->assertArrayNotHasKey('reviewed_content_sha256', $draft['pack']['metadata']);
        $this->assertArrayNotHasKey('revision_proposal_manifest', $draft['pack']['metadata']);
        $this->assertArrayNotHasKey('revision_prompt_changes', $draft['pack']['metadata']);
        $this->assertSame($before['pack']['metadata']['candidate_source_packs'],
            $draft['pack']['metadata']['candidate_source_packs']);

        foreach ($before['questions'] as $index => $question) {
            $after = $draft['questions'][$index];
            $this->assertSame($question['external_key'], $after['external_key']);
            if ($question['external_key'] !== 'sec-dkim-023') {
                $this->assertSame($question, $after);
                continue;
            }
            $this->assertSame($question['prompt'], $after['prompt']);
            $this->assertNotSame($question['explanation'], $after['explanation']);
            foreach (['source_type', 'source_reference', 'response_schema',
                'grading_rule', 'learning_metadata', 'difficulty', 'sort_order',
                'is_active'] as $field) {
                $this->assertSame($question[$field], $after[$field]);
            }
        }

        $comparison = app(ApExamCandidateRevisionComparisonService::class)
            ->inspect($notes, $proposal);
        $this->assertSame('draft_comparison_rereview_required', $comparison['status']);
        $this->assertSame($comparison['hypothetical_content_sha256'],
            $summary['new_draft_content_sha256']);
        $this->assertNotSame($comparison['original_content_sha256'],
            $summary['new_draft_content_sha256']);
        $this->assertSame('new_unapproved_draft_ready_for_manual_file_review',
            $summary['status']);
        $this->assertTrue($summary['all_question_reviews_reset_to_pending']);
        $this->assertTrue($summary['new_source_and_rights_checks_required']);
        foreach (['new_draft_file_saved', 'git_resource_committed', 'database_imported',
            'independent_human_review_verified', 'rights_independently_verified',
            'exam_content_approved', 'publication_authorized',
            'automatic_publication_allowed'] as $flag) {
            $this->assertFalse($summary[$flag]);
        }
        $this->assertSame($sourceFileHash, hash_file('sha256', $sourcePath));
        $this->assertDatabaseCount('question_packs', 0);
    }

    public function test_new_payload_imports_only_as_draft_in_disposable_db_and_publication_stays_blocked(): void
    {
        [$notes, $proposal] = $this->sample();
        $output = app(ApExamCandidateDraftPreparationService::class)->prepare($notes, $proposal);
        // Test explicitly opts into import into an in-memory SQLite fixture;
        // the production workflow and preparation service never import.
        $result = app(QuestionPackImportService::class)->import($output['candidate']);
        $pack = $result['pack'];
        $this->assertSame('draft', $pack->status);
        $this->assertFalse($pack->downloadable);
        $this->assertSame(80, $pack->questions()->count());
        $this->assertFalse(app(QuestionPackPublicationReadinessService::class)
            ->inspect($pack)['publishable']);
        $this->assertDatabaseCount('question_packs', 1);
        $this->assertNull(QuestionPack::where('slug',
            'ap-a-2026-cbt-80-candidate-v2')->first());
        $this->assertNull(QuestionPack::where('slug',
            'ap-a-2026-cbt-80-candidate-v1')->first());
    }

    public function test_invalid_review_or_proposal_and_ipa_edit_cannot_create_new_draft(): void
    {
        [$notes, $proposal] = $this->sample();
        $service = app(ApExamCandidateDraftPreparationService::class);

        $bad = $proposal;
        $bad['base_content_sha256'] = str_repeat('c', 64);
        try {
            $service->prepare($notes, $bad);
            $this->fail('Stale full-content revision must not be packaged.');
        } catch (\DomainException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $bad = $notes;
        $bad['entries'][0]['quality_reviewer_id'] = 'made-up-reviewer';
        $this->expectException(\DomainException::class);
        $service->prepare($bad, $proposal);
    }

    public function test_cli_dry_run_does_not_write_and_explicit_write_is_private_exclusive(): void
    {
        [$notes, $proposal] = $this->sample();
        $notesFile = tempnam(sys_get_temp_dir(), 'ap80-v626-n-');
        $proposalFile = tempnam(sys_get_temp_dir(), 'ap80-v626-p-');
        $this->assertNotFalse($notesFile);
        $this->assertNotFalse($proposalFile);
        $generated = null;
        try {
            file_put_contents($notesFile, json_encode($notes, JSON_THROW_ON_ERROR));
            file_put_contents($proposalFile, json_encode($proposal, JSON_THROW_ON_ERROR));
            $options = ['--notes' => $notesFile, '--proposal' => $proposalFile];

            $this->assertSame(0, Artisan::call('study:ap80-prepare-new-draft', $options));
            $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertFalse($report['new_draft_file_saved']);
            $this->assertFalse($report['database_imported']);
            $this->assertStringNotContainsString('未監修修正草案', Artisan::output());
            $this->assertStringNotContainsString('synthetic-reviewer-v626', Artisan::output());
            $this->assertDatabaseCount('question_packs', 0);

            $this->assertSame(0, Artisan::call('study:ap80-prepare-new-draft',
                $options + ['--write' => true]));
            $saved = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertTrue($saved['new_draft_file_saved']);
            $this->assertFalse($saved['database_imported']);
            $this->assertFalse($saved['publication_authorized']);
            $this->assertMatchesRegularExpression(
                '/^ap-a-2026-cbt-80-candidate-v3-[0-9a-f]{16}\.json$/D',
                $saved['private_file_name'],
            );
            $generated = storage_path('app/private/learning-ap80-draft-previews/'
                .$saved['private_file_name']);
            $this->assertFileExists($generated);
            $created = json_decode(file_get_contents($generated), true, 512,
                JSON_THROW_ON_ERROR);
            $this->assertSame('0.5.0-review-required', $created['pack']['version']);
            $this->assertFalse($created['pack']['downloadable']);
            $this->assertSame('pending_human_content_and_rights_review',
                $created['pack']['metadata']['review_state']);

            // A second attempt must not overwrite a previously inspected file.
            $previousHash = hash_file('sha256', $generated);
            $this->assertSame(1, Artisan::call('study:ap80-prepare-new-draft',
                $options + ['--write' => true]));
            $this->assertSame($previousHash, hash_file('sha256', $generated));
            $this->assertStringNotContainsString($notesFile, Artisan::output());
            $this->assertStringNotContainsString('未監修修正草案', Artisan::output());
        } finally {
            if (is_string($generated) && is_file($generated)) {
                unlink($generated);
            }
            unlink($notesFile);
            unlink($proposalFile);
        }
    }

    public function test_cli_rejects_broken_local_proposal_without_side_effects(): void
    {
        [$notes] = $this->sample();
        $notesFile = tempnam(sys_get_temp_dir(), 'ap80-v626-n-');
        $proposalFile = tempnam(sys_get_temp_dir(), 'ap80-v626-p-');
        try {
            file_put_contents($notesFile, json_encode($notes, JSON_THROW_ON_ERROR));
            file_put_contents($proposalFile, '{not valid JSON');
            $this->assertSame(1, Artisan::call('study:ap80-prepare-new-draft', [
                '--notes' => $notesFile,
                '--proposal' => $proposalFile,
                '--write' => true,
            ]));
            $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('blocked', $result['status']);
            $this->assertFalse($result['new_draft_file_saved']);
            $this->assertFalse($result['database_imported']);
            $this->assertFalse($result['publication_authorized']);
            $this->assertStringNotContainsString($notesFile, Artisan::output());
        } finally {
            unlink($notesFile);
            unlink($proposalFile);
        }
    }
}
