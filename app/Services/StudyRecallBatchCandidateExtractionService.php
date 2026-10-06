<?php

namespace App\Services;

use App\Exceptions\NativeAiExecutionException;
use App\Models\Plan;
use App\Models\StudyRecallCandidate;
use App\Models\StudyRecallSource;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class StudyRecallBatchCandidateExtractionService
{
    public const MIN_SOURCES = 2;
    public const MAX_SOURCES = 5;

    public function __construct(
        private readonly NativeAiGateway $nativeAi,
    ) {}

    /**
     * @param Collection<int,StudyRecallSource> $sources
     * @return array{created:int,run_id:int,total:int,source_count:int}
     */
    public function extract(
        Collection $sources,
        Plan $plan,
        Task $task,
        ?int $userId = null,
    ): array {
        $sources = $sources
            ->filter(fn ($source) => $source instanceof StudyRecallSource)
            ->values();

        if (
            $sources->count() < self::MIN_SOURCES
            || $sources->count() > self::MAX_SOURCES
        ) {
            throw new RuntimeException(
                'Recall batchは2〜5件の教材で実行してください。',
            );
        }

        foreach ($sources as $source) {
            if (
                (int) $source->plan_id !== (int) $plan->id
                || (int) $source->task_id !== (int) $task->id
            ) {
                throw new RuntimeException(
                    '別のPlan / Taskの教材を同じRecall batchへ混在できません。',
                );
            }

            if (! in_array($source->source_type, ['image', 'pdf'], true)) {
                throw new RuntimeException(
                    'Recall batchは画像またはPDF教材だけを扱います。',
                );
            }

            if (
                ! $source->storage_path
                || ! Storage::exists($source->storage_path)
            ) {
                throw new RuntimeException(
                    'Recall batchの教材ファイルを読み込めませんでした。',
                );
            }
        }

        $manifest = $sources
            ->map(fn (StudyRecallSource $source, int $index) =>
                $index.': '
                .($source->original_name ?: $source->sourceLabel())
                .' ('.$source->source_type.')'
            )
            ->implode("\n");

        $prompt = implode("\n", [
            'あなたはCanoviaのRecallカード候補抽出器です。',
            '複数の教材ページをひとまとまりとして読み、暗記・想起に向くRecallカード候補を作成してください。',
            '',
            '【対象Task】',
            'Plan: '.$plan->title,
            'Task: '.$task->title,
            '説明: '.($task->description ?: 'なし'),
            '',
            '【教材manifest】',
            $manifest,
            '',
            '【重要ルール】',
            '- 添付教材に書かれていない内容を推測・補完しない',
            '- 複数ページにまたがる同じ論点は必要なら統合してよい',
            '- 1カード1論点にする',
            '- frontは短く、答えを直接含めない',
            '- backは想起確認に必要な最小限の内容にする',
            '- source_excerptには根拠となる教材内の短い抜粋を入れる',
            '- source_indexには最も直接の根拠になった教材の0始まりindexを入れる',
            '- confidenceは教材上の根拠の明確さを0〜100で表す',
            '- 曖昧・文脈不足・カード化に不向きな内容は候補にしない',
            '- 最大50件。量より品質を優先する',
        ]);

        try {
            $result = $this->nativeAi->generateStructured(
                purpose: 'study_recall_candidate_batch_extraction',
                prompt: $prompt,
                schema: $this->schema($sources->count()),
                schemaName: 'study_recall_batch_candidates',
                plan: $plan,
                task: $task,
                maxOutputTokens: 7000,
                userId: $userId,
                capacityTier: 'standard',
                metadata: [
                    'study_recall_source_ids' => $sources
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->all(),
                    'source_count' => $sources->count(),
                    'batch' => true,
                ],
                inputParts: $this->inputParts($sources),
            );
        } catch (NativeAiExecutionException $exception) {
            StudyRecallSource::query()
                ->whereIn('id', $sources->pluck('id'))
                ->update([
                    'status' => 'failed',
                    'native_ai_run_id' => $exception->runId,
                    'updated_at' => now(),
                ]);

            throw $exception;
        }

        $cards = collect(data_get($result, 'data.candidates', []))
            ->filter(fn ($candidate) => is_array($candidate))
            ->take(50);

        $created = 0;
        $createdPerSource = array_fill(0, $sources->count(), 0);

        foreach ($cards as $candidate) {
            $sourceIndex = (int) ($candidate['source_index'] ?? -1);
            $source = $sources->get($sourceIndex);

            if (! $source instanceof StudyRecallSource) {
                continue;
            }

            $promptText = trim((string) ($candidate['prompt'] ?? ''));
            $answer = trim((string) ($candidate['answer'] ?? ''));

            if ($promptText === '' || $answer === '') {
                continue;
            }

            $fingerprint = hash(
                'sha256',
                $this->normalize($promptText)
                .'|'
                .$this->normalize($answer),
            );

            $model = StudyRecallCandidate::query()->firstOrCreate(
                [
                    'task_id' => (int) $task->id,
                    'fingerprint' => $fingerprint,
                ],
                [
                    'study_recall_source_id' => (int) $source->id,
                    'plan_id' => (int) $plan->id,
                    'prompt' => mb_substr($promptText, 0, 1000),
                    'answer' => mb_substr($answer, 0, 8000),
                    'note' => filled($candidate['note'] ?? null)
                        ? mb_substr(
                            trim((string) $candidate['note']),
                            0,
                            4000,
                        )
                        : null,
                    'tags' => collect($candidate['tags'] ?? [])
                        ->filter(
                            fn ($tag) =>
                                is_string($tag)
                                && trim($tag) !== '',
                        )
                        ->map(
                            fn ($tag) =>
                                mb_substr(trim($tag), 0, 80),
                        )
                        ->unique()
                        ->take(5)
                        ->values()
                        ->all(),
                    'source_excerpt' =>
                        filled($candidate['source_excerpt'] ?? null)
                            ? mb_substr(
                                trim(
                                    (string)
                                    $candidate['source_excerpt'],
                                ),
                                0,
                                1000,
                            )
                            : null,
                    'confidence' => max(
                        0,
                        min(
                            100,
                            (int) ($candidate['confidence'] ?? 50),
                        ),
                    ),
                    'status' => 'pending',
                ],
            );

            if ($model->wasRecentlyCreated) {
                $created++;
                $createdPerSource[$sourceIndex]++;
            }
        }

        foreach ($sources as $index => $source) {
            $source->update([
                'status' => 'ready',
                'native_ai_run_id' => (int) $result['run_id'],
                'candidate_count' => $createdPerSource[$index],
            ]);
        }

        return [
            'created' => $created,
            'run_id' => (int) $result['run_id'],
            'total' => $cards->count(),
            'source_count' => $sources->count(),
        ];
    }

    /**
     * @param Collection<int,StudyRecallSource> $sources
     * @return array<int,array<string,mixed>>
     */
    private function inputParts(Collection $sources): array
    {
        $parts = [];

        foreach ($sources as $index => $source) {
            $parts[] = [
                'type' => 'input_text',
                'text' => 'SOURCE_INDEX '.$index
                    .' / '
                    .($source->original_name ?: $source->sourceLabel()),
            ];

            $bytes = Storage::get((string) $source->storage_path);
            $mime = (string) $source->mime_type;
            $base64 = base64_encode($bytes);

            if ($source->source_type === 'image') {
                $parts[] = [
                    'type' => 'input_image',
                    'image_url' => 'data:'.$mime.';base64,'.$base64,
                    'detail' => 'high',
                ];

                continue;
            }

            if ($source->source_type === 'pdf') {
                $parts[] = [
                    'type' => 'input_file',
                    'filename' =>
                        $source->original_name
                        ?: 'study-material-'.$index.'.pdf',
                    'file_data' =>
                        'data:application/pdf;base64,'.$base64,
                    'detail' => 'auto',
                ];

                continue;
            }

            throw new RuntimeException('未対応の教材形式です。');
        }

        return $parts;
    }

    private function schema(int $sourceCount): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['candidates'],
            'properties' => [
                'candidates' => [
                    'type' => 'array',
                    'maxItems' => 50,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'source_index',
                            'prompt',
                            'answer',
                            'note',
                            'tags',
                            'source_excerpt',
                            'confidence',
                        ],
                        'properties' => [
                            'source_index' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'maximum' => $sourceCount - 1,
                            ],
                            'prompt' => ['type' => 'string'],
                            'answer' => ['type' => 'string'],
                            'note' => [
                                'type' => ['string', 'null'],
                            ],
                            'tags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'source_excerpt' => [
                                'type' => ['string', 'null'],
                            ],
                            'confidence' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'maximum' => 100,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(
            trim(
                preg_replace('/\s+/u', ' ', $value)
                ?? $value,
            ),
        );
    }
}
