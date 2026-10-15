<?php

namespace App\Services;

/**
 * V6.22: offline structural preflight for independently reviewed AP syllabus
 * proposals. A complete report is NEVER proof that a human actually reviewed
 * the IPA PDF, that rights are cleared, or that import/publication is approved.
 */
final class StudyOfficialSyllabusReviewSubmissionService
{
    private const MAX_NODES = 120;
    private const MAX_LINKS = 240;

    public function __construct(
        private readonly StudyOfficialSyllabusReviewPacketService $packets,
    ) {}

    /** @param array<string,mixed> $submission
     *  @return array<string,mixed>
     */
    public function inspect(string $packSlug, array $submission): array
    {
        // Re-fetch the source question snapshots now, never trust caller-
        // supplied inventory hashes or an old V6.21 packet.
        $packet = $this->packets->build($packSlug);
        $errors = [];

        if (! $this->onlyKeys($submission, [
            'schema', 'official_source', 'pack', 'review_stage', 'scope_nodes', 'question_links',
        ]) || ($submission['schema'] ?? null) !==
            'canovia.learning.syllabus_review_submission.v1'
            || ($submission['review_stage'] ?? null) !== 'human_review_submission') {
            $errors[] = 'invalid_submission_schema';
        }
        if (($submission['official_source'] ?? null) !== $packet['official_source']
            || ($submission['pack'] ?? null) !== $packet['pack']) {
            $errors[] = 'source_or_pack_mismatch';
        }

        $nodes = $submission['scope_nodes'] ?? null;
        $links = $submission['question_links'] ?? null;
        if (! is_array($nodes) || ! array_is_list($nodes)
            || count($nodes) < 1 || count($nodes) > self::MAX_NODES) {
            $errors[] = 'invalid_scope_list';
            $nodes = [];
        }
        if (! is_array($links) || ! array_is_list($links)
            || count($links) < 1 || count($links) > self::MAX_LINKS) {
            $errors[] = 'invalid_link_list';
            $links = [];
        }

        $codes = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || ! $this->onlyKeys($node, [
                'code', 'label', 'source_version', 'source_page', 'source_locator',
                'transcription_review', 'independent_review',
            ]) || ! is_string($node['code'] ?? null)
                || preg_match('/^[A-Z0-9][A-Z0-9._-]{2,63}$/D', $node['code']) !== 1
                || ! $this->boundedText($node['label'] ?? null, 100)
                || ($node['source_version'] ?? null) !== '7.2'
                || ! is_int($node['source_page'] ?? null)
                || $node['source_page'] < 1 || $node['source_page'] > 500
                || ! $this->boundedText($node['source_locator'] ?? null, 120)
                || ! $this->review($node['transcription_review'] ?? null, 'confirmed')
                || ! $this->review($node['independent_review'] ?? null, 'confirmed')
                || ($node['transcription_review']['reviewer_id'] ?? null) ===
                    ($node['independent_review']['reviewer_id'] ?? null)) {
                $errors[] = 'invalid_scope_review';
                continue;
            }
            if (isset($codes[$node['code']])) {
                $errors[] = 'duplicate_scope_code';
            }
            $codes[$node['code']] = true;
        }

        $questions = [];
        foreach ($packet['question_candidates'] as $candidate) {
            $questions[$candidate['question_external_key']] =
                $candidate['question_snapshot_sha256'];
        }

        $uniqueLinks = [];
        foreach ($links as $link) {
            if (! is_array($link) || ! $this->onlyKeys($link, [
                'scope_code', 'source_version', 'pack_slug', 'pack_version',
                'question_external_key', 'question_snapshot_sha256',
                'content_review', 'rights_review', 'rights_evidence_ref',
            ]) || ! is_string($link['scope_code'] ?? null)
                || ! isset($codes[$link['scope_code']])
                || ($link['source_version'] ?? null) !== '7.2'
                || ($link['pack_slug'] ?? null) !== $packet['pack']['slug']
                || ($link['pack_version'] ?? null) !== $packet['pack']['version']
                || ! is_string($link['question_external_key'] ?? null)
                || ! isset($questions[$link['question_external_key']])
                || ! $this->review($link['content_review'] ?? null, 'confirmed')
                || ! $this->review($link['rights_review'] ?? null, 'permitted_for_intended_use')
                || ($link['content_review']['reviewer_id'] ?? null) ===
                    ($link['rights_review']['reviewer_id'] ?? null)
                || ! $this->evidenceRef($link['rights_evidence_ref'] ?? null)) {
                $errors[] = 'invalid_question_review';
                continue;
            }

            $key = $link['scope_code'].'|'.$link['question_external_key'];
            if (isset($uniqueLinks[$key])) {
                $errors[] = 'duplicate_question_link';
            }
            $uniqueLinks[$key] = true;

            $claimed = $link['question_snapshot_sha256'] ?? null;
            if (! is_string($claimed)
                || preg_match('/^[a-f0-9]{64}$/D', $claimed) !== 1
                || ! hash_equals($questions[$link['question_external_key']], $claimed)) {
                $errors[] = 'stale_question_snapshot';
            }
        }

        $errors = array_values(array_unique($errors));

        return [
            'schema' => 'canovia.learning.syllabus_review_preflight.v1',
            'status' => $errors === []
                ? 'structurally_complete_manual_evidence_review_required'
                : 'blocked',
            'error_codes' => $errors,
            'proposed_scope_count' => count($nodes),
            'proposed_link_count' => count($links),
            'official_coverage_percent' => null,
            'official_coverage_denominator' => null,
            'independent_human_review_verified' => false,
            'rights_independently_verified' => false,
            'eligible_for_registry_import' => false,
            'production_authorized' => false,
            'read_only' => true,
        ];
    }

    /** @param mixed $value */
    private function boundedText(mixed $value, int $max): bool
    {
        return is_string($value) && $value !== '' && trim($value) === $value
            && mb_strlen($value) <= $max;
    }

    /** @param mixed $value */
    private function evidenceRef(mixed $value): bool
    {
        // Opaque local review-record handle, not a URL or document contents.
        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{3,119}$/D', $value) === 1;
    }

    /** @param mixed $value */
    private function review(mixed $value, string $decision): bool
    {
        if (! is_array($value)
            || ! $this->onlyKeys($value, ['reviewer_id', 'reviewed_at', 'decision'])
            || ($value['decision'] ?? null) !== $decision
            || ! is_string($value['reviewer_id'] ?? null)
            || preg_match('/^[A-Za-z0-9._@-]{3,120}$/D', $value['reviewer_id']) !== 1
            || ! is_string($value['reviewed_at'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value['reviewed_at']) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value['reviewed_at']);
        return $date !== false
            && $date->format('Y-m-d') === $value['reviewed_at']
            && $value['reviewed_at'] >= '2026-01-08'
            && $value['reviewed_at'] <= today()->toDateString();
    }

    /** @param array<mixed> $source
     *  @param list<string> $keys
     */
    private function onlyKeys(array $source, array $keys): bool
    {
        $actual = array_keys($source);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }
}
