<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionPack;
use Illuminate\Support\Collection;

/**
 * Format verification does not make a Bank publication an exam-ready set.
 * This read-only preflight is shared by entry discovery and start() so the
 * learning UI never advertises a pack that the exam route must reject.
 */
final class AdaptiveExamPackReadinessService
{
    public function __construct(private readonly AdaptiveLearningBankQueueService $bank) {}

    /**
     * @param array<string,mixed> $profile
     * @param Collection<int,Question>|null $questions
     * @return array{ready:bool,active_count:int,blocking:list<string>}
     */
    public function inspect(QuestionPack $pack, array $profile, ?Collection $questions = null): array
    {
        $blocking = [];
        $metadata = is_array($pack->metadata) ? $pack->metadata : [];
        if ($pack->status !== 'published') {
            $blocking[] = '公開済みの問題集ではありません。';
        }
        if ($pack->exam_code !== ($profile['exam_code'] ?? null)
            || $pack->subject !== ($profile['subject'] ?? null)
            || ($metadata['exam_simulation_profile_key'] ?? '') !== ($profile['key'] ?? null)
            || (string) ($metadata['exam_simulation_profile_version'] ?? '') !== (string) ($profile['version'] ?? '')) {
            $blocking[] = '試験プロファイルと問題集の識別情報が一致しません。';
        }

        $questions ??= $pack->questions()->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get();
        $expected = (int) ($profile['question_count'] ?? 0);
        if ($expected < 1 || $questions->count() !== $expected) {
            $blocking[] = "有効問題数が{$expected}問に一致しません（現在{$questions->count()}問）。";
        }

        $invalid = $questions->filter(function (Question $question) use ($profile): bool {
            if (! $this->bank->isSupported($question)
                || ($question->grading_rule['type'] ?? '') !== 'exact_choice') {
                return true;
            }
            $field = collect($question->response_schema ?? [])->firstWhere('id', 'answer');
            if (! is_array($field) || ($field['type'] ?? '') !== 'single_choice') {
                return true;
            }
            $choices = $field['choices'] ?? [];
            $choiceCount = (int) ($profile['choices_per_question'] ?? 0);
            if (! is_array($choices) || ($choiceCount > 0 && count($choices) !== $choiceCount)) {
                return true;
            }
            return collect($choices)->contains(fn ($choice) =>
                ! is_array($choice) || ! is_string($choice['label'] ?? null)
                || trim($choice['label']) === '');
        })->count();
        if ($invalid > 0) {
            $blocking[] = "試験の四肢択一・採点形式に一致しない問題が{$invalid}問あります。";
        }

        $attributionMissing = $questions->filter(fn (Question $question) =>
            in_array($question->source_type, ['official', 'licensed', 'derived'], true)
            && trim((string) $question->source_reference) === '')->count();
        if ($attributionMissing > 0) {
            $blocking[] = "出典情報のない問題が{$attributionMissing}問あります。";
        }

        if (($profile['requires_pack_review'] ?? false) === true) {
            $review = is_array($metadata['exam_simulation_review'] ?? null)
                ? $metadata['exam_simulation_review'] : [];
            if (($review['reviewed_pack_version'] ?? '') !== $pack->version
                || ($review['format_checked'] ?? false) !== true
                || ($review['answer_key_checked'] ?? false) !== true
                || ($review['content_rights_checked'] ?? false) !== true
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($review['reviewed_at'] ?? ''))) {
                $blocking[] = 'この版の80問・正答・利用条件に対する管理者の最終確認が未記録です。';
            }
        }

        return [
            'ready' => $blocking === [],
            'active_count' => $questions->count(),
            'blocking' => $blocking,
        ];
    }
}
