<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionPack;
use DomainException;
use InvalidArgumentException;

/**
 * V6.21: operator-only, read-only inventory for independent syllabus reviewers.
 * This is NOT an official taxonomy, a signed review, or a mapping publisher.
 */
final class StudyOfficialSyllabusReviewPacketService
{
    private const MAX_CANDIDATES = 240;

    public function __construct(
        private readonly QuestionPackPublicationReadinessService $readiness,
        private readonly AdaptiveLearningBankQueueService $queue,
        private readonly StudyOfficialSyllabusBridgeService $bridge,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $packSlug): array
    {
        if (! $this->identity($packSlug)) {
            throw new InvalidArgumentException('Invalid pack identifier.');
        }

        $source = config('study_official_syllabus.ap_subject_a_2026', []);
        if (! is_array($source)
            || ($source['exam_code'] ?? null) !== 'AP'
            || ($source['subject'] ?? null) !== '科目A'
            || ($source['catalog_state'] ?? null) !== 'source_only'
            || ($source['source_version'] ?? null) !== '7.2'
            || ($source['published_date'] ?? null) !== '2026-01-08'
            || ($source['source_index_url'] ?? null) !== 'https://www.ipa.go.jp/shiken/syllabus/gaiyou.html'
            || ($source['source_document_url'] ?? null) !==
                'https://www.ipa.go.jp/shiken/syllabus/omgdg50000005kq5-att/syllabus_ap_ver7_2.pdf'
            || ($source['scope_nodes'] ?? null) !== []
            || ($source['question_links'] ?? null) !== []) {
            throw new DomainException('Official syllabus source-only registry is not verified.');
        }

        $pack = QuestionPack::query()
            ->where('slug', $packSlug)
            ->where('status', 'published')
            ->where('exam_code', 'AP')
            ->where('subject', '科目A')
            ->with(['questions' => fn ($query) => $query->where('is_active', true)])
            ->first();

        if (! $pack || ! $this->identity((string) $pack->version)) {
            throw new DomainException('Eligible published AP subject A pack not found.');
        }

        $metadata = is_array($pack->metadata) ? $pack->metadata : [];
        $matchTerms = is_array($metadata['match_terms'] ?? null)
            ? $metadata['match_terms'] : [];
        $routed = collect($matchTerms)->contains(fn ($term) => is_string($term)
            && (mb_strtolower(trim($term)) === 'ap' || str_contains($term, '応用情報')));
        if (! $routed && ($metadata['content_kind'] ?? null) !== 'official_past_exam_curated') {
            throw new DomainException('Pack does not have explicit AP routing.');
        }
        if (! $this->readiness->inspect($pack)['publishable']) {
            throw new DomainException('Pack fails the existing publication preflight.');
        }
        if ($pack->questions->count() > self::MAX_CANDIDATES) {
            throw new DomainException('Pack exceeds bounded reviewer inventory size.');
        }

        $candidates = [];
        foreach ($pack->questions as $question) {
            if (! $this->queue->isSupported($question)) {
                continue;
            }
            if (! $this->identity((string) $question->external_key)) {
                throw new DomainException('A source question has an invalid external key.');
            }
            $candidates[] = [
                'pack_slug' => $pack->slug,
                'pack_version' => (string) $pack->version,
                'question_external_key' => $question->external_key,
                'question_snapshot_sha256' => $this->bridge->snapshotHash($question),
                'proposed_scope_code' => null,
                'source_page' => null,
                'content_review' => 'pending',
                'rights_review' => 'pending',
            ];
        }
        if ($candidates === []) {
            throw new DomainException('No supported active questions in the pack.');
        }

        return [
            'schema' => 'canovia.learning.syllabus_review_packet.v1',
            'official_source' => [
                'exam_code' => 'AP',
                'subject' => '科目A',
                'source_version' => '7.2',
                'published_date' => '2026-01-08',
                'source_index_url' => $source['source_index_url'],
                'source_document_url' => $source['source_document_url'],
            ],
            'pack' => ['slug' => $pack->slug, 'version' => (string) $pack->version],
            'catalog_state' => 'source_only',
            'review_status' => 'pending_independent_human_content_and_rights_review',
            'scope_nodes' => [],
            'question_links' => [],
            'question_candidates' => $candidates,
            'excluded_unsupported_question_count' =>
                $pack->questions->count() - count($candidates),
            'official_coverage_percent' => null,
            'official_coverage_denominator' => null,
            'eligible_for_registry_import' => false,
            'question_content_included' => false,
            'read_only' => true,
        ];
    }

    private function identity(string $value): bool
    {
        return $value !== '' && trim($value) === $value
            && mb_strlen($value) <= 120 && ! str_contains($value, '|');
    }
}
