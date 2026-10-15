<?php

namespace App\Services;

/**
 * V6.23: read-only handoff material for actual independent human reviewers.
 * Neither the generated worksheet nor its candidate rows are a review
 * submission, an official syllabus transcription, or an import authority.
 */
final class StudyOfficialSyllabusReviewWorkbookService
{
    public function __construct(
        private readonly StudyOfficialSyllabusReviewPacketService $packets,
    ) {}

    /** @return array<string,mixed> */
    public function build(string $packSlug): array
    {
        // Reuse V6.21 source-only, exact Pack/Question and publication gates.
        // No database state or question content is written or exported.
        $packet = $this->packets->build($packSlug);

        $rows = [];
        foreach ($packet['question_candidates'] as $candidate) {
            $rows[] = [
                'pack_slug' => $candidate['pack_slug'],
                'pack_version' => $candidate['pack_version'],
                'question_external_key' => $candidate['question_external_key'],
                'question_snapshot_sha256' => $candidate['question_snapshot_sha256'],
                'proposed_scope_code' => null,
                'source_page' => null,
                'source_locator' => null,
                'content_review' => [
                    'reviewer_id' => null,
                    'reviewed_at' => null,
                    'decision' => 'pending',
                ],
                'rights_review' => [
                    'reviewer_id' => null,
                    'reviewed_at' => null,
                    'decision' => 'pending',
                ],
                'rights_evidence_ref' => null,
            ];
        }

        return [
            'schema' => 'canovia.learning.syllabus_review_workbook.v1',
            'official_source' => $packet['official_source'],
            'pack' => $packet['pack'],
            'state' => 'draft_pending_independent_human_reviews',
            'instructions' => [
                'human_transcribe_selected_official_nodes_with_page_and_locator',
                'independent_human_verify_scope_meaning_and_rights_offline',
                'independent_human_match_each_question_to_explicit_scope',
                'manually_prepare_separate_v622_submission_after_actual_reviews',
                'rerun_v622_preflight_on_current_question_snapshots',
            ],
            // Deliberately NOT an accepted V6.22 review submission. The
            // human must add verified scope items, question associations
            // and two independent review decisions before separate preflight.
            'submission_draft' => [
                'schema' => 'canovia.learning.syllabus_review_submission.v1',
                'official_source' => $packet['official_source'],
                'pack' => $packet['pack'],
                'review_stage' => 'draft_pending_review',
                'scope_nodes' => [],
                'question_links' => [],
            ],
            'question_candidate_rows' => $rows,
            'excluded_unsupported_question_count' =>
                $packet['excluded_unsupported_question_count'],
            'official_scope_transcribed' => false,
            'independent_human_review_verified' => false,
            'rights_independently_verified' => false,
            'eligible_for_registry_import' => false,
            'official_coverage_percent' => null,
            'official_coverage_denominator' => null,
            'production_authorized' => false,
            'question_content_included' => false,
            'read_only' => true,
        ];
    }
}
