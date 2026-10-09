<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Support\Collection;

/**
 * Immutable AP Subject A candidate revision preflight.
 *
 * This checks source-diff fidelity and regenerates the exam-review fingerprint
 * for a second, unapproved Bundled Draft. It never imports, approves or
 * publishes a Question Pack, and never treats editorial changes as sign-off.
 */
final class ApExamCandidateRevisionPreviewService
{
    public const BASE_KEY = 'ap/ap-a-2026-cbt-80-candidate-v1';
    public const PREVIEW_KEY = 'ap/ap-a-2026-cbt-80-candidate-v2';

    public function __construct(
        private readonly QuestionPackCatalogService $catalog,
        private readonly AdaptiveExamPackReadinessService $exam,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function inspect(): array
    {
        $base = $this->catalog->payload(self::BASE_KEY);
        $preview = $this->catalog->payload(self::PREVIEW_KEY);
        $proposals = $this->readEvidence('ap-a-2026-six-priority-clarifications-v1.json');
        $ledger = $this->readEvidence('ap-a-2026-v04-preview-change-ledger.json');
        $choiceAudit = $this->readEvidence('ap-a-2026-canovia-45-choice-audit-v2.json');
        $oldChoiceAudit = $this->readEvidence('ap-a-2026-canovia-45-choice-audit-v1.json');
        $sourceAudit = $this->readEvidence('ap-a-2026-source-spotchecks-v2.json');
        $oldSourceAudit = $this->readEvidence('ap-a-2026-source-spotchecks-v1.json');
        $baseQuestions = $base['questions'] ?? [];
        $previewQuestions = $preview['questions'] ?? [];
        $baseVersion = (string) ($base['pack']['version'] ?? '');
        $previewVersion = (string) ($preview['pack']['version'] ?? '');
        $issues = [];

        if ($baseVersion !== '0.3.0-review-required'
            || $previewVersion !== '0.4.0-review-required'
            || ($preview['pack']['slug'] ?? '') !== 'ap-a-2026-cbt-80-candidate-v2'
            || ($ledger['base_candidate_key'] ?? '') !== self::BASE_KEY
            || ($ledger['preview_candidate_key'] ?? '') !== self::PREVIEW_KEY
            || ($ledger['base_version'] ?? '') !== $baseVersion
            || ($ledger['preview_version'] ?? '') !== $previewVersion
            || ($proposals['candidate_version'] ?? '') !== $baseVersion) {
            $issues[] = 'Base/preview revision identifiers are inconsistent.';
        }

        $metadata = $preview['pack']['metadata'] ?? [];
        if (($preview['pack']['downloadable'] ?? true) !== false
            || ($metadata['review_state'] ?? '') !== 'pending_human_content_and_rights_review'
            || ($metadata['explanation_review_state'] ?? '') !== 'pending_human_subject_review'
            || ($metadata['revision_state'] ?? '') !== 'proposal_applied_to_unpublished_preview_only'
            || ($metadata['rights_and_subject_review_completed'] ?? true) !== false
            || isset($metadata['exam_simulation_review'])
            || ($ledger['publication_authorized'] ?? true) !== false) {
            $issues[] = 'Draft approval and release guards must remain closed.';
        }

        if (count($baseQuestions) !== 80 || count($previewQuestions) !== 80
            || count($proposals['entries'] ?? []) !== 6
            || count($ledger['changed_questions'] ?? []) !== 6) {
            $issues[] = 'Question and proposal counts do not match the contract.';
        }

        $baseByKey = collect($baseQuestions)->keyBy('external_key');
        $previewByKey = collect($previewQuestions)->keyBy('external_key');
        $proposalByKey = collect($proposals['entries'] ?? [])->keyBy('key');
        $ledgerByKey = collect($ledger['changed_questions'] ?? [])->keyBy('key');
        if ($baseByKey->count() !== 80 || $previewByKey->count() !== 80
            || $proposalByKey->count() !== 6 || $ledgerByKey->count() !== 6) {
            $issues[] = 'Duplicate or missing question/proposal identifiers.';
        }

        $modifiedKeys = [];
        $promptChanges = 0;
        $explanationChanges = 0;
        $officialUnchanged = 0;
        foreach ($baseQuestions as $index => $old) {
            $key = (string) ($old['external_key'] ?? '');
            $new = $previewQuestions[$index] ?? [];
            if (($new['external_key'] ?? '') !== $key || $new === []) {
                $issues[] = "Order or key changed at question {$index}.";
                continue;
            }
            $proposal = $proposalByKey->get($key);
            if ($proposal === null) {
                if ($new !== $old) {
                    $issues[] = "Unapproved source difference in {$key}.";
                }
            } else {
                if (! ApExamCandidateAuditService::matchesRevisionProposal(
                    $proposal, $old, $baseVersion,
                    (string) ($proposals['candidate_version'] ?? ''),
                )) {
                    $issues[] = "Stale six-item proposal for {$key}.";
                }

                $expected = $old;
                $expected['prompt'] = $proposal['proposed_prompt'];
                $expected['explanation'] = $proposal['proposed_explanation'];
                $expected['learning_metadata']['curation'] = array_merge(
                    data_get($old, 'learning_metadata.curation', []), [
                        'revision_overlay_from' => $baseVersion,
                        'revision_proposal_key' => $key,
                        'revision_review_status' => 'not_independently_reviewed',
                        'source_pack_original_unchanged' => true,
                    ]
                );
                if ($new !== $expected) {
                    $issues[] = "Extra/invalid field edit in {$key}.";
                }
                if (($new['grading_rule'] ?? null) !== ($old['grading_rule'] ?? null)
                    || ($new['response_schema'] ?? null) !== ($old['response_schema'] ?? null)) {
                    $issues[] = "Answer or option changed in {$key}.";
                }
                $promptChanged = $new['prompt'] !== $old['prompt'];
                $explanationChanged = $new['explanation'] !== $old['explanation'];
                $promptChanges += (int) $promptChanged;
                $explanationChanges += (int) $explanationChanged;
                if (! $promptChanged && ! $explanationChanged) {
                    $issues[] = "No effective content change in {$key}.";
                }
                if (($ledgerByKey->get($key)['change_set'] ?? null) !== [
                    'prompt_changed' => $promptChanged,
                    'explanation_changed' => $explanationChanged,
                    'answer_changed' => false,
                    'choices_changed' => false,
                ]) {
                    $issues[] = "Wrong change ledger for {$key}.";
                }
                $modifiedKeys[] = $key;
            }
            if (($old['source_type'] ?? '') === 'official') {
                if ($new !== $old) {
                    $issues[] = "IPA question altered: {$key}.";
                }
                $officialUnchanged++;
            }
        }

        sort($modifiedKeys);
        $ledgerKeys = $ledgerByKey->keys()->all();
        sort($ledgerKeys);
        if ($modifiedKeys !== $ledgerKeys || $promptChanges !== 4
            || $explanationChanges !== 6 || $officialUnchanged !== 35) {
            $issues[] = 'Revision counts or official source fidelity failed.';
        }

        $oldDrafts = collect($oldChoiceAudit['entries'] ?? [])->keyBy('key');
        $newDrafts = collect($choiceAudit['entries'] ?? [])->keyBy('key');
        $choiceNotesCurrent = 0;
        foreach ($previewQuestions as $question) {
            if (($question['source_type'] ?? '') === 'official') {
                continue;
            }
            $key = $question['external_key'];
            $note = $newDrafts->get($key);
            $old = $oldDrafts->get($key);
            if (! is_array($note) || ! is_array($old)
                || ! ApExamCandidateAuditService::matchesChoiceDraft(
                    $note, $question, $previewVersion,
                    (string) ($choiceAudit['candidate_version'] ?? ''),
                )
                || ($note['incorrect_choices'] ?? null) !== ($old['incorrect_choices'] ?? null)
                || ($note['correct_basis_draft'] ?? null) !== ($question['explanation'] ?? null)) {
                $issues[] = "Invalid or stale wrong-choice audit: {$key}.";
            } else {
                $choiceNotesCurrent++;
            }
        }
        if ($newDrafts->count() !== 45 || $choiceNotesCurrent !== 45
            || ($choiceAudit['audit_summary']['independent_human_approvals'] ?? null) !== 0) {
            $issues[] = '45-question draft audit was not fully regenerated.';
        }

        $oldVisual = collect($oldSourceAudit['entries'] ?? [])->keyBy('key');
        $newVisual = collect($sourceAudit['entries'] ?? [])->keyBy('key');
        $officialEvidenceCarried = 0;
        foreach ($previewQuestions as $question) {
            if (($question['source_type'] ?? '') !== 'official') {
                continue;
            }
            $key = $question['external_key'];
            $newEntry = $newVisual->get($key);
            $oldEntry = $oldVisual->get($key);
            if (! is_array($newEntry) || ! is_array($oldEntry)
                || ! ApExamCandidateAuditService::matchesVisualEvidence(
                    $newEntry, $question, $previewVersion,
                    (string) ($sourceAudit['candidate_version'] ?? ''),
                )
                || ($newEntry['verified_snapshot'] ?? null) !== ($oldEntry['verified_snapshot'] ?? null)) {
                $issues[] = "Official source evidence mismatch: {$key}.";
            } else {
                $officialEvidenceCarried++;
            }
        }
        if ($newVisual->count() !== 35 || $officialEvidenceCarried !== 35
            || ($sourceAudit['review_summary']['independent_subject_review_approved'] ?? null) !== 0) {
            $issues[] = '35 original source snapshots were not safely carried forward.';
        }

        $baseHash = $this->fingerprint($baseQuestions);
        $previewHash = $this->fingerprint($previewQuestions);
        if (hash_equals($baseHash, $previewHash)) {
            $issues[] = 'Changed preview unexpectedly has the same content fingerprint.';
        }

        return [
            'base_version' => $baseVersion,
            'preview_version' => $previewVersion,
            'base_slug' => $base['pack']['slug'] ?? '',
            'preview_slug' => $preview['pack']['slug'] ?? '',
            'question_count' => count($previewQuestions),
            'changed_keys' => $modifiedKeys,
            'prompt_changes' => $promptChanges,
            'explanation_changes' => $explanationChanges,
            'official_unchanged' => $officialUnchanged,
            'choice_draft_current' => $choiceNotesCurrent,
            'official_source_evidence_carried' => $officialEvidenceCarried,
            'base_content_sha256' => $baseHash,
            'preview_content_sha256' => $previewHash,
            'structurally_consistent' => $issues === [],
            'issues' => $issues,
            'publication_authorized' => false,
            'independent_review_pending' => count($previewQuestions),
        ];
    }

    /**
     * Use the same algorithm as exam-readiness rather than a differently
     * serialized raw JSON digest. A hash is content integrity, not approval.
     *
     * @param array<int,array<string,mixed>> $questions
     */
    private function fingerprint(array $questions): string
    {
        $active = collect($questions)
            ->filter(fn (array $row) => ($row['is_active'] ?? false) === true)
            ->map(fn (array $row) => new Question($row));

        return $this->exam->contentFingerprint($active);
    }

    /**
     * @return array<string,mixed>
     */
    private function readEvidence(string $name): array
    {
        return json_decode((string) file_get_contents(
            resource_path('learning_review/'.$name)
        ), true, 512, JSON_THROW_ON_ERROR);
    }
}
