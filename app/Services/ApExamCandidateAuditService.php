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
                'flags' => $flags,
                'review_tasks' => $reviewTasks,
                // No individual sign-off record is provided by the bundle.
                'independent_review_status' => 'pending',
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
            'publication_blocked' =>
                ($metadata['review_state'] ?? '') === 'pending_human_content_and_rights_review'
                && ($metadata['explanation_review_state'] ?? '') === 'pending_human_subject_review'
                && ! isset($metadata['exam_simulation_review']),
        ];
    }
}
