<?php

namespace Tests\Feature;

use App\Models\QuestionPack;
use App\Services\ApExamCandidateDraftPreparationService;
use App\Services\ApExamCandidateHumanReviewIntakeService;
use App\Services\ApExamCandidateRevisionComparisonService;
use App\Services\ApExamCandidateRevisionPreviewService;
use App\Services\QuestionPackCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V6.27 is an editorial packet, not a V6.24 human signoff or a published Pack.
 * Synthetic local reviewer notes are ONLY test fixtures to exercise V6.25/26.
 */
final class ApExamCandidateOwnerEditorialPacketV627Test extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,mixed> */
    private function packet(): array
    {
        $raw = file_get_contents(base_path(
            'docs/learning/review_packets/AP_A_80_OWNER_FIRST_PASS_6Q_V627_2026_10_15.json'));
        $this->assertIsString($raw);

        return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    }

    public function test_six_decisions_are_bound_to_unchanged_v04_source_and_never_approve(): void
    {
        $packet = $this->packet();
        $sourcePath = resource_path('question_packs/ap/ap-a-2026-cbt-80-candidate-v2.json');
        $raw = file_get_contents($sourcePath);
        $this->assertIsString($raw);
        // Git blob ID: exact bytes + Git blob header, not a model-invented hash.
        $blob = sha1('blob '.strlen($raw)."\0".$raw);
        $this->assertSame($packet['base_source_git_blob_sha1'], $blob);
        $original = app(QuestionPackCatalogService::class)
            ->payload(ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $this->assertSame($packet['candidate_slug'], $original['pack']['slug']);
        $this->assertSame($packet['candidate_version'], $original['pack']['version']);
        $this->assertSame('ap/ap-a-2026-cbt-80-candidate-v2',
            $packet['candidate_key']);
        $this->assertSame('owner_submitted_ai_assisted_first_pass_only',
            $packet['packet_status']);
        $this->assertSame('editorial_planning_only_not_v624_review_notes_and_not_v625_revision_proposal',
            $packet['applicability']);
        foreach (['quality_reviewer_authenticated', 'rights_reviewer_authenticated',
            'approval_evidence_attached', 'publication_authorized', 'database_import_allowed',
            'independent_human_review_verified', 'rights_independently_verified'] as $flag) {
            $this->assertFalse($packet[$flag]);
        }

        $expected = [
            'db-view-009' => [13, 'ア', false],
            'calc-mm1-015' => [24, 'イ', false],
            'sec-dkim-023' => [34, 'ア', true],
            'quality-reliability-032' => [48, 'ア', true],
            'test-boundary-043' => [61, 'イ', true],
            'sec-csrf-047' => [68, 'ア', true],
        ];
        $this->assertCount(6, $packet['review_items']);
        $this->assertSame(array_keys($expected),
            array_column($packet['review_items'], 'key'));
        $sourceKeys = array_column($original['questions'], 'external_key');
        foreach ($packet['review_items'] as $row) {
            $key = $row['key'];
            $this->assertArrayHasKey($key, $expected);
            $index = array_search($key, $sourceKeys, true);
            $this->assertNotFalse($index);
            $this->assertSame($expected[$key][0], $index + 1);
            $this->assertSame($index + 1, $row['number']);
            $this->assertSame($original['questions'][$index]['grading_rule']['answer'],
                $row['correct_answer']);
            $this->assertSame($expected[$key][1], $row['correct_answer']);
            $this->assertTrue($row['answer_unchanged']);
            $this->assertSame($expected[$key][2], $row['text_revision_required']);
        }
        $this->assertSame(35, collect($original['questions'])
            ->where('source_type', 'official')->count());
        $this->assertDatabaseCount('question_packs', 0);
        $this->assertSame($blob,
            sha1('blob '.strlen(file_get_contents($sourcePath))."\0".file_get_contents($sourcePath)));
    }

    public function test_all_four_text_only_proposals_pass_v625_and_produce_separate_unapproved_v626_preview(): void
    {
        $packet = $this->packet();
        $notes = app(ApExamCandidateHumanReviewIntakeService::class)->worksheet();
        $base = app(QuestionPackCatalogService::class)
            ->payload(ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $byKey = collect($base['questions'])->keyBy('external_key');
        $required = ['sec-dkim-023', 'quality-reliability-032',
            'test-boundary-043', 'sec-csrf-047'];
        $this->assertSame($required,
            array_column($packet['text_revision_proposals'], 'key'));

        $changes = [];
        foreach ($packet['text_revision_proposals'] as $proposal) {
            $this->assertSame(['key', 'reason', 'fields'],
                array_keys($proposal));
            $key = $proposal['key'];
            $old = $byKey->get($key);
            $this->assertIsArray($old);
            $this->assertNotSame('official', $old['source_type']);
            $this->assertSame(['prompt', 'explanation'],
                array_keys($proposal['fields']));
            $this->assertNotSame($old['prompt'], $proposal['fields']['prompt']);
            $this->assertNotSame($old['explanation'], $proposal['fields']['explanation']);

            $index = array_search($key, array_column($notes['entries'], 'key'), true);
            $this->assertNotFalse($index);
            // Synthetic, explicitly NOT authenticated. No notes/proposal JSON
            // is persisted from these values or counted as a human review.
            $notes['entries'][$index]['review_state'] = 'needs_revision';
            $notes['entries'][$index]['checks']['explanation_accuracy'] = 'needs_revision';
            $notes['entries'][$index]['quality_reviewer_id'] = 'synthetic-fixture-v627';
            $notes['entries'][$index]['reviewed_at'] = today()->toDateString();
            $changes[] = [
                'key' => $key,
                'base_question_sha256' =>
                    $notes['entries'][$index]['question_content_sha256'],
                'reason' => $proposal['reason'],
                'fields' => $proposal['fields'],
            ];
        }

        $v625Proposal = [
            'schema' => 'canovia.learning.ap_a_80_revision_proposal.v1',
            'candidate_key' => $notes['candidate_key'],
            'candidate_version' => $notes['candidate_version'],
            'base_content_sha256' => $notes['candidate_content_sha256'],
            'review_stage' => 'unapproved_revision_comparison',
            'changes' => $changes,
            'publication_authorized' => false,
        ];
        $comparison = app(ApExamCandidateRevisionComparisonService::class)
            ->inspect($notes, $v625Proposal);
        $this->assertSame([], $comparison['error_codes']);
        $this->assertSame('draft_comparison_rereview_required', $comparison['status']);
        $this->assertCount(4, $comparison['differences']);
        foreach ($comparison['differences'] as $difference) {
            $this->assertFalse($difference['old_review_transferred']);
            $this->assertSame('pending_full_independent_rereview',
                $difference['review_reset_to']);
            $this->assertCount(5, $difference['required_rechecks']);
        }
        $prepared = app(ApExamCandidateDraftPreparationService::class)
            ->prepare($notes, $v625Proposal);
        $draft = $prepared['candidate'];
        $this->assertSame('ap-a-2026-cbt-80-candidate-v3', $draft['pack']['slug']);
        $this->assertSame('0.5.0-review-required', $draft['pack']['version']);
        $this->assertFalse($draft['pack']['downloadable']);
        $this->assertSame('pending_human_content_and_rights_review',
            $draft['pack']['metadata']['review_state']);
        $this->assertSame('all_items_pending_new_version_review',
            $draft['pack']['metadata']['review_evidence_state']);
        $this->assertCount(80, $draft['questions']);
        $this->assertSame($comparison['hypothetical_content_sha256'],
            $prepared['summary']['new_draft_content_sha256']);

        foreach ($base['questions'] as $i => $question) {
            $updated = $draft['questions'][$i];
            $this->assertSame($question['external_key'], $updated['external_key']);
            if (! in_array($question['external_key'], $required, true)) {
                $this->assertSame($question, $updated);
                continue;
            }
            foreach (['source_type', 'source_reference', 'response_schema',
                'grading_rule', 'learning_metadata', 'difficulty', 'sort_order',
                'is_active'] as $immutable) {
                $this->assertSame($question[$immutable], $updated[$immutable]);
            }
        }
        $this->assertSame(35, collect($draft['questions'])
            ->where('source_type', 'official')->count());
        $this->assertFalse($prepared['summary']['database_imported']);
        $this->assertFalse($prepared['summary']['publication_authorized']);
        $this->assertFalse($prepared['summary']['new_draft_file_saved']);
        $this->assertDatabaseCount('question_packs', 0);
    }

    public function test_six_new_questions_are_unique_four_choice_unapproved_candidates_not_imported(): void
    {
        $packet = $this->packet();
        $extras = $packet['new_question_candidates'];
        $this->assertCount(6, $extras);
        $this->assertCount(6, array_unique(array_column($extras, 'id')));
        $this->assertSame(array_column($packet['review_items'], 'key'),
            array_column($extras, 'parent_key'));
        $source = app(QuestionPackCatalogService::class)
            ->payload(ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $existing = array_column($source['questions'], 'external_key');
        $correctAnswers = ['ア', 'イ', 'エ', 'イ', 'イ', 'ウ'];
        foreach ($extras as $i => $extra) {
            $this->assertNotContains($extra['id'], $existing);
            $this->assertSame($correctAnswers[$i], $extra['answer']);
            $this->assertContains($extra['purpose'],
                ['exam_application', 'weakness_diagnosis']);
            $this->assertIsString($extra['target_skill']);
            $this->assertGreaterThan(12, mb_strlen($extra['prompt']));
            $this->assertGreaterThan(18, mb_strlen($extra['explanation']));
            $this->assertCount(4, $extra['choices']);
            $this->assertSame(['ア','イ','ウ','エ'],
                array_column($extra['choices'], 0));
            $this->assertCount(4, array_unique(array_column($extra['choices'], 1)));
            foreach ($extra['choices'] as $choice) {
                $this->assertIsString($choice[1]);
                $this->assertNotSame('', trim($choice[1]));
            }
            foreach ($extra['sources'] as $url) {
                $this->assertStringStartsWith('https://', $url);
            }
        }
        $this->assertStringContainsString('5件/秒', $extras[1]['explanation']);
        $this->assertStringContainsString('異なる組織ドメイン', $extras[2]['explanation']);
        $this->assertStringContainsString('8値', $extras[4]['explanation']);
        $this->assertStringContainsString('POSTは安全なメソッドではなく', $extras[5]['explanation']);
        foreach ($packet['review_and_publication_guards'] as $name => $flag) {
            if (str_contains($name, 'unchanged') || str_starts_with($name, 'not_')
                || str_starts_with($name, 'pending_') || str_starts_with($name, 'recheck_')
                || str_starts_with($name, 'all_') || $name === 'does_not_mutate_database'
                || $name === 'does_not_populate_v624_notes'
                || $name === 'new_questions_are_not_question_pack_import_json') {
                $this->assertTrue($flag, "Unapproved editorial boundary: {$name}");
            }
        }
        $this->assertFalse($packet['publication_authorized']);
        $this->assertDatabaseCount('question_packs', 0);
        $this->assertNull(QuestionPack::query()->first());
    }
}
