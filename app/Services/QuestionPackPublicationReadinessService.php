<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionPack;
use Illuminate\Support\Collection;

/**
 * Read-only publication preflight. This service does not import, publish,
 * mutate grading rules, or assume a bundled JSON file is in the database.
 */
final class QuestionPackPublicationReadinessService
{
    public function __construct(
        private readonly AdaptiveLearningBankQueueService $adaptiveQueue,
    ) {}

    /**
     * @return array{
     *     active_count:int,
     *     one_question_count:int,
     *     missing_explanation_count:int,
     *     missing_attribution_count:int,
     *     blocking:list<string>,
     *     warnings:list<string>,
     *     publishable:bool
     * }
     */
    public function inspect(QuestionPack $pack): array
    {
        /** @var Collection<int, Question> $active */
        $active = $pack->relationLoaded('questions')
            ? $pack->questions->filter(fn (Question $question) => (bool) $question->is_active)
            : $pack->questions()->where('is_active', true)->get();

        $oneQuestion = $active->filter(
            fn (Question $question) => $this->adaptiveQueue->isSupported($question)
        )->count();

        $missingGrading = $active->filter(fn (Question $question) =>
            ! is_array($question->grading_rule)
            || trim((string) ($question->grading_rule['type'] ?? '')) === ''
        )->count();

        $missingLearningMetadata = $active->filter(function (Question $question): bool {
            $metadata = is_array($question->learning_metadata) ? $question->learning_metadata : [];

            foreach (['concepts', 'weakness_targets', 'tags', 'keywords'] as $key) {
                foreach (is_array($metadata[$key] ?? null) ? $metadata[$key] : [] as $term) {
                    if (is_string($term) && trim($term) !== '') {
                        return false;
                    }
                }
            }

            return true;
        })->count();

        $missingAttribution = $active->filter(fn (Question $question) =>
            in_array($question->source_type, ['official', 'licensed', 'derived'], true)
            && trim((string) $question->source_reference) === ''
        )->count();

        $missingExplanation = $active->filter(fn (Question $question) =>
            trim((string) $question->explanation) === ''
        )->count();

        $metadata = is_array($pack->metadata) ? $pack->metadata : [];
        $routingTerms = collect(is_array($metadata['match_terms'] ?? null)
            ? $metadata['match_terms'] : [])
            ->filter(fn ($term) => is_string($term) && trim($term) !== '');

        $blocking = [];
        if ($active->isEmpty()) {
            $blocking[] = '公開には有効な問題が1問以上必要です。';
        }
        if ($missingGrading > 0) {
            $blocking[] = '採点ルールがない問題が'.$missingGrading.'問あります。';
        }
        if ($missingLearningMetadata > 0) {
            $blocking[] = '学習分野・弱点タグがない問題が'.$missingLearningMetadata.'問あります。';
        }
        if ($missingAttribution > 0) {
            $blocking[] = '公式・ライセンス・派生問題の出典がない問題が'.$missingAttribution.'問あります。';
        }
        if (trim((string) $pack->exam_code) === '' && $routingTerms->isEmpty()) {
            $blocking[] = 'exam_codeまたはmetadata.match_termsが必要です。';
        }

        $warnings = [];
        if ($oneQuestion === 0) {
            $warnings[] = '新しい1問ずつ学習では未対応です（旧演習専用のPackとしては公開可能）。';
        }
        if ($missingExplanation > 0) {
            $warnings[] = '解説未登録が'.$missingExplanation.'問あります。学習効果を確認してから公開してください。';
        }

        return [
            'active_count' => $active->count(),
            'one_question_count' => $oneQuestion,
            'missing_explanation_count' => $missingExplanation,
            'missing_attribution_count' => $missingAttribution,
            'blocking' => $blocking,
            'warnings' => $warnings,
            'publishable' => $blocking === [],
        ];
    }
}
