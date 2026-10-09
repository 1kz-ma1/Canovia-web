<?php

namespace App\Services;

/**
 * Automated/source-backed P0 triage and IPA credit checks for the unpublished
 * AP A v0.4 candidate. This is NOT independent human review or legal approval.
 */
final class ApExamCandidatePrecheckEvidenceService
{
    public const CONTENT_PRECHECK = 'learning_review/ap-a-2026-v04-p0-technical-precheck.json';
    public const IPA_POLICY = 'learning_review/ap-a-2026-v04-ipa-reuse-policy-precheck.json';

    private const P0_KEYS = [
        'sec-dkim-023',
        'sec-csrf-047',
        'db-view-009',
        'quality-reliability-032',
        'test-boundary-043',
        'calc-mm1-015',
    ];

    public function __construct(
        private readonly QuestionPackCatalogService $catalog,
        private readonly ApExamCandidateRevisionPreviewService $preview,
    ) {}

    /**
     * Check the complete evidence snapshots rather than trusting a prefilled
     * "pass" field. No approval decisions are written to any Question Pack.
     *
     * @param array<string,mixed>|null $overrideTech
     * @param array<string,mixed>|null $overridePolicy
     * @return array<string,mixed>
     */
    public function inspect(?array $overrideTech = null, ?array $overridePolicy = null): array
    {
        $tech = $overrideTech ?? $this->readEvidence(self::CONTENT_PRECHECK);
        $policy = $overridePolicy ?? $this->readEvidence(self::IPA_POLICY);
        $candidate = $this->catalog->payload(ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $version = (string) ($candidate['pack']['version'] ?? '');
        $questions = collect($candidate['questions'] ?? [])->keyBy('external_key');
        $preview = $this->preview->inspect();
        $issues = [];

        if (($tech['candidate_version'] ?? '') !== $version
            || ($tech['candidate_key'] ?? '') !== ApExamCandidateRevisionPreviewService::PREVIEW_KEY
            || ($tech['publication_authorized'] ?? true) !== false
            || ($tech['counts']['independent_human_signoffs'] ?? null) !== 0
            || ($tech['counts']['rights_approved'] ?? null) !== 0
            || ($tech['counts']['p0_questions'] ?? null) !== 6
            || ($tech['counts']['explained_wrong_choices'] ?? null) !== 18
            || ! is_array($tech['entries'] ?? null) || count($tech['entries']) !== 6) {
            $issues[] = 'P0 content evidence is stale or claims an unauthorized approval.';
        }

        $seen = [];
        $validP0 = 0;
        $totalReasons = 0;
        $precheckItems = [];
        foreach ($tech['entries'] ?? [] as $entry) {
            $key = (string) ($entry['key'] ?? '');
            $q = $questions->get($key);
            if (isset($seen[$key]) || ! in_array($key, self::P0_KEYS, true) || ! is_array($q)) {
                $issues[] = 'Unexpected, missing, or duplicate P0 evidence ID.';
                continue;
            }
            $seen[$key] = true;
            $snap = $entry['source_snapshot'] ?? null;
            $choices = data_get($q, 'response_schema.0.choices', []);
            $answer = data_get($q, 'grading_rule.answer');
            $wrongKeys = array_values(array_filter(
                array_column($choices, 'id'),
                fn ($choiceId) => $choiceId !== $answer,
            ));
            $wrongReasons = $entry['why_wrong'] ?? null;

            $valid = ($entry['status'] ?? '') === 'canovia_ai_preliminary_content_check_not_independent_human_approval'
                && ($entry['priority'] ?? '') === 'P0'
                && ($entry['human_review_status'] ?? '') === 'pending'
                && ($entry['human_reviewer'] ?? null) === null
                && ($entry['rights_approval'] ?? true) === false
                && ($entry['verdict'] ?? '') !== ''
                && str_contains((string) $entry['verdict'], 'requires_human_review')
                && is_array($snap)
                && ($snap['prompt'] ?? null) === ($q['prompt'] ?? null)
                && ($snap['choices'] ?? null) === $choices
                && ($snap['answer'] ?? null) === $answer
                && ($snap['explanation'] ?? null) === ($q['explanation'] ?? null)
                && ($entry['why_correct'] ?? '') !== ''
                && ($entry['finding'] ?? '') !== ''
                && ($entry['risk'] ?? '') !== ''
                && is_array($entry['sources'] ?? null)
                && count($entry['sources']) >= 1
                && is_array($wrongReasons)
                && array_keys($wrongReasons) === $wrongKeys;
            if ($valid) {
                foreach ($wrongReasons as $reason) {
                    if (! is_string($reason) || trim($reason) === '') {
                        $valid = false;
                        break;
                    }
                }
            }
            if (! $valid) {
                $issues[] = "Invalid or outdated technical precheck: {$key}.";
            } else {
                $validP0++;
                $totalReasons += count($wrongReasons);
            }
            $precheckItems[] = [
                'key' => $key,
                'status' => $valid ? 'ai_technical_precheck_only' : 'stale_or_invalid',
                'finding' => $valid ? $entry['finding'] : null,
                'risk' => $valid ? $entry['risk'] : null,
                'human_review_pending' => true,
            ];
        }
        if (count($seen) !== 6 || $validP0 !== 6 || $totalReasons !== 18
            || array_diff(self::P0_KEYS, array_keys($seen)) !== []) {
            $issues[] = 'Not all six P0 answer/distractor reviews are current.';
        }

        if (($policy['candidate_version'] ?? '') !== $version
            || ($policy['candidate_key'] ?? '') !== ApExamCandidateRevisionPreviewService::PREVIEW_KEY
            || ($policy['ipa_policy_page'] ?? '') !== 'https://www.ipa.go.jp/shiken/faq.html'
            || ($policy['legal_compliance_approved'] ?? true) !== false
            || ($policy['machine_attribution_format_checked'] ?? null) !== 35
            || ! is_array($policy['entries'] ?? null)
            || count($policy['entries']) !== 35) {
            $issues[] = 'IPA usage policy evidence cannot be treated as legally approved.';
        }

        $sources = collect($policy['entries'] ?? [])->keyBy('key');
        $validAttribution = 0;
        $officialKeys = [];
        foreach ($candidate['questions'] ?? [] as $q) {
            if (($q['source_type'] ?? '') !== 'official') {
                continue;
            }
            $key = $q['external_key'];
            $officialKeys[] = $key;
            $provenance = data_get($q, 'learning_metadata.provenance', []);
            $label = (string) ($q['source_reference'] ?? '');
            $questionNum = $provenance['question_number'] ?? null;
            $expectedPrefix = '令和7年度 秋期 応用情報技術者試験 午前 問'.(string) $questionNum;
            $entry = $sources->get($key);
            if (is_array($entry)
                && ($entry['source_label'] ?? '') === $label
                && ($entry['attribution_format_check'] ?? '')
                    === 'required_fields_present_not_legal_approval'
                && ($entry['rights_clearance_status'] ?? '')
                    === 'requires_final_project_context_review'
                && is_int($questionNum)
                && str_contains($label, $expectedPrefix)
                && str_contains($label, '整形')
                && str_starts_with((string) ($provenance['problem_url'] ?? ''),
                    'https://www.ipa.go.jp/')
                && str_starts_with((string) ($provenance['answer_url'] ?? ''),
                    'https://www.ipa.go.jp/')) {
                $validAttribution++;
            } else {
                $issues[] = "IPA attribution metadata needs correction: {$key}.";
            }
        }
        if ($validAttribution !== 35 || $sources->count() !== 35
            || array_diff($sources->keys()->all(), $officialKeys) !== []) {
            $issues[] = 'The complete set of 35 official credits has not been checked.';
        }
        if (! $preview['structurally_consistent']
            || ($candidate['pack']['metadata']['review_state'] ?? '') !== 'pending_human_content_and_rights_review'
            || ($candidate['pack']['metadata']['explanation_review_state'] ?? '') !== 'pending_human_subject_review'
            || isset($candidate['pack']['metadata']['exam_simulation_review'])) {
            $issues[] = 'The underlying AP A content or release guard has changed.';
        }

        return [
            'candidate_version' => $version,
            'p0_technical_prechecks_current' => $validP0,
            'p0_wrong_choices_explained' => $totalReasons,
            'ipa_source_label_fields_present' => $validAttribution,
            'ipa_source_policy_url' => 'https://www.ipa.go.jp/shiken/faq.html',
            'learner_facing_credit_checked' => false,
            'independent_human_approved' => 0,
            'rights_approved' => 0,
            'can_publish' => false,
            'structurally_consistent' => $issues === [],
            'issues' => $issues,
            'items' => $precheckItems,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readEvidence(string $relative): array
    {
        return json_decode((string) file_get_contents(
            resource_path($relative)), true, 512, JSON_THROW_ON_ERROR);
    }
}
