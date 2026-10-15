<?php

namespace App\Services;

use App\Models\Question;
use DomainException;

/**
 * V6.25: compare hypothetical corrections for an unapproved AP A v0.4 Draft.
 * Read-only. Does not authenticate review notes, import a Pack or clear rights.
 */
final class ApExamCandidateRevisionComparisonService
{
    private const RECHECKS = [
        'answer_key_and_domain',
        'incorrect_choice_uniqueness',
        'explanation_accuracy',
        'semantic_overlap_and_difficulty',
        'content_rights_and_attribution',
    ];

    public function __construct(
        private readonly ApExamCandidateHumanReviewIntakeService $intake,
        private readonly QuestionPackCatalogService $catalog,
        private readonly QuestionPackImportService $importer,
        private readonly AdaptiveExamPackReadinessService $exam,
    ) {}

    /**
     * @param array<string,mixed> $notes V6.24 reviewer-notes document
     * @param array<string,mixed> $proposal Explicit external correction edits
     * @return array<string,mixed>
     */
    public function inspect(array $notes, array $proposal): array
    {
        $reference = $this->intake->worksheet();
        $notesReport = $this->intake->inspect($notes);
        $errors = [];
        if ($notesReport['status'] !== 'notes_structurally_valid_not_approved') {
            $errors[] = 'stale_or_invalid_review_notes';
        }

        if (! $this->exactKeys($proposal, [
            'schema', 'candidate_key', 'candidate_version',
            'base_content_sha256', 'review_stage', 'changes',
            'publication_authorized',
        ]) || ($proposal['schema'] ?? null) !==
            'canovia.learning.ap_a_80_revision_proposal.v1'
            || ($proposal['review_stage'] ?? null) !== 'unapproved_revision_comparison'
            || ($proposal['publication_authorized'] ?? null) !== false) {
            $errors[] = 'invalid_proposal_schema_or_approval_claim';
        }
        if (($proposal['candidate_key'] ?? null) !== $reference['candidate_key']
            || ($proposal['candidate_version'] ?? null) !== $reference['candidate_version']
            || ($proposal['base_content_sha256'] ?? null) !== $reference['candidate_content_sha256']) {
            $errors[] = 'stale_or_wrong_candidate_revision';
        }

        $source = $this->catalog->payload(ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $questions = $source['questions'] ?? null;
        if (! is_array($questions) || ! array_is_list($questions)
            || count($questions) !== 80) {
            throw new DomainException('The frozen AP80 candidate is unavailable.');
        }

        $byKey = [];
        foreach ($questions as $index => $question) {
            $key = $question['external_key'] ?? null;
            if (! is_string($key) || isset($byKey[$key])
                || ($reference['entries'][$index]['key'] ?? null) !== $key) {
                throw new DomainException('AP80 source identity mismatch.');
            }
            $byKey[$key] = $index;
        }

        $required = [];
        // Never treat an invalid V6.24 note as permission to revise. Even
        // on invalid documents, gather only bounded status information.
        if ($notesReport['status'] === 'notes_structurally_valid_not_approved') {
            foreach ($notes['entries'] as $row) {
                if ($row['review_state'] === 'needs_revision') {
                    $required[$row['key']] = $row['question_content_sha256'];
                }
            }
        }

        $edits = $proposal['changes'] ?? null;
        if (! is_array($edits) || ! array_is_list($edits)
            || count($edits) < 1 || count($edits) > 80
            || count($edits) !== count($required)) {
            $errors[] = 'missing_or_extra_revision_proposals';
            $edits = [];
        }

        $seen = [];
        $diffs = [];
        $proposedQuestions = $questions;
        foreach ($edits as $edit) {
            if (! is_array($edit) || ! $this->exactKeys($edit, [
                'key', 'base_question_sha256', 'reason', 'fields',
            ])) {
                $errors[] = 'invalid_edit_schema';
                continue;
            }
            $key = $edit['key'] ?? null;
            if (! is_string($key) || ! isset($required[$key])
                || ! isset($byKey[$key]) || isset($seen[$key])) {
                $errors[] = 'unrequested_or_duplicate_revision';
                continue;
            }
            $seen[$key] = true;
            $index = $byKey[$key];
            $original = $questions[$index];
            // IPA source questions cannot be changed through an independent
            // Canovia wording proposal, even if notes mark them for revision.
            if (($original['source_type'] ?? null) === 'official'
                || ! in_array($reference['entries'][$index]['source_type'], [
                    'canovia_revised_unapproved', 'canovia_unrevised_unapproved',
                ], true)) {
                $errors[] = 'official_or_unowned_source_is_immutable';
                continue;
            }
            if (($edit['base_question_sha256'] ?? null) !== $required[$key]) {
                $errors[] = 'stale_question_snapshot';
                continue;
            }
            $reason = $edit['reason'] ?? null;
            $fields = $edit['fields'] ?? null;
            if (! is_string($reason) || trim($reason) !== $reason
                || mb_strlen($reason) < 4 || mb_strlen($reason) > 500
                || ! is_array($fields) || array_is_list($fields)
                || count($fields) < 1 || count($fields) > 2
                || array_diff(array_keys($fields), ['prompt', 'explanation']) !== []) {
                $errors[] = 'invalid_revision_fields_or_reason';
                continue;
            }

            $changed = [];
            $comparison = [];
            $updated = $original;
            foreach ($fields as $name => $value) {
                $old = $original[$name] ?? null;
                if (! is_string($value) || $value === ''
                    || trim($value) !== $value
                    || mb_strlen($value) > 12000 || $value === $old) {
                    $errors[] = 'empty_overlong_or_unchanged_edit';
                    continue 2;
                }
                $updated[$name] = $value;
                $changed[] = $name;
                // Report content digests/lengths rather than question or
                // answer text. Reviewers compare their local source files.
                $comparison[$name] = [
                    'before_sha256' => hash('sha256', (string) $old),
                    'after_sha256' => hash('sha256', $value),
                    'before_chars' => mb_strlen((string) $old),
                    'after_chars' => mb_strlen($value),
                ];
            }
            $oldNormalized = $this->importer->normalizeQuestionDefinition($original, $index);
            $newNormalized = $this->importer->normalizeQuestionDefinition($updated, $index);
            // Guard silent normalization/truncation and forbid edits to
            // choices, answer, source attribution, metadata, difficulty.
            foreach ($changed as $name) {
                if ($newNormalized[$name] !== $updated[$name]) {
                    $errors[] = 'edit_changed_during_normalization';
                    continue 2;
                }
            }
            foreach (['external_key', 'source_type', 'source_reference',
                'response_schema', 'grading_rule', 'learning_metadata',
                'difficulty', 'sort_order', 'is_active'] as $preserve) {
                if ($oldNormalized[$preserve] !== $newNormalized[$preserve]) {
                    $errors[] = 'protected_question_field_changed';
                    continue 2;
                }
            }
            $oldHash = $this->exam->contentFingerprint(collect([new Question($oldNormalized)]));
            $newHash = $this->exam->contentFingerprint(collect([new Question($newNormalized)]));
            if (! hash_equals($required[$key], $oldHash)
                || hash_equals($oldHash, $newHash)) {
                $errors[] = 'stale_or_identical_question_fingerprint';
                continue;
            }
            $proposedQuestions[$index] = $updated;
            $diffs[] = [
                'number' => $index + 1,
                'key' => $key,
                'priority' => $reference['entries'][$index]['priority'],
                'changed_fields' => $changed,
                'field_comparison' => $comparison,
                'before_question_sha256' => $oldHash,
                'proposed_question_sha256' => $newHash,
                'review_reset_to' => 'pending_full_independent_rereview',
                'required_rechecks' => self::RECHECKS,
                'old_review_transferred' => false,
            ];
        }

        if (count($seen) !== count($required)
            || count($diffs) !== count($required)) {
            $errors[] = 'not_all_needs_revision_items_replaced';
        }

        $newPackHash = null;
        if ($errors === []) {
            $newPackHash = $this->fingerprint($proposedQuestions);
            if (hash_equals($reference['candidate_content_sha256'], $newPackHash)) {
                $errors[] = 'proposed_pack_unchanged';
                $newPackHash = null;
            }
        }

        $errors = array_values(array_unique($errors));
        return [
            'schema' => 'canovia.learning.ap_a_80_revision_comparison.v1',
            'status' => $errors === [] ? 'draft_comparison_rereview_required' : 'blocked',
            'error_codes' => $errors,
            'revision_items_requested' => count($required),
            'revision_items_compared' => $errors === [] ? count($diffs) : 0,
            'original_content_sha256' => $reference['candidate_content_sha256'],
            'hypothetical_content_sha256' => $errors === [] ? $newPackHash : null,
            'differences' => $errors === [] ? $diffs : [],
            'new_draft_written' => false,
            'existing_candidate_changed' => false,
            'human_rereview_verified' => false,
            'rights_independently_verified' => false,
            'exam_content_approved' => false,
            'publication_authorized' => false,
            'database_import_allowed' => false,
            'read_only' => true,
        ];
    }

    /** @param list<array<string,mixed>> $questions */
    private function fingerprint(array $questions): string
    {
        $models = collect(array_values($questions))
            ->map(fn (array $row, int $index) =>
                $this->importer->normalizeQuestionDefinition($row, $index))
            ->filter(fn (array $row) => $row['is_active'])
            ->map(fn (array $row) => new Question($row));
        return $this->exam->contentFingerprint($models);
    }

    /** @param array<mixed> $input @param list<string> $keys */
    private function exactKeys(array $input, array $keys): bool
    {
        $actual = array_keys($input);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }
}
