<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Models\CompanionMessage;
use App\Models\CompanionMutationCandidate;
use App\Models\CompanionThread;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CompanionConversationService
{
    public function __construct(
        private readonly NativeAiGateway $nativeAi,
        private readonly CompanionContextService $context,
        private readonly AiCapacityService $capacity,
    ) {}

    /**
     * @return array{message:CompanionMessage,candidates:\Illuminate\Support\Collection<int,CompanionMutationCandidate>}
     */
    public function send(
        CompanionThread $thread,
        User $user,
        string $content,
        string $requestId,
        ?Plan $plan = null,
        ?Task $task = null,
        ?string $sourcePath = null,
    ): array {
        $existingUserMessage = CompanionMessage::query()
            ->where('request_id', $requestId)
            ->where('user_id', $user->id)
            ->first();

        if ($existingUserMessage) {
            $existingAssistant = CompanionMessage::query()
                ->where('companion_thread_id', $thread->id)
                ->where('role', 'assistant')
                ->where('metadata->reply_to_message_id', $existingUserMessage->id)
                ->first();

            if ($existingAssistant) {
                return [
                    'message' => $existingAssistant,
                    'candidates' => $existingAssistant->mutationCandidates()->get(),
                ];
            }

            $userMessage = $existingUserMessage;
        } else {
            $userMessage = $thread->messages()->create([
                'user_id' => $user->id,
                'request_id' => $requestId,
                'role' => 'user',
                'content' => trim($content),
                'metadata' => [
                    'source_path' => $sourcePath,
                ],
            ]);
        }

        $contextSnapshot = $this->context->snapshot($user, $plan, $task);
        $history = $thread->messages()
            ->where('id', '<=', $userMessage->id)
            ->latest('id')
            ->limit(12)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (CompanionMessage $message) => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->all();

        $prompt = $this->buildPrompt($contextSnapshot, $history);
        $schema = $this->schema();

        $result = $this->nativeAi->generateStructured(
            purpose: 'canovia_companion_reply',
            prompt: $prompt,
            schema: $schema,
            schemaName: 'canovia_companion_reply',
            plan: $plan,
            task: $task,
            maxOutputTokens: 2200,
            userId: $user->id,
            capacityTier: $this->capacity->tierFor($user),
            metadata: [
                'companion_thread_id' => (int) $thread->id,
                'reply_to_message_id' => (int) $userMessage->id,
                'scope' => $contextSnapshot['scope'] ?? 'global',
            ],
            featureKey: FeatureKey::CanoviaCompanion,
        );

        return DB::transaction(function () use ($thread, $user, $userMessage, $result, $plan, $task, $contextSnapshot) {
            $existingAssistant = CompanionMessage::query()
                ->where('companion_thread_id', $thread->id)
                ->where('role', 'assistant')
                ->where('metadata->reply_to_message_id', $userMessage->id)
                ->lockForUpdate()
                ->first();

            if ($existingAssistant) {
                return [
                    'message' => $existingAssistant,
                    'candidates' => $existingAssistant->mutationCandidates()->get(),
                ];
            }

            $data = $result['data'];
            $assistant = $thread->messages()->create([
                'native_ai_run_id' => $result['run_id'],
                'role' => 'assistant',
                'content' => trim((string) ($data['reply'] ?? '')),
                'metadata' => [
                    'reply_to_message_id' => (int) $userMessage->id,
                    'context_scope' => $contextSnapshot['scope'] ?? 'global',
                    'candidate_count' => count($data['candidates'] ?? []),
                ],
            ]);

            $candidates = collect($data['candidates'] ?? [])
                ->take(3)
                ->map(fn ($candidate) => $this->persistCandidate(
                    $thread,
                    $assistant,
                    $user,
                    is_array($candidate) ? $candidate : [],
                    $plan,
                    $task,
                ))
                ->filter()
                ->values();

            if (! filled($thread->title)) {
                $thread->title = Str::limit($userMessage->content, 60, '');
            }
            $thread->last_message_at = now();
            $thread->save();

            return [
                'message' => $assistant,
                'candidates' => $candidates,
            ];
        });
    }

    private function persistCandidate(
        CompanionThread $thread,
        CompanionMessage $assistant,
        User $user,
        array $candidate,
        ?Plan $plan,
        ?Task $task,
    ): ?CompanionMutationCandidate {
        $type = (string) ($candidate['type'] ?? '');
        if (! in_array($type, CompanionMutationCandidate::TYPES, true)) {
            return null;
        }

        $requiresPlan = in_array($type, ['create_task', 'update_plan', 'record_goal_fact'], true);
        $requiresTask = $type === 'update_task';

        if (($requiresPlan && ! $plan) || ($requiresTask && ! $task)) {
            return null;
        }

        $payloadRaw = trim((string) ($candidate['payload_json'] ?? ''));
        $payload = $payloadRaw !== '' ? json_decode($payloadRaw, true) : [];
        if (! is_array($payload)) {
            return null;
        }

        // IDs are never accepted from AI. Targets come from the selected Canovia context.
        unset(
            $payload['id'],
            $payload['user_id'],
            $payload['plan_id'],
            $payload['task_id'],
            $payload['owner_token'],
        );

        return CompanionMutationCandidate::query()->create([
            'companion_thread_id' => $thread->id,
            'companion_message_id' => $assistant->id,
            'user_id' => $user->id,
            'plan_id' => $plan?->id,
            'task_id' => $task?->id,
            'candidate_key' => (string) Str::uuid(),
            'type' => $type,
            'status' => CompanionMutationCandidate::STATUS_PENDING,
            'title' => Str::limit(trim((string) ($candidate['title'] ?? '変更候補')), 255, ''),
            'summary' => Str::limit(trim((string) ($candidate['summary'] ?? '')), 2000, ''),
            'payload' => $payload,
            'metadata' => [
                'source' => 'canovia_companion',
                'native_ai_run_id' => $assistant->native_ai_run_id,
                'target_source' => 'selected_context',
            ],
        ]);
    }

    private function buildPrompt(array $context, array $history): string
    {
        $historyText = collect($history)
            ->map(fn (array $message) => strtoupper((string) $message['role']).': '.trim((string) $message['content']))
            ->implode("\n");

        $contextText = $this->context->prompt($context);

        return <<<PROMPT
あなたはCanovia Companionです。
汎用雑談AIではなく、ユーザーが目標へ進むためにCanoviaが既に持つ文脈を使って伴走してください。

【重要な境界】
- CONTEXTにない事実を作らない
- 不明なことは不明と扱う
- 時間を使っただけでProgressと判断しない
- ユーザーの意図を尊重し、勝手にPlan/Taskを変更したと断言しない
- DB変更は一切できない。必要ならMutation Candidateを提案するだけ
- CandidateへPlan ID / Task ID / User IDを書かない。対象はCanovia側が選択中Contextから決める
- 単なる相談ならCandidateは0件でよい
- 一度に候補を増やしすぎず、最大3件
- 返答は日本語で簡潔に、次に動きやすい内容にする

【許可されたCandidate type】
create_task: 選択中Planへ新しいTask候補
update_task: 選択中Taskの変更候補
update_plan: 選択中Planの変更候補
record_goal_fact: 選択中PlanのGoal Contextへ新しい確認済みFact候補
create_future_memo: 今すぐ実行しない将来メモ候補
create_inbox_item: 整理前の情報としてInboxへ残す候補

payload_jsonはJSONオブジェクトを文字列化して返す。
Candidateはまだ未反映であり、返答内でも「変更した」と表現しない。

【CANOVIA CONTEXT】
{$contextText}

【RECENT CONVERSATION】
{$historyText}
PROMPT;
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'reply' => ['type' => 'string'],
                'candidates' => [
                    'type' => 'array',
                    'maxItems' => 3,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'enum' => CompanionMutationCandidate::TYPES,
                            ],
                            'title' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                            'payload_json' => ['type' => 'string'],
                        ],
                        'required' => ['type', 'title', 'summary', 'payload_json'],
                    ],
                ],
            ],
            'required' => ['reply', 'candidates'],
        ];
    }
}
