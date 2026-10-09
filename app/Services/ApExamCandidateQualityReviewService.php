<?php

namespace App\Services;

/**
 * Read-only handoff from automated AP A checks to independent human review.
 *
 * Evidence about IPA transcription, draft explanations and answer-key
 * structure is useful, but it must never be mistaken for an attested content,
 * educational-quality or usage-rights decision.
 */
final class ApExamCandidateQualityReviewService
{
    public const WORKLIST = 'learning_review/ap-a-2026-v04-independent-review-worklist.json';

    private const REQUIRED_DIMENSIONS = [
        'answer_key_and_domain',
        'incorrect_choice_uniqueness',
        'explanation_accuracy',
        'semantic_overlap_and_difficulty',
        'content_rights_and_attribution',
    ];

    public function __construct(
        private readonly QuestionPackCatalogService $catalog,
        private readonly ApExamCandidateRevisionPreviewService $revision,
    ) {}

    /**
     * @param array<string,mixed>|null $override Mainly useful for testing
     *                                        the fail-closed boundary.
     * @return array<string,mixed>
     */
    public function inspect(?array $override = null): array
    {
        $manifest = $override ?? json_decode((string) file_get_contents(
            resource_path(self::WORKLIST)), true, 512, JSON_THROW_ON_ERROR);
        $candidate = $this->catalog->payload(ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $revision = $this->revision->inspect();
        $items = $candidate['questions'] ?? [];
        $entries = $manifest['entries'] ?? [];
        $issues = [];
        $priorities = ['P0' => 0, 'P1' => 0, 'P2' => 0];
        $origins = ['official' => 0, 'canovia' => 0];
        $proposed = array_fill_keys($revision['changed_keys'], true);

        if (($manifest['candidate_key'] ?? '') !== ApExamCandidateRevisionPreviewService::PREVIEW_KEY
            || ($manifest['candidate_version'] ?? '') !== ($candidate['pack']['version'] ?? '')
            || ($manifest['candidate_slug'] ?? '') !== ($candidate['pack']['slug'] ?? '')
            || ($manifest['base_candidate_key'] ?? '') !== ApExamCandidateRevisionPreviewService::BASE_KEY
            || ($manifest['publication_authorized'] ?? true) !== false) {
            $issues[] = 'Review worksheet is not bound to the correct unpublished v0.4 candidate.';
        }
        if (($manifest['review_dimensions'] ?? null) !== self::REQUIRED_DIMENSIONS
            || ! is_array($entries) || count($items) !== 80 || count($entries) !== 80
            || ($manifest['approval_counts'] ?? null) !== [
                'item_quality_approved' => 0,
                'item_rights_approved' => 0,
                'reviewer_signoffs' => 0,
                'complete_items' => 0,
            ]) {
            $issues[] = 'Review dimensions, item count or zero-approval gate is invalid.';
        }

        $outputItems = [];
        $seen = [];
        foreach ($items as $index => $q) {
            $row = $entries[$index] ?? [];
            $key = (string) ($q['external_key'] ?? '');
            $isOfficial = ($q['source_type'] ?? '') === 'official';
            $isChanged = isset($proposed[$key]);
            $priority = $isChanged ? 'P0' : ($isOfficial ? 'P1' : 'P2');
            $expectedOrigin = $isOfficial ? 'ipa_2025_autumn'
                : ($isChanged ? 'canovia_revised_unapproved' : 'canovia_unrevised_unapproved');
            $expectedSource = (string) data_get($q, 'learning_metadata.curation.source_pack_slug', '');
            if (isset($priorities[$priority])) {
                $priorities[$priority]++;
            }
            $origins[$isOfficial ? 'official' : 'canovia']++;
            if ($key === '' || isset($seen[$key])) {
                $issues[] = "Duplicate candidate key: {$key}.";
            }
            $seen[$key] = true;

            $dimensions = is_array($row['review_checks'] ?? null) ? $row['review_checks'] : [];
            $expectedChecks = array_fill_keys(self::REQUIRED_DIMENSIONS, 'pending');
            if (($row['key'] ?? '') !== $key
                || ($row['number'] ?? null) !== $index + 1
                || ($row['priority'] ?? '') !== $priority
                || ($row['source_type'] ?? '') !== $expectedOrigin
                || ($row['source_pack'] ?? '') !== $expectedSource
                || ($row['review_status'] ?? '') !== 'awaiting_independent_human_content_and_rights_review'
                || ($row['reviewer_id'] ?? null) !== null
                || ($row['reviewed_at'] ?? null) !== null
                || ($row['approval_decision'] ?? null) !== null
                || ($row['content_sha256'] ?? null) !== null
                || $dimensions !== $expectedChecks
                || ! is_array($row['review_tasks'] ?? null)
                || count($row['review_tasks']) !== 5
                || ! is_array($row['machine_evidence'] ?? null)) {
                $issues[] = "Missing, unauthorized approval or stale review row: {$key}.";
            }

            $expectedMachine = $isOfficial
                ? ['2025_ipa_original_question_snapshot_carried',
                    '2025_ipa_official_answer_key_crosscheck']
                : ['canovia_draft_answer_choice_rationale_present'];
            if ($isChanged) {
                $expectedMachine[] = 'unapproved_six_item_wording_proposal_applied';
            }
            if (($row['machine_evidence'] ?? null) !== $expectedMachine
                || ($isChanged && trim((string) ($row['known_caveat'] ?? '')) === '')
                || (! $isChanged && ($row['known_caveat'] ?? null) !== null)) {
                $issues[] = "Incorrect automatic evidence/proposal flag for {$key}.";
            }

            $outputItems[] = [
                'number' => $index + 1,
                'key' => $key,
                'priority' => $priority,
                'source_type' => $expectedOrigin,
                'human_review_pending' => true,
                'reason' => $row['known_caveat'] ?? null,
                'todo' => $row['review_tasks'] ?? [],
                'machine_evidence' => $expectedMachine,
            ];
        }

        if ($priorities !== ['P0' => 6, 'P1' => 35, 'P2' => 39]
            || $origins !== ['official' => 35, 'canovia' => 45]
            || ($manifest['counts']['priority'] ?? null) !== $priorities
            || ($manifest['counts']['total'] ?? null) !== 80
            || ($manifest['counts']['pending_human_review'] ?? null) !== 80
            || ($manifest['counts']['draft_wrong_choice_notes'] ?? null) !== 135
            || ($manifest['counts']['source_transcriptions_carried'] ?? null) !== 35) {
            $issues[] = 'Review priority/origin coverage summary is inconsistent.';
        }
        if (! $revision['structurally_consistent']) {
            $issues[] = 'The underlying v0.4 source diff or content fingerprint failed inspection.';
        }
        if (($candidate['pack']['metadata']['review_state'] ?? '') !== 'pending_human_content_and_rights_review'
            || ($candidate['pack']['metadata']['explanation_review_state'] ?? '') !== 'pending_human_subject_review'
            || isset($candidate['pack']['metadata']['exam_simulation_review'])
            || ($candidate['pack']['downloadable'] ?? true) !== false) {
            $issues[] = 'Candidate is not explicitly locked as unpublished and unapproved.';
        }

        return [
            'candidate_slug' => $candidate['pack']['slug'] ?? '',
            'candidate_version' => $candidate['pack']['version'] ?? '',
            'content_sha256' => $revision['preview_content_sha256'],
            'total' => count($outputItems),
            'priority_counts' => $priorities,
            'source_counts' => $origins,
            'review_checks_pending' => count($outputItems) * count(self::REQUIRED_DIMENSIONS),
            'independent_human_review_pending' => count($outputItems),
            'quality_approved' => 0,
            'rights_approved' => 0,
            'signed_off' => 0,
            'can_publish' => false,
            'structurally_consistent' => $issues === [],
            'issues' => $issues,
            'items' => $outputItems,
        ];
    }
}
