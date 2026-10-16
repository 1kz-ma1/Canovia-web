<?php

namespace App\Services;

use App\Contracts\StudyPracticeQuestionProvider;
use App\Exceptions\NativeAiExecutionException;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

class NativeAiStudyPracticeQuestionProvider implements StudyPracticeQuestionProvider
{
    public function __construct(
        private readonly StudyPracticePromptService $promptService,
        private readonly NativeAiGateway $gateway,
        private readonly AiCapacityService $capacity,
    ) {}

    public function key(): string
    {
        return 'native_ai';
    }

    public function mode(): string
    {
        return 'direct';
    }

    public function prepare(
        Plan $plan,
        Task $task,
        Collection $recentAttempts,
        array $strategy,
        ?int $actorUserId = null,
    ): array {
        $prompt = $this->promptService->generationPrompt($plan, $task, $recentAttempts, $strategy);
        $user = $actorUserId ? User::query()->find($actorUserId) : null;
        $capacityTier = $this->capacity->tierFor($user);
        $maxOutputTokens = (int) config(
            "native_ai.study_practice.capacity.{$capacityTier}.generation_max_output_tokens",
            8000,
        );

        $result = $this->gateway->generateStructured(
            purpose: 'study_practice_generation',
            prompt: $prompt,
            schema: StudyPracticeNativeAiSchema::questions(),
            schemaName: 'canovia_study_practice',
            plan: $plan,
            task: $task,
            maxOutputTokens: $maxOutputTokens,
            userId: $actorUserId,
            capacityTier: $capacityTier,
            metadata: ['strategy_key' => $strategy['key'] ?? null],
        );

        $data = $result['data'];
        if (
            ($data['flow'] ?? null) !== 'study_practice'
            || (int) data_get($data, 'target_plan.id') !== (int) $plan->id
            || (int) data_get($data, 'target_task.id') !== (int) $task->id
            || ! is_array($data['questions'] ?? null)
            || ($data['questions'] ?? []) === []
        ) {
            $this->gateway->markRunFailed(
                (int) $result['run_id'],
                'native_ai_contract_mismatch',
                'Native AIの問題生成結果がCanoviaの対象または契約と一致しません。',
            );

            throw new NativeAiExecutionException(
                'Native AIの問題生成結果をCanoviaへ安全に読み込めませんでした。',
                'native_ai_contract_mismatch',
                (int) $result['run_id'],
            );
        }

        try {
            app(StudyPracticeQuestionIntegrityService::class)->assertValid(
                $data['questions'],
                app(StudyPracticeExamProfileService::class)->forPlanTask($plan, $task),
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $this->gateway->markRunFailed((int) $result['run_id'], 'native_ai_question_integrity', $message);
            throw new NativeAiExecutionException($message, 'native_ai_question_integrity', (int) $result['run_id']);
        }

        $title = trim((string) ($data['title'] ?? 'Canovia Native AI演習'));
        $questions = array_values($data['questions']);

        return [
            'provider' => $this->key(),
            'mode' => $this->mode(),
            'selector_type' => 'native_ai',
            'selector_version' => 'openai-responses-v1',
            'payload' => [
                'title' => $title !== '' ? $title : 'Canovia Native AI演習',
                'generation_prompt' => $prompt,
                'response_envelope' => $data,
                'native_ai' => [
                    'run_id' => (int) $result['run_id'],
                    'provider' => $result['provider'],
                    'model' => $result['model'],
                    'capacity_tier' => $capacityTier,
                ],
            ],
            'questions' => $questions,
            'selected_questions' => collect($questions)->map(fn ($question, $index) => [
                'question_ref' => is_array($question) && filled($question['id'] ?? null)
                    ? (string) $question['id']
                    : 'native_'.($index + 1),
                'question_id' => null,
                'source_type' => 'native_ai',
            ])->values()->all(),
        ];
    }
}
