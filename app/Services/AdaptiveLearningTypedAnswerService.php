<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Validates one answer against the immutable per-run Bank snapshot.
 * Only server-side grading rules determine correctness.
 */
final class AdaptiveLearningTypedAnswerService
{
    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $input
     * @return array{payload:array{type:string,value:mixed},stored_value:string,graded_value:mixed,grading_method:string}
     */
    public function normalize(array $snapshot, array $rule, array $input): array
    {
        $field = data_get($snapshot, 'response_field', []);
        $type = (string) data_get($field, 'type', '');
        $ruleType = (string) ($rule['type'] ?? '');
        $allowed = collect(is_array($field['choices'] ?? null) ? $field['choices'] : [])
            ->pluck('id')->map('strval')->all();

        if ($type === 'single_choice' && $ruleType === 'exact_choice') {
            $value = $input['choice'] ?? null;
            if (! is_string($value) || ! in_array($value, $allowed, true)) {
                $this->fail('choice', '有効な選択肢を1つ選んでください。');
            }
            return [
                'payload' => ['type' => $type, 'value' => $value],
                'stored_value' => $value,
                'graded_value' => $value,
                'grading_method' => 'question_bank_exact_choice',
            ];
        }

        if ($type === 'multiple_choice' && $ruleType === 'exact_multiple') {
            $value = $input['choices'] ?? null;
            if (! is_array($value) || ! array_is_list($value)
                || count($value) < 1 || count($value) > 8
                || count($value) !== count(array_unique($value))
                || ! collect($value)->every(fn ($choice) =>
                    is_string($choice) && in_array($choice, $allowed, true))) {
                $this->fail('choices', '選択肢から重複なく1つ以上選んでください。');
            }
            sort($value, SORT_STRING);
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            // Legacy answer_value is varchar(255). Typed JSON is the source
            // of truth for long Unicode choice IDs; never truncate an answer.
            $legacyValue = strlen($encoded) <= 255
                ? $encoded : 'multiple:sha256:'.hash('sha256', $encoded);
            return [
                'payload' => ['type' => $type, 'value' => $value],
                'stored_value' => $legacyValue,
                'graded_value' => $value,
                'grading_method' => 'question_bank_exact_multiple',
            ];
        }

        if ($type === 'number' && $ruleType === 'numeric_tolerance') {
            $raw = $input['number'] ?? null;
            if (! is_string($raw) || strlen($raw) > 64) {
                $this->fail('number', '数値を入力してください。');
            }
            $raw = trim($raw);
            if (! preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d{1,3})?\z/D', $raw)
                || ! is_numeric($raw) || ! is_finite((float) $raw)) {
                $this->fail('number', '有効な有限数値を半角数字で入力してください。');
            }
            // The current grader compares floats; canonicalize retries to
            // that same semantic value (e.g. 42, 42.0 and 4.2e1 are equal).
            $value = (string) (float) $raw;
            return [
                'payload' => ['type' => $type, 'value' => $value],
                'stored_value' => $value,
                'graded_value' => $value,
                'grading_method' => 'question_bank_numeric_tolerance',
            ];
        }

        abort(409, 'この問題の回答形式はサポートされていません。');
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
