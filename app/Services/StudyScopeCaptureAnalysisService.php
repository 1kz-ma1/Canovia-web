<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\StudyScopeCapture;
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class StudyScopeCaptureAnalysisService
{
    public function __construct(
        private readonly NativeAiGateway $nativeAi,
    ) {}

    /**
     * @return array{run_id:int,item_count:int}
     */
    public function analyze(
        StudyScopeCapture $capture,
        Plan $plan,
        ?int $userId = null,
    ): array {
        abort_unless((int) $capture->plan_id === (int) $plan->id, 404);

        $capture->loadMissing('inboxItem');
        $source = $capture->inboxItem;

        if (! $source) {
            throw new RuntimeException('学習範囲の元ファイルが見つかりません。');
        }

        $capture->update([
            'status' => 'captured',
            'failure_code' => null,
        ]);

        $prompt = implode("\n", [
            'あなたはCanoviaのStudy Scope Capture抽出器です。',
            '学校の定期テスト、模試、大学の試験、資格試験などの「試験範囲」を構造化してください。',
            '',
            '【最重要ルール】',
            '- 添付された画像またはPDFと、補足メモに明示されている事実だけを使う',
            '- 見えていない科目・単元・ページ・日付を推測して追加しない',
            '- 読み取れない箇所は無理に補完せずambiguitiesへ入れる',
            '- 同じ科目でも範囲が分かれている場合は複数itemにしてよい',
            '- subjectが資料上で特定できない場合はnull',
            '- unitが特定できない場合はnull',
            '- ページ番号は明示されている場合だけpage_start/page_endへ入れる',
            '- page_startだけ明示されている場合はpage_endをnullにする',
            '- exam_dateはYYYY-MM-DDとして確実に正規化できる場合だけ入れる',
            '- 年が書かれておらず確実に決められない場合、exam_dateはnullにしてexam_date_textへ原文を残す',
            '- source_excerptは根拠となる短い文字列だけ。全文転記しない',
            '- confidenceは各itemの読取根拠の明確さを0〜100で表す',
            '- 普通の学校のテスト範囲表でも自然に扱う',
            '',
            '【Plan context】',
            'category: '.mb_substr((string) ($plan->category ?? ''), 0, 100),
            'plan_deadline: '.($plan->deadline?->format('Y-m-d') ?? 'なし'),
            '',
            '【補足メモ】',
            mb_substr((string) ($source->content ?? ''), 0, 2000) ?: 'なし',
        ]);

        try {
            $result = $this->nativeAi->generateStructured(
                purpose: 'study_scope_capture_extraction',
                prompt: $prompt,
                schema: $this->schema(),
                schemaName: 'canovia_study_scope_capture',
                plan: $plan,
                task: null,
                maxOutputTokens: 5000,
                userId: $userId,
                capacityTier: 'standard',
                metadata: [
                    'study_scope_capture_id' => (int) $capture->id,
                    'source_type' => $source->source_type,
                ],
                inputParts: $this->inputParts($source),
                featureKey: FeatureKey::StudyScopeCapture,
            );
        } catch (NativeAiExecutionException $exception) {
            $capture->update([
                'status' => 'failed',
                'native_ai_run_id' => $exception->runId,
                'failure_code' => mb_substr($exception->errorCode, 0, 80),
                'analyzed_at' => now(),
            ]);

            throw $exception;
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $items = collect($data['items'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->take(80)
            ->map(fn (array $item) => $this->normalizeItem($item))
            ->filter(fn (array $item) => $this->hasScopeContent($item))
            ->values()
            ->all();

        $ambiguities = collect($data['ambiguities'] ?? [])
            ->filter(fn ($item) => is_scalar($item))
            ->map(fn ($item) => mb_substr(trim((string) $item), 0, 500))
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();

        $examTitle = $this->nullableString($data['exam_title'] ?? null, 255);
        $examDateText = $this->nullableString($data['exam_date_text'] ?? null, 255);
        $examDate = $this->normalizedDate($data['exam_date'] ?? null);
        $overallConfidence = max(0, min(100, (int) ($data['overall_confidence'] ?? 0)));

        $capture->update([
            'status' => 'review',
            'native_ai_run_id' => (int) $result['run_id'],
            'exam_title' => $examTitle,
            'exam_date_text' => $examDateText,
            'exam_date' => $examDate,
            'draft_data' => [
                'items' => $items,
                'ambiguities' => $ambiguities,
            ],
            'confidence' => round($overallConfidence / 100, 4),
            'extraction_version' => 'study_scope_v1',
            'failure_code' => null,
            'analyzed_at' => now(),
        ]);

        return [
            'run_id' => (int) $result['run_id'],
            'item_count' => count($items),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function inputParts(InboxItem $source): array
    {
        if (! $source->storage_path || ! Storage::exists($source->storage_path)) {
            throw new RuntimeException('学習範囲の元ファイルを読み込めません。');
        }

        $bytes = Storage::get($source->storage_path);
        $base64 = base64_encode($bytes);

        if ($source->source_type === 'image') {
            return [[
                'type' => 'input_image',
                'image_url' => 'data:'.($source->mime_type ?: 'image/jpeg').';base64,'.$base64,
                'detail' => 'high',
            ]];
        }

        if ($source->source_type === 'pdf') {
            return [[
                'type' => 'input_file',
                'filename' => $source->original_name ?: 'study-scope.pdf',
                'file_data' => 'data:application/pdf;base64,'.$base64,
                'detail' => 'auto',
            ]];
        }

        throw new RuntimeException('Study Scope Captureで未対応のファイル形式です。');
    }

    private function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'exam_title',
                'exam_date_text',
                'exam_date',
                'overall_confidence',
                'items',
                'ambiguities',
            ],
            'properties' => [
                'exam_title' => $nullableString,
                'exam_date_text' => $nullableString,
                'exam_date' => $nullableString,
                'overall_confidence' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                ],
                'items' => [
                    'type' => 'array',
                    'maxItems' => 80,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'subject',
                            'unit',
                            'range_text',
                            'page_start',
                            'page_end',
                            'source_excerpt',
                            'confidence',
                        ],
                        'properties' => [
                            'subject' => $nullableString,
                            'unit' => $nullableString,
                            'range_text' => $nullableString,
                            'page_start' => ['type' => ['integer', 'null'], 'minimum' => 1],
                            'page_end' => ['type' => ['integer', 'null'], 'minimum' => 1],
                            'source_excerpt' => $nullableString,
                            'confidence' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'maximum' => 100,
                            ],
                        ],
                    ],
                ],
                'ambiguities' => [
                    'type' => 'array',
                    'maxItems' => 20,
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeItem(array $item): array
    {
        return [
            'subject' => $this->nullableString($item['subject'] ?? null, 120),
            'unit' => $this->nullableString($item['unit'] ?? null, 255),
            'range_text' => $this->nullableString($item['range_text'] ?? null, 1000),
            'page_start' => $this->nullablePage($item['page_start'] ?? null),
            'page_end' => $this->nullablePage($item['page_end'] ?? null),
            'source_excerpt' => $this->nullableString($item['source_excerpt'] ?? null, 1000),
            'confidence' => max(0, min(100, (int) ($item['confidence'] ?? 0))),
        ];
    }

    private function hasScopeContent(array $item): bool
    {
        return filled($item['subject'])
            || filled($item['unit'])
            || filled($item['range_text'])
            || $item['page_start'] !== null
            || $item['page_end'] !== null;
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, $maxLength) : null;
    }

    private function nullablePage(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value >= 1 && $value <= 100000 ? $value : null;
    }

    private function normalizedDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (! $date) {
            return null;
        }

        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $value : null;
    }
}
