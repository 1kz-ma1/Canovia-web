<?php

namespace App\Services;

use App\Models\Question;
use DomainException;

/**
 * V6.24: offline review-note intake for the *unpublished* v0.4 AP A candidate.
 * Nothing here authenticates human reviewers or grants publication authority.
 */
final class ApExamCandidateHumanReviewIntakeService
{
    private const CHECKS = [
        'answer_key_and_domain',
        'incorrect_choice_uniqueness',
        'explanation_accuracy',
        'semantic_overlap_and_difficulty',
        'content_rights_and_attribution',
    ];

    public function __construct(
        private readonly ApExamCandidateQualityReviewService $quality,
        private readonly QuestionPackCatalogService $catalog,
        private readonly QuestionPackImportService $importer,
        private readonly AdaptiveExamPackReadinessService $exam,
    ) {}

    /** @return array<string,mixed> */
    public function worksheet(): array
    {
        $review = $this->quality->inspect();
        $candidate = $this->catalog->payload(ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $pack = $candidate['pack'] ?? [];
        $questions = $candidate['questions'] ?? [];

        // Existing independent QA, source/answer/rights *metadata* checks
        // remain the source of truth. An unreviewed candidate may be inspected,
        // but must never be silently treated as already approved.
        if (! ($review['structurally_consistent'] ?? false)
            || ($review['can_publish'] ?? true) !== false
            || ($review['independent_human_review_pending'] ?? null) !== 80
            || ($review['signed_off'] ?? -1) !== 0
            || count($questions) !== 80 || count($review['items'] ?? []) !== 80
            || ($pack['slug'] ?? null) !== 'ap-a-2026-cbt-80-candidate-v2'
            || ($pack['version'] ?? null) !== '0.4.0-review-required'
            || ($pack['metadata']['review_state'] ?? null) !== 'pending_human_content_and_rights_review'
            || ($pack['metadata']['explanation_review_state'] ?? null) !== 'pending_human_subject_review'
            || ($pack['downloadable'] ?? true) !== false
            || isset($pack['metadata']['exam_simulation_review'])) {
            throw new DomainException('AP A v0.4 candidate is not a valid unapproved review source.');
        }

        $entries = [];
        $seen = [];
        foreach ($questions as $index => $raw) {
            $key = $raw['external_key'] ?? null;
            $qualityRow = $review['items'][$index];
            if (! is_string($key) || trim($key) === '' || isset($seen[$key])
                || ($qualityRow['key'] ?? null) !== $key) {
                throw new DomainException('Candidate question ordering/identity mismatch.');
            }
            $seen[$key] = true;

            // Use exactly the existing exam-publication canonicalizer, not
            // a second invented raw-JSON hash algorithm.
            $normalized = $this->importer->normalizeQuestionDefinition($raw, $index);
            $itemHash = $this->exam->contentFingerprint(collect([new Question($normalized)]));
            $entries[] = [
                'number' => $index + 1,
                'key' => $key,
                'priority' => $qualityRow['priority'],
                'source_type' => $qualityRow['source_type'],
                'question_content_sha256' => $itemHash,
                'review_state' => 'pending',
                'checks' => array_fill_keys(self::CHECKS, 'pending'),
                'quality_reviewer_id' => null,
                'rights_reviewer_id' => null,
                'reviewed_at' => null,
                'rights_evidence_ref' => null,
            ];
        }

        return [
            'schema' => 'canovia.learning.ap_a_80_review_notes.v1',
            'candidate_key' => ApExamCandidateRevisionPreviewService::PREVIEW_KEY,
            'candidate_slug' => $pack['slug'],
            'candidate_version' => $pack['version'],
            'candidate_content_sha256' => $review['content_sha256'],
            'review_stage' => 'operator_notes_only',
            'entries' => $entries,
            'publication_authorized' => false,
            'independent_human_review_verified' => false,
            'rights_independently_verified' => false,
            'database_import_allowed' => false,
        ];
    }

    /**
     * @param array<string,mixed> $notes
     * @return array<string,mixed>
     */
    public function inspect(array $notes): array
    {
        $reference = $this->worksheet(); // fail if source or review queue is stale
        $errors = [];

        if (! $this->exactKeys($notes, [
            'schema', 'candidate_key', 'candidate_slug', 'candidate_version',
            'candidate_content_sha256', 'review_stage', 'entries',
            'publication_authorized', 'independent_human_review_verified',
            'rights_independently_verified', 'database_import_allowed',
        ]) || ($notes['schema'] ?? null) !== $reference['schema']
            || ($notes['review_stage'] ?? null) !== 'operator_notes_only'
            || ($notes['publication_authorized'] ?? null) !== false
            || ($notes['independent_human_review_verified'] ?? null) !== false
            || ($notes['rights_independently_verified'] ?? null) !== false
            || ($notes['database_import_allowed'] ?? null) !== false) {
            $errors[] = 'invalid_notes_schema_or_approval_claim';
        }

        foreach (['candidate_key', 'candidate_slug', 'candidate_version',
            'candidate_content_sha256'] as $key) {
            if (($notes[$key] ?? null) !== $reference[$key]) {
                $errors[] = 'candidate_version_or_fingerprint_mismatch';
                break;
            }
        }

        $rows = $notes['entries'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== 80) {
            $errors[] = 'invalid_item_count_or_order';
            $rows = [];
        }
        $counts = ['pending' => 0, 'needs_revision' => 0, 'submitted_claims' => 0];
        foreach ($rows as $index => $row) {
            $expected = $reference['entries'][$index] ?? null;
            if (! is_array($row) || $expected === null
                || ! $this->exactKeys($row, [
                    'number', 'key', 'priority', 'source_type',
                    'question_content_sha256', 'review_state', 'checks',
                    'quality_reviewer_id', 'rights_reviewer_id',
                    'reviewed_at', 'rights_evidence_ref',
                ])) {
                $errors[] = 'invalid_review_item';
                continue;
            }
            foreach (['number', 'key', 'priority', 'source_type',
                'question_content_sha256'] as $key) {
                if ($row[$key] !== $expected[$key]) {
                    $errors[] = 'stale_or_mismatched_review_item';
                    continue 2;
                }
            }

            $checks = $row['checks'];
            if (! is_array($checks) || ! $this->exactKeys($checks, self::CHECKS)
                || array_diff(array_values($checks), [
                    'pending', 'checked_claim', 'needs_revision',
                ]) !== []) {
                $errors[] = 'invalid_review_dimensions';
                continue;
            }
            $state = $row['review_state'];
            if ($state === 'pending') {
                if ($checks !== $expected['checks']
                    || $row['quality_reviewer_id'] !== null
                    || $row['rights_reviewer_id'] !== null
                    || $row['reviewed_at'] !== null
                    || $row['rights_evidence_ref'] !== null) {
                    $errors[] = 'invalid_pending_review';
                } else {
                    $counts['pending']++;
                }
            } elseif ($state === 'needs_revision') {
                if (! in_array('needs_revision', $checks, true)
                    || ! $this->reviewer($row['quality_reviewer_id'])
                    || ! $this->date($row['reviewed_at'])
                    || $row['rights_reviewer_id'] !== null
                    || $row['rights_evidence_ref'] !== null) {
                    $errors[] = 'invalid_revision_review';
                } else {
                    $counts['needs_revision']++;
                }
            } elseif ($state === 'submitted_for_external_verification') {
                if (count(array_filter($checks, fn ($status) =>
                    $status === 'checked_claim')) !== count(self::CHECKS)
                    || ! $this->reviewer($row['quality_reviewer_id'])
                    || ! $this->reviewer($row['rights_reviewer_id'])
                    || $row['quality_reviewer_id'] === $row['rights_reviewer_id']
                    || ! $this->date($row['reviewed_at'])
                    || ! $this->evidenceRef($row['rights_evidence_ref'])) {
                    $errors[] = 'invalid_unverified_review_claim';
                } else {
                    $counts['submitted_claims']++;
                }
            } else {
                $errors[] = 'invalid_review_state';
            }
        }

        $errors = array_values(array_unique($errors));
        return [
            'schema' => 'canovia.learning.ap_a_80_review_notes_preflight.v1',
            'status' => $errors === [] ? 'notes_structurally_valid_not_approved' : 'blocked',
            'error_codes' => $errors,
            'expected_items' => 80,
            'pending_items' => $counts['pending'],
            'needs_revision_items' => $counts['needs_revision'],
            'submitted_unverified_claims' => $counts['submitted_claims'],
            'independent_human_review_verified' => false,
            'rights_independently_verified' => false,
            'exam_content_approved' => false,
            'publication_authorized' => false,
            'database_import_allowed' => false,
            'read_only' => true,
        ];
    }

    /** @param array<mixed> $source @param list<string> $keys */
    private function exactKeys(array $source, array $keys): bool
    {
        $actual = array_keys($source);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }

    private function reviewer(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-zA-Z0-9._@-]{3,120}$/D', $value) === 1;
    }

    private function evidenceRef(mixed $value): bool
    {
        // An opaque external record identifier, never attached evidence data.
        return is_string($value)
            && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{3,119}$/D', $value) === 1;
    }

    private function date(mixed $value): bool
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value
            && $value >= '2026-01-08' && $value <= today()->toDateString();
    }
}
