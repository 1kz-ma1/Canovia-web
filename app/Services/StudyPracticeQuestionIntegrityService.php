<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Format integrity only: never guesses whether an answer is factually correct. */
final class StudyPracticeQuestionIntegrityService
{
    public function assertValid(array $questions, array $examProfile): void
    {
        if (($examProfile['key'] ?? null) !== 'ap_subject_a_exam') {
            return;
        }

        foreach (array_values($questions) as $index => $question) {
            $fields = is_array($question) ? ($question['response_fields'] ?? []) : [];
            if (! is_array($fields)) {
                continue; // Structural validation belongs to the existing normalizer.
            }
            $fields = array_filter($fields, 'is_array');
            if (! collect($fields)->contains(fn ($field) => ($field['type'] ?? '') === 'single_choice')) {
                continue; // Retain older numeric/description exercises and generic compound formats.
            }

            $independent = array_filter($fields, function ($field) {
                $required = $field['required'] ?? true;
                $optional = $required === false || in_array($required, ['false', '0', 'no', 0], true);
                return ($field['type'] ?? '') !== 'textarea' || ! $optional;
            });
            if (count($independent) !== 1) {
                throw ValidationException::withMessages(['questions_json' =>
                    'Q'.($index + 1).'：AP科目Aの単問四択に複数の独立した回答欄が含まれています。'
                    .'別問題は別のquestionに分け、各問の最終回答はsingle_choiceを1件にしてください。'
                    .'同じ問題の考え方・計算過程は任意のtextareaにできます。'
                    .'選択肢を自動削除せず、元の問題文と照合してJSONを修正・再取込してください。']);
            }
        }
    }
}
