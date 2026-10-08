<?php

namespace App\Services;

use App\Models\Question;
use App\Models\Task;
use Illuminate\Support\Collection;

class QuestionBankGrader
{
    public const SUPPORTED_RULES = ['exact_choice', 'exact_multiple', 'numeric_tolerance'];

    /** Grade from an immutable server-only snapshot, independent of later Bank edits. */
    public function gradeRule(array $rule, mixed $value): ?bool
    {
        if (! in_array((string) ($rule['type'] ?? ''), self::SUPPORTED_RULES, true)) {
            return null;
        }
        return $this->isCorrect($rule, $value);
    }

    /**
     * @param array<int, array<string, mixed>> $questions
     * @param array<int, array<string, mixed>> $answers
     */
    public function canGrade(array $questions, array $answers = []): bool
    {
        if ($questions === []) {
            return false;
        }

        $sourceIds = collect($questions)
            ->pluck('source_question_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($sourceIds->count() !== count($questions)) {
            return false;
        }

        $models = Question::query()
            ->whereIn('id', $sourceIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        if ($models->count() !== $sourceIds->unique()->count()) {
            return false;
        }

        $answersByQuestion = collect($answers)->keyBy('question_id');

        foreach ($questions as $question) {
            $model = $models->get((int) $question['source_question_id']);
            $rule = $model?->grading_rule ?? [];
            $type = (string) ($rule['type'] ?? '');
            $fieldId = (string) ($rule['field_id'] ?? 'answer');

            if (! in_array($type, self::SUPPORTED_RULES, true) || $fieldId === '') {
                return false;
            }

            $requiredNonGraded = collect($model->response_schema ?? [])
                ->filter(fn ($field) => is_array($field) && (bool) ($field['required'] ?? true))
                ->filter(fn ($field) => (string) ($field['id'] ?? '') !== $fieldId);

            if ($requiredNonGraded->isNotEmpty()) {
                return false;
            }

            $questionAnswer = $answersByQuestion->get((string) ($question['id'] ?? ''), []);
            $hasNonGradedInput = collect(is_array($questionAnswer) ? ($questionAnswer['fields'] ?? []) : [])
                ->filter(fn ($field) => is_array($field) && (string) ($field['field_id'] ?? '') !== $fieldId)
                ->contains(fn ($field) => $this->hasMeaningfulValue($field['value'] ?? null));

            if ($hasNonGradedInput) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $questions
     * @param array<int, array<string, mixed>> $answers
     * @return array<string, mixed>
     */
    public function grade(Task $task, array $questions, array $answers): array
    {
        $sourceIds = collect($questions)
            ->pluck('source_question_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $models = Question::query()
            ->whereIn('id', $sourceIds)
            ->get()
            ->keyBy('id');

        $answersByQuestion = collect($answers)->keyBy('question_id');
        $feedback = [];
        $correctCount = 0;
        $strengths = collect();
        $weaknesses = collect();

        foreach ($questions as $question) {
            $questionId = (string) $question['id'];
            $model = $models->get((int) $question['source_question_id']);
            $rule = $model?->grading_rule ?? [];
            $fieldId = (string) ($rule['field_id'] ?? 'answer');
            $answer = $answersByQuestion->get($questionId, []);
            $value = collect(is_array($answer) ? ($answer['fields'] ?? []) : [])
                ->firstWhere('field_id', $fieldId)['value'] ?? null;

            $correct = $this->isCorrect($rule, $value);
            if ($correct) {
                $correctCount++;
            }

            $concepts = $this->concepts($model);
            if ($correct) {
                $strengths = $strengths->merge($concepts);
            } else {
                $weaknesses = $weaknesses->merge($concepts);
            }

            $feedback[] = [
                'question_id' => $questionId,
                'correctness' => $correct ? 'correct' : 'incorrect',
                'feedback' => $model?->explanation
                    ?: ($correct ? '正解です。' : '正答条件を満たしていません。'),
                'reasoning_feedback' => '',
                // Deterministic choice grading can prove correctness, but it
                // cannot safely infer why the learner chose a distractor.
                'error_type' => $correct ? 'none' : 'unknown',
                'weakness_topics' => $correct ? [] : $concepts->take(3)->values()->all(),
                'misconceptions' => $correct ? [] : $concepts->take(3)->values()->all(),
            ];
        }

        $total = max(1, count($questions));
        $score = (int) round(($correctCount / $total) * 100);
        $weaknessList = $weaknesses->filter()->unique()->take(8)->values()->all();
        $strengthList = $strengths->filter()->unique()->take(8)->values()->all();

        return [
            'score_percent' => $score,
            'question_feedback' => $feedback,
            'strengths' => $strengthList,
            'weaknesses' => $weaknessList,
            // Deterministic grading does not infer Task completion.
            'recommended_task_progress_percent' => (int) $task->progress_percent,
            'evidence_summary' => "Question Bankの採点ルールで{$correctCount}/{$total}問を正解しました。",
            'next_action' => $weaknessList !== []
                ? implode(' / ', array_slice($weaknessList, 0, 3)).'を復習して再挑戦する'
                : '同じ分野の応用・定着問題へ進む',
        ];
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function isCorrect(array $rule, mixed $value): bool
    {
        return match ((string) ($rule['type'] ?? '')) {
            'exact_choice' => (string) $value === (string) ($rule['answer'] ?? ''),
            'exact_multiple' => $this->sameSet($value, $rule['answers'] ?? []),
            'numeric_tolerance' => $this->withinTolerance($value, $rule),
            default => false,
        };
    }

    private function sameSet(mixed $actual, mixed $expected): bool
    {
        if (! is_array($actual) || ! is_array($expected)) {
            return false;
        }

        $actual = array_values(array_unique(array_map('strval', $actual)));
        $expected = array_values(array_unique(array_map('strval', $expected)));
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function withinTolerance(mixed $actual, array $rule): bool
    {
        if (! is_numeric($actual) || ! is_numeric($rule['answer'] ?? null)) {
            return false;
        }

        $tolerance = is_numeric($rule['tolerance'] ?? null)
            ? max(0.0, (float) $rule['tolerance'])
            : 0.0;

        return abs((float) $actual - (float) $rule['answer']) <= $tolerance;
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        if (is_array($value)) {
            return collect($value)->filter(fn ($item) => trim((string) $item) !== '')->isNotEmpty();
        }

        return is_scalar($value) && trim((string) $value) !== '';
    }

    private function concepts(?Question $question): Collection
    {
        return collect(data_get($question?->learning_metadata, 'concepts', []))
            ->merge(data_get($question?->learning_metadata, 'weakness_targets', []))
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn ($item) => trim($item))
            ->unique();
    }
}
