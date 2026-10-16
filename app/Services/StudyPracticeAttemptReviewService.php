<?php

namespace App\Services;

use App\Models\StudyPracticeAttempt;

/** Read-only ID joins. Ambiguous/missing records never borrow a neighbour's data. */
final class StudyPracticeAttemptReviewService
{
    public function forAttempt(StudyPracticeAttempt $attempt): array
    {
        $questions = $this->records($attempt->questions);
        $answers = $this->unique($this->records($attempt->answers), 'question_id');
        $assessment = is_array($attempt->assessment) ? $attempt->assessment : [];
        $feedback = $this->unique($this->records($assessment['question_feedback'] ?? []), 'question_id');
        $ids = array_count_values(array_filter(array_map(fn ($q) => $this->id($q['id'] ?? null), $questions), fn ($id) => $id !== ''));
        $cards = [];
        $counts = ['incorrect' => 0, 'partial' => 0, 'correct' => 0, 'ungraded' => 0];
        foreach ($questions as $ordinal => $question) {
            $id = $this->id($question['id'] ?? null);
            $joined = $id !== '' && ($ids[$id] ?? 0) === 1;
            $answer = $joined ? ($answers[$id] ?? null) : null;
            $review = $joined ? ($feedback[$id] ?? null) : null;
            $status = is_array($review) ? ($review['correctness'] ?? 'ungraded') : 'ungraded';
            if (! is_string($status) || ! array_key_exists($status, $counts)) $status = 'ungraded';
            $counts[$status]++;
            $rawFields = $this->records($question['response_fields'] ?? []);
            if ($rawFields === [] && isset($question['type'])) {
                $rawFields = [['id' => 'answer', 'type' => $question['type'] === 'text' ? 'textarea' : $question['type'],
                    'label' => '回答', 'choices' => $question['choices'] ?? []]];
            }
            $fieldIds = array_count_values(array_filter(array_map(fn ($f) => $this->id($f['id'] ?? null), $rawFields), fn ($id) => $id !== ''));
            $values = is_array($answer) ? $this->unique($this->records($answer['fields'] ?? []), 'field_id') : [];
            if (is_array($answer) && ! array_key_exists('fields', $answer) && array_key_exists('answer', $answer)) {
                $values = ['answer' => ['value' => $answer['answer']]];
            }
            $fields = [];
            foreach ($rawFields as $field) {
                $fieldId = $this->id($field['id'] ?? null);
                $choices = $this->records($field['choices'] ?? []);
                $choiceMap = $this->unique($choices, 'id');
                $record = $fieldId !== '' && ($fieldIds[$fieldId] ?? 0) === 1 ? ($values[$fieldId] ?? null) : null;
                $value = $record['value'] ?? null;
                $choiceType = in_array($field['type'] ?? '', ['single_choice', 'multiple_choice'], true);
                $selected = is_array($value) ? array_filter($value, 'is_scalar') : (is_scalar($value) ? [$value] : []);
                $selected = array_map('strval', $selected);
                $text = $choiceType
                    ? implode(' / ', array_map(fn ($v) => isset($choiceMap[$v])
                        ? $v.'：'.$this->text($choiceMap[$v]['label'] ?? null)
                        : $v.'（選択肢本文を対応付けられません）', $selected))
                    : (is_scalar($value) ? (string) $value : '');
                $fields[] = ['id' => $fieldId, 'type' => $this->text($field['type'] ?? ''),
                    'label' => $this->text($field['label'] ?? '') ?: '回答',
                    'required' => (bool) ($field['required'] ?? true), 'choices' => $choices,
                    'selected_ids' => $selected, 'answer_text' => $text,
                    'recorded' => $record !== null && $text !== ''];
            }
            $cards[] = ['id' => $id, 'ordinal' => $ordinal + 1, 'question' => $question,
                'prompt' => $this->text($question['prompt'] ?? '') ?: '問題文は記録されていません。',
                'status' => $status, 'mapping_issue' => ! $joined || $answer === null || $review === null,
                'fields' => $fields, 'feedback' => $this->text($review['feedback'] ?? ''),
                'reasoning_feedback' => $this->text($review['reasoning_feedback'] ?? ''),
                'misconceptions' => array_map(fn ($v) => $this->text($v), $this->scalars($review['misconceptions'] ?? [])),
                'reference' => $joined ? app(StudyPracticeReviewReferenceService::class)->forQuestion($assessment, $question) : null];
        }
        $rank = ['incorrect' => 0, 'partial' => 1, 'ungraded' => 2, 'correct' => 3];
        usort($cards, fn ($a, $b) => ($rank[$a['status']] <=> $rank[$b['status']]) ?: ($a['ordinal'] <=> $b['ordinal']));
        return ['cards' => $cards, 'counts' => $counts,
            'trusted' => count($cards) > 0 && collect($cards)->every(fn ($c) => $c['reference'] !== null)];
    }

    private function unique(array $records, string $key): array
    {
        $groups = [];
        foreach ($records as $record) {
            $id = $this->id($record[$key] ?? null);
            if ($id !== '') $groups[$id][] = $record;
        }
        return array_map(fn ($group) => count($group) === 1 ? $group[0] : null, $groups);
    }

    private function records(mixed $raw): array { return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : []; }
    private function scalars(mixed $raw): array { return is_array($raw) ? array_values(array_filter($raw, 'is_scalar')) : []; }
    private function id(mixed $value): string { return is_string($value) || is_int($value) ? (string) $value : ''; }
    private function text(mixed $value): string { return is_scalar($value) ? (string) $value : ''; }
}
