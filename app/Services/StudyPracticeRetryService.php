<?php

namespace App\Services;

use App\Models\StudyPracticeAttempt;
use App\Models\StudyPracticeSession;
use Illuminate\Validation\ValidationException;

/** Same-question recall practice. It never emits a graded Attempt or learning evidence. */
final class StudyPracticeRetryService
{
    public function cards(StudyPracticeAttempt $source): array
    {
        $review = app(StudyPracticeAttemptReviewService::class)->forAttempt($source);
        $cards = array_values(array_filter($review['cards'], fn ($c) => in_array($c['status'], ['incorrect', 'partial'], true)));
        foreach ($cards as $card) {
            if ($card['mapping_issue'] || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $card['id'])
                || count($card['fields']) < 1 || count($card['fields']) > 4) return [];
            $seen = [];
            foreach ($card['fields'] as $field) {
                if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $field['id']) || isset($seen[$field['id']])
                    || ! in_array($field['type'], ['single_choice', 'multiple_choice', 'number', 'short_text', 'textarea'], true)) return [];
                $seen[$field['id']] = true;
                if (in_array($field['type'], ['single_choice', 'multiple_choice'], true)) {
                    if (count($field['choices']) < 2 || count($field['choices']) > 6) return [];
                    $choices = [];
                    foreach ($field['choices'] as $choice) {
                        $id = $choice['id'] ?? null;
                        if (! is_string($id) || ! preg_match('/^[\p{L}\p{N}_-]{1,20}$/uD', $id)
                            || isset($choices[$id]) || ! is_string($choice['label'] ?? null)) return [];
                        $choices[$id] = true;
                    }
                }
            }
        }
        if (count($cards) > 20) return [];
        $profile = data_get($source->practiceSession?->selection_context, 'strategy.exam_profile');
        if (! is_array($profile)) $profile = app(StudyPracticeExamProfileService::class)->forPlanTask($source->plan, $source->task);
        try {
            app(StudyPracticeQuestionIntegrityService::class)->assertValid(array_column($cards, 'question'), $profile);
        } catch (ValidationException) {
            return []; // Keep #778's existing rejection, without guessing a repair.
        }
        return $cards;
    }

    public function result(StudyPracticeAttempt $source, array $cards, array $raw): array
    {
        if (array_diff(array_keys($raw), array_column($cards, 'id')) !== []) $this->fail('未対応の問題IDが含まれています。');
        $answers = []; $feedback = []; $graded = 0; $correctCount = 0;
        foreach ($cards as $card) {
            $input = $raw[$card['id']] ?? [];
            if (! is_array($input) || array_diff(array_keys($input), array_column($card['fields'], 'id')) !== []) $this->fail('回答欄のIDが一致しません。');
            $fields = [];
            foreach ($card['fields'] as $field) {
                $value = $input[$field['id']] ?? ($field['type'] === 'multiple_choice' ? [] : '');
                $allowed = array_column($field['choices'], 'id');
                if ($field['type'] === 'multiple_choice') {
                    if (! is_array($value) || ! array_is_list($value) || count($value) > 6
                        || ! collect($value)->every(fn ($v) => is_string($v) && in_array($v, $allowed, true))
                        || count($value) !== count(array_unique($value))) $this->fail('有効な選択肢を重複なく選んでください。');
                    sort($value, SORT_STRING);
                } else {
                    if (! is_string($value)) $this->fail('回答形式が一致しません。');
                    $value = trim($value);
                    if (mb_strlen($value) > ($field['type'] === 'textarea' ? 12000 : 3000)) $this->fail('回答が長すぎます。');
                    if ($field['type'] === 'single_choice' && $value !== '' && ! in_array($value, $allowed, true)) $this->fail('有効な選択肢を選んでください。');
                    if ($field['type'] === 'number' && $value !== '' && (! is_numeric($value) || ! is_finite((float) $value))) $this->fail('有限の数値を入力してください。');
                }
                if ($field['required'] && ($value === '' || $value === [])) $this->fail('必須の回答欄を入力してください。');
                $fields[] = ['field_id' => $field['id'], 'type' => $field['type'], 'label' => $field['label'], 'value' => $value];
            }
            $answers[] = ['question_id' => $card['id'], 'fields' => $fields];
            $rule = $card['reference']['rule'] ?? null;
            $value = is_array($rule) ? (collect($fields)->firstWhere('field_id', $rule['field_id'] ?? '')['value'] ?? null) : null;
            $correct = is_array($rule) ? app(QuestionBankGrader::class)->gradeRule($rule, $value) : null;
            if ($correct !== null) { $graded++; $correctCount += (int) $correct; }
            $feedback[] = ['question_id' => $card['id'], 'correctness' => $correct === null ? 'ungraded' : ($correct ? 'correct' : 'incorrect'),
                'feedback' => $correct === null
                    ? '再回答を保存しました。保存済みの正答根拠がないため、正誤の判定は保留しています。'
                    : '保存済みのBankルールとの照合結果です。同じ問題への再回答だけでは習熟・弱点解消を判定しません。',
                'reasoning_feedback' => '', 'misconceptions' => []];
        }
        if (strlen(json_encode($answers, JSON_THROW_ON_ERROR)) > 120000) $this->fail('回答全体が長すぎます。');
        $assessment = ['question_feedback' => $feedback, 'graded_count' => $graded,
            'correct_count' => $correctCount, 'ungraded_count' => count($cards) - $graded,
            'review_reference_v1' => $source->assessment['review_reference_v1'] ?? null];
        return ['answers' => $answers, 'assessment' => $assessment];
    }

    public function review(StudyPracticeSession $retry): array
    {
        $review = app(StudyPracticeAttemptReviewService::class)->forAttempt(new StudyPracticeAttempt([
            'questions' => $retry->questions_snapshot,
            'answers' => $retry->assessment_payload['answers'] ?? [],
            'assessment' => $retry->assessment_payload['assessment'] ?? [],
        ]));
        foreach ($review['cards'] as &$card) {
            $ordinal = $retry->provider_payload['source_ordinals'][$card['id']] ?? null;
            if (is_int($ordinal) && $ordinal > 0) $card['ordinal'] = $ordinal;
        }
        unset($card);
        return $review;
    }

    private function fail(string $message): never { throw ValidationException::withMessages(['answers' => $message]); }
}
