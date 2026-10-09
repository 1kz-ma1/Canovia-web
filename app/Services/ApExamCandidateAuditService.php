<?php

namespace App\Services;

/**
 * Read-only, reproducible review queue for the *bundled* AP A 80-question
 * candidate. No DB writes, no external requests and no approval side effects.
 *
 * Automatic source fidelity is NOT an independent assessment of correctness:
 * human reviewers must inspect the official PDF, keys, explanations, scope
 * and usage rights for each item.
 */
final class ApExamCandidateAuditService
{
    public const CANDIDATE = 'ap/ap-a-2026-cbt-80-candidate-v1';

    private const SOURCE_KEYS = [
        'ap-a-ipa-2025-autumn-official-v1' => 'ap/ap-a-ipa-2025-autumn-official-v1',
        'ap-a-canovia-core-v1' => 'ap/ap-a-canovia-core-v1',
        'ap-a-canovia-business-management-supplement-v1'
            => 'ap/ap-a-canovia-business-management-supplement-v1',
    ];

    public function __construct(private readonly QuestionPackCatalogService $catalog) {}

    /**
     * Proposed edits have no effect on the canonical source or published
     * question. Any stale source snapshot must be re-reviewed first.
     *
     * @param array<string,mixed> $proposal
     * @param array<string,mixed> $question
     */
    public static function matchesRevisionProposal(
        array $proposal,
        array $question,
        string $candidateVersion,
        string $proposalVersion,
    ): bool {
        $snapshot = $proposal['source_snapshot'] ?? null;
        if (! is_array($snapshot)
            || $candidateVersion !== $proposalVersion
            || ($proposal['key'] ?? '') !== ($question['external_key'] ?? '')
            || ($proposal['requires_expert_signoff'] ?? false) !== true
            || ! is_string($proposal['proposed_prompt'] ?? null)
            || trim($proposal['proposed_prompt']) === ''
            || ! is_string($proposal['proposed_explanation'] ?? null)
            || trim($proposal['proposed_explanation']) === '') {
            return false;
        }
        return ($snapshot['prompt'] ?? null) === ($question['prompt'] ?? null)
            && ($snapshot['choices'] ?? null) === data_get($question, 'response_schema.0.choices')
            && ($snapshot['answer'] ?? null) === data_get($question, 'grading_rule.answer')
            && ($snapshot['explanation'] ?? null) === ($question['explanation'] ?? null)
            && ($proposal['proposed_choices'] ?? null) === $snapshot['choices']
            && ($proposal['proposed_answer'] ?? null) === $snapshot['answer'];
    }

    /**
     * Per-option reasoning drafts are a tool for reviewers, never human
     * approval. A changed question or explanation expires the draft.
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $question
     */
    public static function matchesChoiceDraft(
        array $entry,
        array $question,
        string $candidateVersion,
        string $auditVersion,
    ): bool {
        $snapshot = $entry['verified_snapshot'] ?? null;
        if ($candidateVersion !== $auditVersion
            || ($entry['review_status'] ?? '') !== 'canovia_draft_not_independently_approved'
            || ! is_array($snapshot)
            || ($entry['key'] ?? '') !== ($question['external_key'] ?? '')) {
            return false;
        }

        $choices = data_get($question, 'response_schema.0.choices', []);
        $answer = data_get($question, 'grading_rule.answer');
        $incorrectIds = array_values(array_filter(array_column($choices, 'id'),
            fn ($id) => $id !== $answer));
        $reasons = $entry['incorrect_choices'] ?? null;
        if (count($incorrectIds) !== 3 || ! is_array($reasons)
            || count($reasons) !== 3 || array_keys($reasons) !== $incorrectIds) {
            return false;
        }
        foreach ($reasons as $reason) {
            if (! is_string($reason) || trim($reason) === '') {
                return false;
            }
        }

        return ($snapshot['prompt'] ?? null) === ($question['prompt'] ?? null)
            && ($snapshot['choices'] ?? null) === $choices
            && ($snapshot['answer'] ?? null) === $answer
            && ($snapshot['explanation'] ?? null) === ($question['explanation'] ?? null);
    }

    /**
     * A source PDF spotcheck expires if its candidate version, official
     * problem text, choices or answer changes. This never grants expert
     * explanation/rights approval.
     *
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $question
     */
    public static function matchesVisualEvidence(
        array $entry,
        array $question,
        string $candidateVersion,
        string $evidenceVersion,
    ): bool {
        $snapshot = $entry['verified_snapshot'] ?? null;
        return $candidateVersion === $evidenceVersion
            && ($entry['status'] ?? '') === 'source_statement_options_visually_spotchecked_only'
            && is_array($snapshot)
            && ($snapshot['prompt'] ?? null) === ($question['prompt'] ?? null)
            && ($snapshot['choices'] ?? null) === data_get($question, 'response_schema.0.choices')
            && ($snapshot['answer'] ?? null) === data_get($question, 'grading_rule.answer');
    }

    /**
     * @return array<string,mixed>
     */
    public function inspect(): array
    {
        $candidate = $this->catalog->payload(self::CANDIDATE);
        $sourceQuestions = [];
        foreach (self::SOURCE_KEYS as $slug => $catalogKey) {
            $sourceQuestions[$slug] = collect($this->catalog->payload($catalogKey)['questions'] ?? [])
                ->keyBy('external_key');
        }

        $spotcheckFile = resource_path('learning_review/ap-a-2026-source-spotchecks-v1.json');
        $spotcheckEvidence = json_decode((string) file_get_contents($spotcheckFile), true,
            512, JSON_THROW_ON_ERROR);
        $spotchecks = collect($spotcheckEvidence['entries'] ?? [])
            ->keyBy('key');
        $choiceAuditFile = resource_path('learning_review/ap-a-2026-canovia-45-choice-audit-v1.json');
        $choiceAuditData = json_decode((string) file_get_contents($choiceAuditFile),
            true, 512, JSON_THROW_ON_ERROR);
        $choiceDrafts = collect($choiceAuditData['entries'] ?? [])->keyBy('key');
        $choiceDraftMatched = 0;
        $choiceDraftStale = 0;

        $revisionFile = resource_path('learning_review/ap-a-2026-six-priority-clarifications-v1.json');
        $revisionData = json_decode((string) file_get_contents($revisionFile), true,
            512, JSON_THROW_ON_ERROR);
        $revisionProposals = collect($revisionData['entries'] ?? [])->keyBy('key');
        $revisionCurrent = 0;
        $revisionStale = 0;

        $sourceVisualChecked = 0;
        $priorities = ['P0' => 0, 'P1' => 0, 'P2' => 0];

        $items = [];
        $normalizedPrompts = [];
        $seenKeys = [];
        $distribution = ['technology' => 0, 'management' => 0, 'strategy' => 0];
        $sourceCounts = array_fill_keys(array_keys(self::SOURCE_KEYS), 0);
        $structuralFailures = 0;

        foreach ($candidate['questions'] ?? [] as $index => $question) {
            $key = (string) ($question['external_key'] ?? '');
            $curation = data_get($question, 'learning_metadata.curation', []);
            $sourceSlug = (string) ($curation['source_pack_slug'] ?? '');
            $sourceKey = (string) ($curation['source_external_key'] ?? '');
            $source = $sourceQuestions[$sourceSlug]?->get($sourceKey) ?? null;
            $flags = [];

            if (! isset($sourceCounts[$sourceSlug]) || ! is_array($source)) {
                $flags[] = '元問題集・元問題IDを確認できません。';
            } else {
                $sourceCounts[$sourceSlug]++;
                foreach (['external_key', 'source_type', 'source_reference', 'prompt',
                    'response_schema', 'grading_rule', 'difficulty', 'is_active'] as $field) {
                    if (($question[$field] ?? null) !== ($source[$field] ?? null)) {
                        $flags[] = '元の問題と'.$field.'が一致しません。';
                    }
                }
                if ($sourceSlug !== 'ap-a-ipa-2025-autumn-official-v1'
                    && ($question['explanation'] ?? null) !== ($source['explanation'] ?? null)) {
                    $flags[] = 'Canovia元問題の解説と一致しません。';
                }
                if ($sourceSlug === 'ap-a-ipa-2025-autumn-official-v1'
                    && (($curation['explanation_origin'] ?? null) !== 'canovia_independent_draft'
                        || ($curation['explanation_review_status'] ?? null) !== 'awaiting_subject_expert_check')) {
                    $flags[] = 'IPA由来の独自解説が未監修と明示されていません。';
                }
            }

            $field = collect($question['response_schema'] ?? [])->firstWhere('id', 'answer');
            $choices = is_array($field['choices'] ?? null) ? $field['choices'] : [];
            $choiceIds = array_column($choices, 'id');
            $choiceLabels = array_column($choices, 'label');
            $answer = (string) data_get($question, 'grading_rule.answer', '');
            if (($field['type'] ?? null) !== 'single_choice'
                || ($question['grading_rule']['type'] ?? null) !== 'exact_choice'
                || $choiceIds !== ['ア', 'イ', 'ウ', 'エ']
                || ! in_array($answer, $choiceIds, true)
                || count(array_unique(array_map('trim', $choiceLabels))) !== 4
                || in_array('', array_map('trim', $choiceLabels), true)) {
                $flags[] = '四肢択一と正答の構造に不整合があります。';
            }
            if (trim((string) ($question['explanation'] ?? '')) === '') {
                $flags[] = '解説がありません。';
            }
            if (isset($seenKeys[$key]) || $key === '') {
                $flags[] = '問題IDが重複または空です。';
            }
            $seenKeys[$key] = true;

            $normalized = mb_strtolower((string) preg_replace(
                '/[\s　、。・.,，．:：;；!?？！（）()［］「」『』]+/u', '',
                (string) ($question['prompt'] ?? '')
            ));
            if ($normalized === '' || isset($normalizedPrompts[$normalized])) {
                $flags[] = '問題文が空、または正規化した問題文が重複しています。';
            }
            $normalizedPrompts[$normalized] = true;

            $tags = data_get($question, 'learning_metadata.tags', []);
            $concepts = data_get($question, 'learning_metadata.concepts', []);
            $domain = in_array('マネジメント', $tags, true)
                || ($concepts[0] ?? null) === 'マネジメント' ? 'management'
                : (in_array('ストラテジ', $tags, true)
                    || ($concepts[0] ?? null) === 'ストラテジ' ? 'strategy' : 'technology');
            $distribution[$domain]++;

            $originType = $sourceSlug === 'ap-a-ipa-2025-autumn-official-v1'
                ? 'official' : ($sourceSlug === 'ap-a-canovia-core-v1' ? 'core' : 'new');
            $reviewTasks = $originType === 'official'
                ? ['IPA問題PDFの本文・選択肢と出典の照合',
                    'IPA解答表と正答キーの照合',
                    'Canovia独自の解説案・誤答理由の検証',
                    '図表・数式の再現と教材利用条件の確認']
                : ['正答と全誤答選択肢の独立検算・根拠確認',
                    '学習指導要領・問題難度・解説の妥当性確認',
                    '他79問との意味上の重複確認',
                    'オリジナル/派生の権利・出典確認'];

            // Source visual checks only establish that the visible original
            // statement and options match; they never count as human signoff.
            $spotcheck = $spotchecks->get($key);
            $visualChecked = $originType === 'official'
                && is_array($spotcheck)
                && self::matchesVisualEvidence(
                    $spotcheck,
                    $question,
                    (string) ($candidate['pack']['version'] ?? ''),
                    (string) ($spotcheckEvidence['candidate_version'] ?? ''),
                );
            if ($visualChecked) {
                $sourceVisualChecked++;
            }

            $choiceDraft = $originType === 'official' ? null : $choiceDrafts->get($key);
            $choiceDraftCurrent = $originType !== 'official'
                && is_array($choiceDraft)
                && self::matchesChoiceDraft(
                    $choiceDraft,
                    $question,
                    (string) ($candidate['pack']['version'] ?? ''),
                    (string) ($choiceAuditData['candidate_version'] ?? ''),
                );
            if ($choiceDraftCurrent) {
                $choiceDraftMatched++;
            } elseif ($originType !== 'official') {
                $choiceDraftStale++;
            }

            $proposal = $originType === 'official' ? null : $revisionProposals->get($key);
            $proposalCurrent = $originType !== 'official'
                && is_array($proposal)
                && self::matchesRevisionProposal(
                    $proposal, $question,
                    (string) ($candidate['pack']['version'] ?? ''),
                    (string) ($revisionData['candidate_version'] ?? ''),
                );
            if ($proposalCurrent) {
                $revisionCurrent++;
            } elseif ($proposal !== null) {
                $revisionStale++;
            }

            // Priority does not alter exam item ordering or approval flags.
            // Address unverified original transcription before general theory.
            $priority = ($flags !== [] || ($originType !== 'official' && ! $choiceDraftCurrent)) ? 'P0'
                : ($originType === 'official' && ! $visualChecked ? 'P0'
                    : ($originType === 'new' || $originType === 'official'
                        || preg_match('/(計算|ベイズ|MIPS|SLA|ROI|待ち行列|EVM|RAID|D.A|MSS)/ui',
                            implode(' ', array_merge($concepts, $tags)) . ' '. $key)
                        ? 'P1' : 'P2'));
            $priorities[$priority]++;
            $priorityReason = $flags !== [] ? '構造不整合を先に修正'
                : ($originType !== 'official' && ! $choiceDraftCurrent
                    ? '独自問題の誤答肢レビュー案が不足、または問題変更で失効'
                : ($originType === 'official' && ! $visualChecked
                    ? 'IPA原問題PDFと本文・選択肢の未照合'
                    : ($originType === 'official'
                        ? '原文スポット照合済み・独自解説と利用条件は未承認'
                        : ($originType === 'new'
                            ? '新規Canovia案の正答・誤答肢・解説を第三者が確認'
                            : ($priority === 'P1'
                                ? '計算・数値条件を独立して検証'
                                : '通常の独立内容レビュー待ち')))));

            $items[] = [
                'number' => $index + 1,
                'key' => $key,
                'source_key' => $sourceKey,
                'source_pack' => $sourceSlug,
                'origin_type' => $originType,
                'domain' => $domain,
                'prompt' => (string) ($question['prompt'] ?? ''),
                'choices' => $choices,
                'answer' => $answer,
                'explanation' => (string) ($question['explanation'] ?? ''),
                'source_reference' => (string) ($question['source_reference'] ?? ''),
                'source_url' => (string) data_get($question, 'learning_metadata.provenance.problem_url', ''),
                'source_answer_url' => (string) data_get($question, 'learning_metadata.provenance.answer_url', ''),
                'flags' => $flags,
                'review_tasks' => $reviewTasks,
                // No individual sign-off record is provided by the bundle.
                'independent_review_status' => 'pending',
                'priority' => $priority,
                'priority_reason' => $priorityReason,
                'content_revision_proposal_status' => $proposal === null ? 'none'
                    : ($proposalCurrent ? 'draft_for_review' : 'stale_needs_reaudit'),
                'content_revision_proposal' => $proposalCurrent ? [
                    'severity' => $proposal['severity'],
                    'summary' => $proposal['summary'],
                    'proposed_prompt' => $proposal['proposed_prompt'],
                    'proposed_explanation' => $proposal['proposed_explanation'],
                    'sources' => $proposal['sources'],
                ] : null,
                'choice_draft_state' => $originType === 'official' ? 'not_applicable'
                    : ($choiceDraftCurrent ? 'current_unreviewed_draft' : 'missing_or_stale'),
                'choice_draft_reasons' => $choiceDraftCurrent
                    ? $choiceDraft['incorrect_choices'] : [],
                'choice_draft_review_focus' => $choiceDraftCurrent
                    ? ($choiceDraft['review_focus'] ?? []) : [],
                'source_visual_spotcheck' => $visualChecked,
                'source_visual_spotcheck_pdf_page' => $visualChecked
                    ? ((int) $spotcheck['pdf_page_index'] + 1) : null,
                'source_visual_spotcheck_note' => $visualChecked
                    ? (string) $spotcheck['evidence'] : null,
            ];

            if ($flags !== []) {
                $structuralFailures++;
            }
        }

        $metadata = $candidate['pack']['metadata'] ?? [];
        $knownOverlapPairs = data_get($metadata,
            'semantic_overlap_review.superseded_by_official', []);
        $currentKeys = array_fill_keys(array_column($items, 'key'), true);
        $knownOverlapExcluded = collect($knownOverlapPairs)->every(fn ($pair) =>
            ! isset($currentKeys[$pair['core'] ?? ''])
            && isset($currentKeys[$pair['official'] ?? '']));

        return [
            'candidate_key' => self::CANDIDATE,
            'candidate_version' => (string) ($candidate['pack']['version'] ?? ''),
            'items' => $items,
            'count' => count($items),
            'structural_failure_count' => $structuralFailures,
            'independent_review_pending_count' => count($items),
            'distribution' => $distribution,
            'source_counts' => $sourceCounts,
            'known_overlap_excluded' => $knownOverlapExcluded,
            'known_overlap_count' => count($knownOverlapPairs),
            'content_revision_proposals_current_count' => $revisionCurrent,
            'content_revision_proposals_stale_count' => $revisionStale,
            'content_revision_proposals_approved_count' => 0,
            'choice_draft_current_count' => $choiceDraftMatched,
            'choice_draft_stale_count' => $choiceDraftStale,
            'choice_draft_independent_approvals' => 0,
            'source_visual_spotchecked_count' => $sourceVisualChecked,
            'source_visual_unchecked_official_count' =>
                $sourceCounts['ap-a-ipa-2025-autumn-official-v1'] - $sourceVisualChecked,
            'priority_counts' => $priorities,
            'publication_blocked' =>
                ($metadata['review_state'] ?? '') === 'pending_human_content_and_rights_review'
                && ($metadata['explanation_review_state'] ?? '') === 'pending_human_subject_review'
                && ! isset($metadata['exam_simulation_review']),
        ];
    }
}
