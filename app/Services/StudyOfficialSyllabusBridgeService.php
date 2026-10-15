<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use Illuminate\Http\Request;

/**
 * V6.20: bounded, read-only official-syllabus provenance and reviewed-link
 * registry. A syllabus document's existence does NOT prove a complete outline
 * or the accuracy/rights/educational merit of Question Bank questions.
 */
final class StudyOfficialSyllabusBridgeService
{
    private const MAX_NODES = 120;
    private const MAX_LINKS = 240;

    public function __construct(
        private readonly QuestionPackPublicationReadinessService $readiness,
        private readonly AdaptiveLearningBankQueueService $queue,
    ) {}

    /** @param array<string,mixed> $bankCoverage
     *  @return array<string,mixed>
     */
    public function forWorkspace(Request $request, Plan $plan, ?string $actorToken,
        bool $canEdit, array $bankCoverage): array
    {
        $unavailable = ['available' => false];
        if (! $canEdit || (! $request->user() && ! filled($actorToken))
            || ! ($bankCoverage['available'] ?? false)) return $unavailable;

        $manifest = config('study_official_syllabus.ap_subject_a_2026', []);
        $source = $this->validateSource(is_array($manifest) ? $manifest : []);
        if ($source === null) {
            return [
                'available' => true, 'source_identified' => false,
                'source' => null, 'index_state' => 'invalid_source',
                'nodes' => [], 'reviewed_link_count' => 0,
                'rejected_links' => 0, 'coverage_percent' => null,
                'coverage_denominator' => null, 'index_complete' => false,
                'review_status' => 'not_verified',
            ];
        }

        $nodes = [];
        $indexState = (string) ($manifest['catalog_state'] ?? 'source_only');
        if ($indexState === 'reviewed_partial') {
            $candidateNodes = $manifest['scope_nodes'] ?? [];
            if (is_array($candidateNodes) && count($candidateNodes) <= self::MAX_NODES) {
                foreach ($candidateNodes as $raw) {
                    if (! is_array($raw) || ($raw['source_version'] ?? null) !== $source['version']
                        || ($raw['review_state'] ?? null) !== 'reviewed'
                        || ! $this->reviewed($raw)
                        || ! is_string($raw['code'] ?? null)
                        || preg_match('/^[A-Z0-9][A-Z0-9._-]{2,63}$/', $raw['code']) !== 1
                        || ! is_string($raw['label'] ?? null)
                        || trim($raw['label']) === '' || mb_strlen($raw['label']) > 100
                        || ! filter_var($raw['source_page'] ?? null, FILTER_VALIDATE_INT,
                            ['options' => ['min_range' => 1, 'max_range' => 500]])) {
                        continue;
                    }
                    $code = $raw['code'];
                    // Duplicate code invalidates the entire index to prevent
                    // assigning one Question to a misleading/tampered node.
                    if (isset($nodes[$code])) {
                        $nodes = [];
                        $indexState = 'invalid_duplicate_node';
                        break;
                    }
                    $nodes[$code] = [
                        'code' => $code, 'label' => trim($raw['label']),
                        'source_page' => (int) $raw['source_page'],
                        'reviewer_id' => $raw['reviewer_id'],
                        'reviewed_at' => $raw['reviewed_at'],
                        'linked_question_count' => 0,
                    ];
                }
            } else {
                $indexState = 'invalid_node_limit';
            }
        }

        $validLinks = [];
        $rejected = 0;
        $inputLinks = $manifest['question_links'] ?? [];
        if ($indexState === 'reviewed_partial' && $nodes !== [] && is_array($inputLinks)
            && count($inputLinks) <= self::MAX_LINKS) {
            $distinct = [];
            foreach ($inputLinks as $raw) {
                if (! is_array($raw) || ! $this->reviewed($raw)
                    || ($raw['review_state'] ?? null) !== 'reviewed'
                    || ($raw['source_version'] ?? null) !== $source['version']
                    || ! isset($nodes[$raw['scope_code'] ?? ''])
                    || ! $this->identity($raw['pack_slug'] ?? null)
                    || ! $this->identity($raw['pack_version'] ?? null)
                    || ! $this->identity($raw['question_external_key'] ?? null)
                    || ! is_string($raw['question_snapshot_sha256'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/', $raw['question_snapshot_sha256']) !== 1) {
                    $rejected++;
                    continue;
                }
                $key = implode('|', [$raw['pack_slug'], $raw['pack_version'],
                    $raw['question_external_key'], $raw['scope_code']]);
                if (isset($distinct[$key])) {
                    $rejected++;
                    continue;
                }
                $distinct[$key] = true;
                $validLinks[] = $raw;
            }
        } elseif (is_array($inputLinks)) {
            $rejected = count($inputLinks);
        }

        $reviewedLinkCount = 0;
        if ($validLinks !== []) {
            $packs = QuestionPack::query()->where('status', 'published')
                ->where('exam_code', 'AP')->where('subject', '科目A')
                ->whereIn('slug', array_values(array_unique(array_column($validLinks, 'pack_slug'))))
                ->with(['questions' => fn ($q) => $q->where('is_active', true)])
                ->get()->keyBy('slug');
            foreach ($validLinks as $link) {
                $pack = $packs->get($link['pack_slug']);
                $meta = is_array($pack?->metadata) ? $pack->metadata : [];
                $routing = is_array($meta['match_terms'] ?? null) ? $meta['match_terms'] : [];
                $apRouted = collect($routing)->contains(fn ($term) => is_string($term)
                    && (mb_strtolower(trim($term)) === 'ap'
                        || str_contains($term, '応用情報')));
                if (! $pack || (string) $pack->version !== $link['pack_version']
                    || (! $apRouted && ($meta['content_kind'] ?? '') !== 'official_past_exam_curated')
                    || ! $this->readiness->inspect($pack)['publishable']) {
                    $rejected++;
                    continue;
                }
                $question = $pack->questions->firstWhere('external_key', $link['question_external_key']);
                if (! $question || ! $this->queue->isSupported($question)
                    || ! hash_equals($this->snapshotHash($question),
                        $link['question_snapshot_sha256'])) {
                    $rejected++;
                    continue;
                }
                $nodes[$link['scope_code']]['linked_question_count']++;
                $reviewedLinkCount++;
            }
        }

        return [
            'available' => true, 'source_identified' => true,
            'source' => $source, 'index_state' => $indexState,
            'nodes' => array_values($nodes), 'reviewed_link_count' => $reviewedLinkCount,
            'rejected_links' => $rejected, 'coverage_percent' => null,
            'coverage_denominator' => null,
            'index_complete' => false,
            'review_status' => 'review_records_only_not_independent_attestation',
        ];
    }

    /** Hash exact Question content, not only its source ID: reviewed mapping
     *  becomes stale on changed prompt/grading/metadata/explanation.
     */
    public function snapshotHash(Question $question): string
    {
        return hash('sha256', json_encode([
            'prompt' => $question->prompt,
            'response_schema' => $question->response_schema,
            'grading_rule' => $question->grading_rule,
            'learning_metadata' => $question->learning_metadata,
            'explanation' => $question->explanation,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function identity(mixed $value): bool
    {
        return is_string($value) && trim($value) !== ''
            && mb_strlen($value) <= 120
            && ! str_contains($value, '|');
    }

    /** @param array<string,mixed> $raw */
    private function reviewed(array $raw): bool
    {
        return is_string($raw['reviewer_id'] ?? null)
            && preg_match('/^[A-Za-z0-9._@-]{3,120}$/', $raw['reviewer_id']) === 1
            && is_string($raw['reviewed_at'] ?? null)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw['reviewed_at']) === 1
            && ($raw['reviewed_at'] <= today()->toDateString());
    }

    /** @param array<string,mixed> $raw
     *  @return array<string,string>|null
     */
    private function validateSource(array $raw): ?array
    {
        if (($raw['exam_code'] ?? '') !== 'AP'
            || ($raw['subject'] ?? '') !== '科目A'
            || ($raw['source_version'] ?? '') !== '7.2'
            || ($raw['published_date'] ?? '') !== '2026-01-08'
            || ($raw['source_index_url'] ?? '') !== 'https://www.ipa.go.jp/shiken/syllabus/gaiyou.html'
            || ($raw['source_document_url'] ?? '') !==
                'https://www.ipa.go.jp/shiken/syllabus/omgdg50000005kq5-att/syllabus_ap_ver7_2.pdf') {
            return null;
        }
        return [
            'title' => '応用情報技術者試験（レベル3）シラバス',
            'version' => '7.2',
            'index_url' => $raw['source_index_url'],
            'document_url' => $raw['source_document_url'],
            'published_date' => '2026-01-08',
            'label_update_date' => '2026-07-06',
        ];
    }
}
