<?php

namespace App\Services;

use App\Models\Question;
use DomainException;

/**
 * V6.26: build a separate, still-unreviewed Draft JSON from V6.25-valid
 * proposed Canovia edits. No DB insert, existing Pack update or release.
 */
final class ApExamCandidateDraftPreparationService
{
    public const NEXT_SLUG = 'ap-a-2026-cbt-80-candidate-v3';
    public const NEXT_VERSION = '0.5.0-review-required';

    public function __construct(
        private readonly ApExamCandidateRevisionComparisonService $comparison,
        private readonly QuestionPackCatalogService $catalog,
        private readonly QuestionPackImportService $importer,
        private readonly AdaptiveExamPackReadinessService $exam,
    ) {}

    /**
     * @param array<string,mixed> $notes
     * @param array<string,mixed> $proposal
     * @return array{candidate:array<string,mixed>,summary:array<string,mixed>}
     */
    public function prepare(array $notes, array $proposal): array
    {
        $comparison = $this->comparison->inspect($notes, $proposal);
        if (($comparison['status'] ?? null) !== 'draft_comparison_rereview_required'
            || ($comparison['publication_authorized'] ?? true) !== false
            || ($comparison['database_import_allowed'] ?? true) !== false
            || ! is_string($comparison['hypothetical_content_sha256'] ?? null)) {
            throw new DomainException('Revision comparison has not passed the review-pending gate.');
        }

        $original = $this->catalog->payload(ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $pack = $original['pack'] ?? null;
        $questions = $original['questions'] ?? null;
        $metadata = $pack['metadata'] ?? null;
        if (! is_array($pack) || ! is_array($metadata)
            || ($original['schema_version'] ?? null) !== '1.0'
            || ($pack['slug'] ?? null) !== 'ap-a-2026-cbt-80-candidate-v2'
            || ($pack['version'] ?? null) !== '0.4.0-review-required'
            || ($pack['exam_code'] ?? null) !== 'AP'
            || ($pack['subject'] ?? null) !== '科目A'
            || ($pack['downloadable'] ?? true) !== false
            || ($metadata['review_state'] ?? null) !== 'pending_human_content_and_rights_review'
            || ($metadata['explanation_review_state'] ?? null) !== 'pending_human_subject_review'
            || isset($metadata['exam_simulation_review'])
            || ! is_array($questions) || ! array_is_list($questions)
            || count($questions) !== 80) {
            throw new DomainException('Source is not the frozen unapproved AP Subject A 80 Draft.');
        }

        // This slot must remain a separate new Draft; never silently collide
        // with a previously installed/committed candidate.
        if ($this->catalog->all()->contains('slug', self::NEXT_SLUG)) {
            throw new DomainException('The next Draft slug is already allocated in the catalog.');
        }

        $changes = $comparison['differences'];
        $proposals = collect($proposal['changes'])->keyBy('key');
        $applied = [];
        foreach ($changes as $difference) {
            $key = $difference['key'];
            $index = $difference['number'] - 1;
            $question = $questions[$index] ?? null;
            $edit = $proposals->get($key);
            if (! is_array($question) || ! is_array($edit)
                || ($question['external_key'] ?? null) !== $key
                || ($question['source_type'] ?? null) === 'official'
                || ! in_array($question['source_type'] ?? null, [
                    'canovia_original', 'derived',
                ], true)) {
                throw new DomainException('Only Canovia-owned proposed question edits may be packaged.');
            }

            $updated = $question;
            foreach ($edit['fields'] as $field => $text) {
                $updated[$field] = $text;
            }

            $oldNormalized = $this->importer->normalizeQuestionDefinition($question, $index);
            $newNormalized = $this->importer->normalizeQuestionDefinition($updated, $index);
            $before = $this->exam->contentFingerprint(collect([new Question($oldNormalized)]));
            $after = $this->exam->contentFingerprint(collect([new Question($newNormalized)]));
            if ($before !== $difference['before_question_sha256']
                || $after !== $difference['proposed_question_sha256']
                || $before === $after) {
                throw new DomainException('Proposed question digests no longer match the V6.25 comparison.');
            }

            // No inherited checklists, reviews or publication records are
            // appended to these questions. The original curation provenance
            // is left intact; package-wide evidence becomes explicitly stale.
            $questions[$index] = $updated;
            $applied[] = $key;
        }

        if (count($applied) !== $comparison['revision_items_requested']
            || $applied === []) {
            throw new DomainException('Not all requested changes were applied.');
        }

        $normalized = collect($questions)->map(fn (array $question, int $index) =>
            $this->importer->normalizeQuestionDefinition($question, $index));
        if ($normalized->pluck('external_key')->unique()->count() !== 80
            || $normalized->filter(fn (array $q) => $q['is_active'] === true)->count() !== 80) {
            throw new DomainException('Draft format and active question roster changed.');
        }
        $preparedHash = $this->exam->contentFingerprint(
            $normalized->map(fn (array $row) => new Question($row))
        );
        if (! hash_equals($comparison['hypothetical_content_sha256'], $preparedHash)
            || hash_equals($comparison['original_content_sha256'], $preparedHash)) {
            throw new DomainException('Draft fingerprint differs from independently calculated comparison.');
        }

        // ALLOWLIST only non-approval config. Old v0.4 revision-evidence
        // references and any signoff flags never cross a version boundary.
        $newMetadata = [
            'match_terms' => $metadata['match_terms'] ?? ['AP', '応用情報', '科目A'],
            'locale' => 'ja-JP',
            'content_kind' => 'mixed_curated_draft_candidate',
            'review_state' => 'pending_human_content_and_rights_review',
            'explanation_review_state' => 'pending_human_subject_review',
            'rights_and_subject_review_completed' => false,
            'exam_simulation_profile_key' => $metadata['exam_simulation_profile_key'] ?? '',
            'exam_simulation_profile_version' => $metadata['exam_simulation_profile_version'] ?? '',
            'candidate_source_packs' => $metadata['candidate_source_packs'] ?? [],
            'source_usage_guidance_url' => $metadata['source_usage_guidance_url'] ?? '',
            'source_domain_distribution_not_certified' => true,
            'domain_distribution' => $metadata['domain_distribution'] ?? [],
            'revision_state' => 'new_draft_proposal_pending_independent_review',
            'revision_parent_slug' => $pack['slug'],
            'revision_parent_version' => $pack['version'],
            'revision_parent_content_sha256' => $comparison['original_content_sha256'],
            'revision_changed_keys' => $applied,
            'revision_changed_count' => count($applied),
            'review_evidence_state' => 'all_items_pending_new_version_review',
            'prior_revision_evidence_transferred' => false,
            'prior_review_signoffs_transferred' => false,
        ];

        $payload = [
            'schema_version' => '1.0',
            'pack' => [
                'slug' => self::NEXT_SLUG,
                'title' => '応用情報 科目A 80問改訂Draft候補（v0.5・未監修）',
                'exam_code' => 'AP',
                'subject' => '科目A',
                'version' => self::NEXT_VERSION,
                'downloadable' => false,
                'metadata' => $newMetadata,
            ],
            'questions' => $questions,
        ];

        return [
            'candidate' => $payload,
            'summary' => [
                'schema' => 'canovia.learning.ap_a_80_new_draft_preparation.v1',
                'status' => 'new_unapproved_draft_ready_for_manual_file_review',
                'slug' => self::NEXT_SLUG,
                'version' => self::NEXT_VERSION,
                'question_count' => count($questions),
                'changed_keys' => $applied,
                'parent_content_sha256' => $comparison['original_content_sha256'],
                'new_draft_content_sha256' => $preparedHash,
                'all_question_reviews_reset_to_pending' => true,
                'new_source_and_rights_checks_required' => true,
                'new_draft_file_saved' => false,
                'git_resource_committed' => false,
                'database_imported' => false,
                'independent_human_review_verified' => false,
                'rights_independently_verified' => false,
                'exam_content_approved' => false,
                'publication_authorized' => false,
                'automatic_publication_allowed' => false,
            ],
        ];
    }
}
