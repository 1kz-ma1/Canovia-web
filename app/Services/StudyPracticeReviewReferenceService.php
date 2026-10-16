<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Support\Collection;

/** New server-graded attempts only. Never rebuilds old answers from today's Bank. */
final class StudyPracticeReviewReferenceService
{
    public function capture(array $questions, Collection $models): array
    {
        $entries = [];
        $packs = [];
        foreach ($questions as $question) {
            $model = $models->get((int) ($question['source_question_id'] ?? 0));
            if (! $model instanceof Question || ! $model->is_active
                || $model->source_type === 'ai_generated' || $model->prompt !== ($question['prompt'] ?? null)
                || ! app(AdaptiveLearningBankQueueService::class)->isSupported($model)) {
                continue;
            }
            $pack = $model->pack;
            if (! $pack || $pack->status !== 'published') continue;
            $packs[$pack->id] ??= app(QuestionPackPublicationReadinessService::class)->inspect($pack)['publishable'];
            if (! $packs[$pack->id]) continue;

            $field = collect($question['response_fields'] ?? [])->firstWhere('id', 'answer');
            $expected = collect($model->response_schema)->firstWhere('id', 'answer');
            if (! is_array($field) || ($field['type'] ?? null) !== ($expected['type'] ?? null)
                || ($field['choices'] ?? []) !== ($expected['choices'] ?? [])) continue;
            $rule = $model->grading_rule;
            $ids = ($rule['type'] ?? '') === 'exact_multiple' ? $rule['answers'] : [$rule['answer']];
            $labels = collect($field['choices'] ?? [])->pluck('label', 'id');
            $text = ($rule['type'] ?? '') === 'numeric_tolerance'
                ? (string) $rule['answer'].'（許容差 '.($rule['tolerance'] ?? 0).'）'
                : collect($ids)->map(fn ($id) => $id.'：'.$labels->get($id))->implode(' / ');
            $entries[] = ['question_id' => (string) $question['id'],
                'question_hash' => $this->questionHash($question), 'rule' => $rule,
                'answer_text' => $text, 'explanation' => (string) $model->explanation,
                'source_reference' => (string) $model->source_reference];
        }
        return ['source' => 'server_published_bank_v1', 'entries' => $entries];
    }

    public function forQuestion(array $assessment, array $question): ?array
    {
        $evidence = $assessment['review_reference_v1'] ?? null;
        if (! is_array($evidence) || ($evidence['source'] ?? '') !== 'server_published_bank_v1') return null;
        $entries = array_values(array_filter((array) ($evidence['entries'] ?? []),
            fn ($entry) => is_array($entry) && ($entry['question_id'] ?? null) === ($question['id'] ?? null)));
        if (count($entries) !== 1 || ($entries[0]['question_hash'] ?? '') !== $this->questionHash($question)) return null;
        return $entries[0];
    }

    public function questionHash(array $question): string
    {
        $canonical = function ($value) use (&$canonical) {
            if (! is_array($value)) return $value;
            if (! array_is_list($value)) ksort($value, SORT_STRING);
            return array_map($canonical, $value);
        };
        return hash('sha256', json_encode($canonical($question), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
