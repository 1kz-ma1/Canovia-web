<?php

namespace App\Services;

use App\Exceptions\NativeAiExecutionException;
use App\Models\Plan;
use App\Models\StudyRecallCandidate;
use App\Models\StudyRecallSource;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class StudyRecallCandidateExtractionService
{
    public function __construct(
        private readonly NativeAiGateway $nativeAi,
        private readonly StudyRecallExtractionCommitFence $commitFence,
        private readonly StudyRecallCandidateReviewFence $reviewFence,
    ) {}

    /**
     * @return array{created:int,run_id:int,total:int}
     */
    public function extract(
        StudyRecallSource $source,
        Plan $plan,
        Task $task,
        ?int $userId = null,
    ): array {
        $authorization = $this->commitFence->captureAuthorization($plan, $userId);
        $sourceSignature = $this->commitFence->snapshot($source);
        $sourceContext = $source->source_type === 'text'
            ? mb_substr((string) $source->source_text, 0, 50000)
            : '教材ファイルは添付入力として渡しています。';

        $prompt = implode("\n", [
            'あなたはCanoviaのRecallカード候補抽出器です。',
            '与えられた教材だけを根拠に、暗記・想起に向くRecallカード候補を作成してください。',
            '',
            '【対象Task】',
            'Plan: '.$plan->title,
            'Task: '.$task->title,
            '説明: '.($task->description ?: 'なし'),
            '',
            '【重要ルール】',
            '- 教材に書かれていない内容を推測・補完しない',
            '- 1カード1論点にする',
            '- frontは短く、答えを直接含めない',
            '- backは想起確認に必要な最小限の内容にする',
            '- source_excerptには根拠となる教材内の短い抜粋を入れる',
            '- confidenceは教材上の根拠の明確さを0〜100で表す',
            '- 曖昧・文脈不足・カード化に不向きな内容は候補にしない',
            '- 最大30件。量より品質を優先する',
            '',
            '【テキスト教材】',
            $sourceContext,
        ]);

        try {
            $inputFileHashes = [];
            $inputParts = $this->inputParts($source, $inputFileHashes);
            $this->commitFence->assertInputMatches(
                $plan, $task, $userId,
                [(int) $source->id => $sourceSignature],
                $authorization, $inputFileHashes,
            );
            $result = $this->nativeAi->generateStructured(
                purpose: 'study_recall_candidate_extraction',
                prompt: $prompt,
                schema: $this->schema(),
                schemaName: 'study_recall_candidates',
                plan: $plan,
                task: $task,
                maxOutputTokens: 5000,
                userId: $userId,
                capacityTier: 'standard',
                metadata: [
                    'study_recall_source_id' => (int) $source->id,
                    'source_type' => $source->source_type,
                    'mime_type' => $source->mime_type,
                ],
                inputParts: $inputParts,
            );
        } catch (NativeAiExecutionException $exception) {
            $this->markFailedSource($source, $plan, $task, $userId, $exception);
            throw $exception;
        }

        $cards = collect(data_get($result, 'data.candidates', []))
            ->filter(fn ($candidate) => is_array($candidate))
            ->take(30);

        try {
            $created = DB::transaction(function () use (
                $plan, $task, $source, $userId, $sourceSignature, $authorization, $result, $cards,
            ): int {
                $this->commitFence->assertCanCommit(
                    $plan,
                    $task,
                    $userId,
                    [(int) $source->id => $sourceSignature],
                    $authorization,
                    (int) $result['run_id'],
                );

                $created = 0;
                $newCandidateIds = [];
                foreach ($cards as $candidate) {
                    $promptText = trim((string) ($candidate['prompt'] ?? ''));
                    $answer = trim((string) ($candidate['answer'] ?? ''));

                    if ($promptText === '' || $answer === '') {
                        continue;
                    }

                    $fingerprint = hash('sha256', $this->normalize($promptText).'|'.$this->normalize($answer));

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
                                ? mb_substr(trim((string) $candidate['note']), 0, 4000)
                                : null,
                            'tags' => collect($candidate['tags'] ?? [])
                                ->filter(fn ($tag) => is_string($tag) && trim($tag) !== '')
                                ->map(fn ($tag) => mb_substr(trim($tag), 0, 80))
                                ->unique()
                                ->take(5)
                                ->values()
                                ->all(),
                            'source_excerpt' => filled($candidate['source_excerpt'] ?? null)
                                ? mb_substr(trim((string) $candidate['source_excerpt']), 0, 1000)
                                : null,
                            'confidence' => max(0, min(100, (int) ($candidate['confidence'] ?? 50))),
                            'status' => 'pending',
                        ],
                    );

                    if ($model->wasRecentlyCreated) {
                        $newCandidateIds[] = (int) $model->id;
                        $created++;
                    }
                }

                $source->update([
                    'status' => 'ready',
                    'native_ai_run_id' => (int) $result['run_id'],
                    'candidate_count' => $created,
                ]);

                // Only stamp genuinely new candidates. A task/fingerprint
                // collision must never retrospectively sign an old row.
                if ($newCandidateIds !== []) {
                    StudyRecallCandidate::query()->whereIn('id', $newCandidateIds)
                        ->update(['review_provenance' => json_encode(
                            $this->reviewFence->provenance($source, $authorization),
                            JSON_THROW_ON_ERROR,
                        )]);
                }

                return $created;
            }, 3);
        } catch (NativeAiExecutionException $exception) {
            $this->markFailedSource($source, $plan, $task, $userId, $exception);

            throw $exception;
        }

        return [
            'created' => $created,
            'run_id' => (int) $result['run_id'],
            'total' => $cards->count(),
        ];
    }

    private function markFailedSource(
        StudyRecallSource $source,
        Plan $plan,
        Task $task,
        ?int $userId,
        NativeAiExecutionException $exception,
    ): void {
        if ($userId === null || $exception->errorCode === 'native_ai_target_changed') {
            // Do not mutate a Source when its Plan/Task authorization moved.
            return;
        }

        // Retain a retryable source only while it still belongs to this actor.
        // Never overwrite a moved/deleted Source or a concurrent ready result.
        StudyRecallSource::query()
            ->whereKey($source->id)
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id)
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'failed'])
            ->update([
                'status' => 'failed',
                'native_ai_run_id' => $exception->runId,
            ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function inputParts(StudyRecallSource $source, array &$inputFileHashes): array
    {
        if ($source->source_type === 'text') {
            return [];
        }

        try {
            if (! $source->storage_path || ! Storage::exists($source->storage_path)) {
                throw new RuntimeException('Recall source missing');
            }
            $bytes = Storage::get($source->storage_path);
        } catch (Throwable) {
            throw new NativeAiExecutionException(
                '元教材ファイルが変更または削除されました。再抽出してください。',
                'native_ai_source_changed', 0,
            );
        }

        $inputFileHashes[(int) $source->id] = hash('sha256', $bytes);
        $mime = (string) $source->mime_type;
        $base64 = base64_encode($bytes);

        if ($source->source_type === 'image') {
            return [[
                'type' => 'input_image',
                'image_url' => 'data:'.$mime.';base64,'.$base64,
                'detail' => 'high',
            ]];
        }

        if ($source->source_type === 'pdf') {
            return [[
                'type' => 'input_file',
                'filename' => $source->original_name ?: 'study-material.pdf',
                'file_data' => 'data:application/pdf;base64,'.$base64,
                'detail' => 'auto',
            ]];
        }

        throw new RuntimeException('未対応の教材形式です。');
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['candidates'],
            'properties' => [
                'candidates' => [
                    'type' => 'array',
                    'maxItems' => 30,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['prompt', 'answer', 'note', 'tags', 'source_excerpt', 'confidence'],
                        'properties' => [
                            'prompt' => ['type' => 'string'],
                            'answer' => ['type' => 'string'],
                            'note' => ['type' => ['string', 'null']],
                            'tags' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'source_excerpt' => ['type' => ['string', 'null']],
                            'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
