<?php

namespace App\Services;

use DomainException;

/**
 * V6.28: reproducible, metadata-only handoff for the remaining 74 AP A items.
 * This does not inspect actual human work or certify any subject/rights review.
 */
final class ApExamCandidateOwnerReviewWavesService
{
    private const PACKET_PATH = 'docs/learning/review_packets/AP_A_80_OWNER_FIRST_PASS_6Q_V627_2026_10_15.json';
    private const SOURCE_PATH = 'question_packs/ap/ap-a-2026-cbt-80-candidate-v2.json';

    public function __construct(
        private readonly ApExamCandidateHumanReviewIntakeService $intake,
        private readonly QuestionPackCatalogService $catalog,
    ) {}

    /** @return array<string,mixed> */
    public function plan(): array
    {
        $raw = file_get_contents(base_path(self::PACKET_PATH));
        if (! is_string($raw)) {
            throw new DomainException('Editorial packet unavailable.');
        }
        $packet = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($packet) || array_is_list($packet)
            || ($packet['schema'] ?? null) !== 'canovia.learning.ap_a_80_owner_editorial_packet.v1'
            || ($packet['packet_status'] ?? null) !== 'owner_submitted_ai_assisted_first_pass_only'
            || ($packet['applicability'] ?? null) !==
                'editorial_planning_only_not_v624_review_notes_and_not_v625_revision_proposal'
            || ($packet['publication_authorized'] ?? true) !== false
            || ($packet['independent_human_review_verified'] ?? true) !== false
            || ($packet['rights_independently_verified'] ?? true) !== false
            || ($packet['database_import_allowed'] ?? true) !== false
            || ($packet['quality_reviewer_authenticated'] ?? true) !== false
            || ($packet['rights_reviewer_authenticated'] ?? true) !== false) {
            throw new DomainException('Unapproved first-pass packet is inconsistent.');
        }

        $reference = $this->intake->worksheet();
        $source = $this->catalog->payload(ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $sourceBytes = file_get_contents(resource_path(self::SOURCE_PATH));
        if (! is_string($sourceBytes)
            || ($packet['base_source_git_blob_sha1'] ?? null) !==
                sha1('blob '.strlen($sourceBytes)."\0".$sourceBytes)
            || ($packet['candidate_key'] ?? null) !== $reference['candidate_key']
            || ($packet['candidate_slug'] ?? null) !== $reference['candidate_slug']
            || ($packet['candidate_version'] ?? null) !== $reference['candidate_version']
            || count($source['questions'] ?? []) !== 80
            || count($reference['entries'] ?? []) !== 80) {
            throw new DomainException('Editorial packet no longer matches the unapproved candidate.');
        }

        $six = $packet['review_items'] ?? null;
        if (! is_array($six) || ! array_is_list($six) || count($six) !== 6) {
            throw new DomainException('Exactly six owner first-pass records required.');
        }

        $ownerKeys = [];
        foreach ($six as $item) {
            if (! is_array($item) || ! is_string($item['key'] ?? null)
                || isset($ownerKeys[$item['key']])
                || ($item['answer_unchanged'] ?? false) !== true) {
                throw new DomainException('Invalid owner first-pass identities.');
            }
            $ownerKeys[$item['key']] = true;
        }

        $p1 = [];
        $p2 = [];
        $seenKeys = [];
        $official = 0;
        foreach ($reference['entries'] as $index => $entry) {
            $question = $source['questions'][$index] ?? null;
            $key = $entry['key'] ?? null;
            if (! is_array($question) || ! is_string($key)
                || ($question['external_key'] ?? null) !== $key
                || isset($seenKeys[$key])) {
                throw new DomainException('Candidate identities differ from the content-bound worksheet.');
            }
            $seenKeys[$key] = true;
            $priority = $entry['priority'];
            if ($priority === 'P0') {
                if (! isset($ownerKeys[$key])) {
                    throw new DomainException('P0 owner records do not cover the expected six items.');
                }
                continue;
            }
            if (isset($ownerKeys[$key])) {
                throw new DomainException('A first-pass key overlaps pending items.');
            }
            if (! in_array($priority, ['P1', 'P2'], true)) {
                throw new DomainException('Unexpected review priority.');
            }
            if ($priority === 'P1') {
                $official++;
                if (($question['source_type'] ?? null) !== 'official'
                    || ($entry['source_type'] ?? null) !== 'ipa_2025_autumn') {
                    throw new DomainException('Official source category mismatch.');
                }
            } elseif (($question['source_type'] ?? null) === 'official') {
                throw new DomainException('Official item omitted from IPA priority.');
            }

            $meta = $question['learning_metadata'] ?? [];
            $row = [
                'number' => $entry['number'],
                'key' => $key,
                'priority' => $priority,
                'source_type' => $entry['source_type'],
                'question_content_sha256' => $entry['question_content_sha256'],
                'concepts' => array_slice(is_array($meta['concepts'] ?? null)
                    ? array_values(array_filter($meta['concepts'], 'is_string')) : [], 0, 4),
                'source_reference' => $question['source_reference'] ?? null,
                'focus' => $priority === 'P1'
                    ? 'original_pdf_answer_explanation_attribution_rights_and_distractors'
                    : 'answer_distractors_explanation_scope_difficulty_overlap_and_rights',
                'owner_first_pass' => 'not_yet_submitted',
                'independent_content_review' => 'pending',
                'independent_rights_review' => 'pending',
            ];
            if ($priority === 'P1') {
                $p1[] = $row;
            } else {
                $p2[] = $row;
            }
        }

        if (count($seenKeys) !== 80 || count($ownerKeys) !== 6
            || $official !== 35 || count($p1) !== 35 || count($p2) !== 39) {
            throw new DomainException('Remaining review coverage is not exactly 35 IPA + 39 Canovia.');
        }

        $waves = [];
        foreach (['P1' => $p1, 'P2' => $p2] as $priority => $items) {
            foreach (array_chunk($items, 10) as $chunk) {
                $waves[] = [
                    'batch' => count($waves) + 1,
                    'priority' => $priority,
                    'category' => $priority === 'P1'
                        ? 'ipa_2025_autumn'
                        : 'canovia_original_or_derived',
                    'count' => count($chunk),
                    'question_numbers' => array_column($chunk, 'number'),
                    'keys' => array_column($chunk, 'key'),
                    'items' => $chunk,
                ];
            }
        }
        if (count($waves) !== 8
            || array_column($waves, 'count') !== [10, 10, 10, 5, 10, 10, 10, 9]) {
            throw new DomainException('Expected review batch sizes changed.');
        }

        return [
            'schema' => 'canovia.learning.ap_a_80_remaining_review_waves.v1',
            'status' => 'read_only_owner_handoff_not_reviewed',
            'candidate_key' => $reference['candidate_key'],
            'candidate_version' => $reference['candidate_version'],
            'candidate_content_sha256' => $reference['candidate_content_sha256'],
            'owner_first_pass_documented' => 6,
            'remaining_owner_first_pass' => 74,
            'pending_official' => 35,
            'pending_canovia' => 39,
            'waves' => $waves,
            'admin_review_path' => '/admin/question-packs',
            'all_independent_human_reviews_pending' => 80,
            'rights_independently_verified' => false,
            'publication_authorized' => false,
            'database_import_allowed' => false,
            'new_draft_written' => false,
            'read_only' => true,
        ];
    }

    /** @return array<string,mixed> */
    public function describe(?int $batch = null): array
    {
        $plan = $this->plan();
        if ($batch !== null && ($batch < 1 || $batch > 8)) {
            throw new DomainException('Review batch must be between 1 and 8.');
        }
        $plan['waves'] = array_map(static function (array $wave) use ($batch) {
            if ($batch === $wave['batch']) {
                return $wave;
            }
            unset($wave['items']);
            return $wave;
        }, $plan['waves']);
        if ($batch !== null) {
            $plan['selected_batch'] = $batch;
            $plan['selected_items'] = $plan['waves'][$batch - 1]['items'];
            unset($plan['waves'][$batch - 1]['items']);
        }
        return $plan;
    }
}
