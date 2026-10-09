<?php

namespace App\Services;

/**
 * IPA past-exam reuse terms and question-level source credit metadata preflight.
 *
 * IPA's educational-material reuse FAQ is not a blanket copyright waiver:
 * these automated checks never attest actual learner-facing attribution,
 * publication context, commercial use, or a professional rights decision.
 */
final class ApExamIpaAttributionReadinessService
{
    public const POLICY_RECORD = 'learning_review/ap-a-2026-v04-ipa-reuse-policy-precheck.json';

    public function __construct(private readonly QuestionPackCatalogService $catalog) {}

    /**
     * @param array<string,mixed>|null $override Policy override is for
     *                                           fail-closed regression tests.
     * @return array<string,mixed>
     */
    public function inspect(?array $override = null): array
    {
        $policy = $override ?? json_decode((string) file_get_contents(
            resource_path(self::POLICY_RECORD)), true, 512, JSON_THROW_ON_ERROR);
        $candidate = $this->catalog->payload(ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $issues = [];
        $official = collect($candidate['questions'] ?? [])
            ->filter(fn (array $q) => ($q['source_type'] ?? null) === 'official');
        $records = collect($policy['entries'] ?? []);
        $byId = $records->keyBy('key');
        $valid = 0;

        if (($policy['candidate_key'] ?? null) !== ApExamCandidateRevisionPreviewService::PREVIEW_KEY
            || ($policy['candidate_version'] ?? null) !== ($candidate['pack']['version'] ?? null)
            || ($policy['ipa_policy_page'] ?? null) !== 'https://www.ipa.go.jp/shiken/faq.html'
            || ($policy['legal_compliance_approved'] ?? null) !== false
            || ($policy['machine_attribution_format_checked'] ?? null) !== 35
            || $official->count() !== 35 || $records->count() !== 35
            || $byId->count() !== 35
            || ! is_array($policy['policy_conditions'] ?? null)
            || count($policy['policy_conditions']) !== 4) {
            $issues[] = 'IPA reuse policy evidence version, conditions, or unsigned status is invalid.';
        }

        $ids = [];
        foreach ($official as $q) {
            $id = (string) $q['external_key'];
            $ids[] = $id;
            $row = $byId->get($id);
            $label = (string) ($q['source_reference'] ?? '');
            $origin = data_get($q, 'learning_metadata.provenance', []);
            $n = $origin['question_number'] ?? null;
            $prefix = '令和7年度 秋期 応用情報技術者試験 午前 問'.(string) $n;

            if (is_array($row)
                && ($row['key'] ?? null) === $id
                && ($row['source_label'] ?? null) === $label
                && ($row['attribution_format_check'] ?? null)
                    === 'required_fields_present_not_legal_approval'
                && ($row['rights_clearance_status'] ?? null)
                    === 'requires_final_project_context_review'
                && is_int($n)
                && str_contains($label, $prefix)
                && str_contains($label, '整形')
                && ($origin['publisher'] ?? '') === '独立行政法人情報処理推進機構（IPA）'
                && str_starts_with((string) ($origin['problem_url'] ?? ''), 'https://www.ipa.go.jp/')
                && str_starts_with((string) ($origin['answer_url'] ?? ''), 'https://www.ipa.go.jp/')) {
                $valid++;
            } else {
                $issues[] = "IPA official credit metadata missing/changed: {$id}.";
            }
        }

        if (array_diff($byId->keys()->all(), $ids) !== [] || $valid !== 35) {
            $issues[] = 'IPA evidence keys do not match all 35 official questions.';
        }
        if (($candidate['pack']['downloadable'] ?? true) !== false
            || ($candidate['pack']['metadata']['review_state'] ?? '') !== 'pending_human_content_and_rights_review'
            || isset($candidate['pack']['metadata']['exam_simulation_review'])) {
            $issues[] = 'The candidate changed from the unpublished review-only state.';
        }

        return [
            'metadata_attribution_present' => $valid,
            'official_question_count' => $official->count(),
            'ipa_faq_url' => 'https://www.ipa.go.jp/shiken/faq.html',
            'policy_metadata_precheck_passed' => $issues === [],
            'learner_facing_attribution_verified' => false,
            'usage_context_approved' => false,
            'rights_approved' => 0,
            'can_publish' => false,
            'issues' => $issues,
        ];
    }
}
