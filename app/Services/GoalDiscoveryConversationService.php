<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\FutureMemo;
use App\Models\GoalContext;
use App\Models\GoalDiscoveryMessage;
use Illuminate\Http\Request;

class GoalDiscoveryConversationService
{
    public function __construct(
        private readonly GoalContextService $goalContexts,
        private readonly GoalDiscoveryPolicyService $policy,
        private readonly NativeAiGateway $nativeAi,
        private readonly FeatureAccessService $access,
        private readonly FeatureFlagService $flags,
        private readonly AiCapacityService $capacity,
        private readonly FutureMemoService $memories,
    ) {}

    public function begin(Request $request, GoalContext $context, string $userText): void
    {
        if (! $context->discoveryMessages()->exists()) {
            $this->storeUserMessage($request, $context, $userText, ['kind' => 'goal']);
            $this->respond($request, $context, $userText);
        }
    }

    public function afterAnswer(Request $request, GoalContext $context, string $userText, string $questionId): void
    {
        $this->storeUserMessage($request, $context, $userText, [
            'kind' => 'answer',
            'question_id' => $questionId,
        ]);

        $this->respond($request, $context, $userText);
    }

    public function messages(GoalContext $context)
    {
        return $context->discoveryMessages()->get();
    }

    private function respond(Request $request, GoalContext $context, string $latestUserText): void
    {
        $context = $this->goalContexts->recalculate($context->fresh(['facts']));
        $question = $this->policy->nextQuestion($context);

        if (! $this->canUseNative($request)) {
            $this->storeAssistantMessage(
                $request,
                $context,
                $this->fallbackReply($question),
                null,
                ['mode' => 'deterministic_fallback'],
            );

            return;
        }

        try {
            $result = $this->nativeAi->generateStructured(
                purpose: 'conversational_onboarding_reply',
                prompt: $this->prompt($context, $question, $latestUserText),
                schema: $this->schema(),
                schemaName: 'conversational_onboarding_reply',
                plan: null,
                task: null,
                maxOutputTokens: 700,
                userId: $request->user()?->id,
                capacityTier: $this->capacity->tierFor($request->user()),
                metadata: [
                    'goal_context_id' => (int) $context->id,
                    'next_question_id' => $question['id'] ?? null,
                    'free_first_experience' => true,
                ],
                featureKey: FeatureKey::ConversationalOnboarding,
            );

            $data = $result['data'];
            $reply = trim((string) ($data['reply'] ?? ''));
            if ($reply === '') {
                $reply = $this->fallbackReply($question);
            }

            $this->captureMemories(
                $request,
                $context,
                $latestUserText,
                is_array($data['memories'] ?? null) ? $data['memories'] : [],
            );

            $this->storeAssistantMessage(
                $request,
                $context,
                $reply,
                (int) $result['run_id'],
                [
                    'mode' => 'native_ai',
                    'next_question_id' => $question['id'] ?? null,
                ],
            );
        } catch (NativeAiExecutionException) {
            $this->storeAssistantMessage(
                $request,
                $context,
                $this->fallbackReply($question),
                null,
                ['mode' => 'provider_fallback'],
            );
        }
    }

    private function canUseNative(Request $request): bool
    {
        return $this->flags->isEnabled(FeatureKey::ConversationalOnboarding)
            && $this->access->canUse($request->user(), FeatureKey::ConversationalOnboarding)
            && $this->nativeAi->isConfigured();
    }

    private function storeUserMessage(Request $request, GoalContext $context, string $content, array $metadata): GoalDiscoveryMessage
    {
        return $context->discoveryMessages()->create([
            'user_id' => $request->user()?->id,
            'role' => 'user',
            'content' => trim($content),
            'metadata' => $metadata,
        ]);
    }

    private function storeAssistantMessage(
        Request $request,
        GoalContext $context,
        string $content,
        ?int $nativeAiRunId,
        array $metadata,
    ): GoalDiscoveryMessage {
        return $context->discoveryMessages()->create([
            'user_id' => null,
            'native_ai_run_id' => $nativeAiRunId,
            'role' => 'assistant',
            'content' => trim($content),
            'metadata' => $metadata,
        ]);
    }

    private function captureMemories(
        Request $request,
        GoalContext $context,
        string $latestUserText,
        array $candidates,
    ): void {
        foreach (array_slice($candidates, 0, 2) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $kind = trim((string) ($candidate['kind'] ?? ''));
            $category = trim((string) ($candidate['category'] ?? ''));
            $quote = trim((string) ($candidate['source_quote'] ?? ''));

            if (
                $quote === ''
                || mb_strlen($quote) < 4
                || mb_strpos($latestUserText, $quote) === false
                || ! array_key_exists($kind, FutureMemo::KINDS)
            ) {
                continue;
            }

            $this->memories->captureExplicitMemory(
                $request,
                $kind,
                array_key_exists($category, FutureMemo::CATEGORIES) ? $category : 'other',
                $quote,
                'goal_discovery',
                (int) $context->id,
            );
        }
    }

    private function fallbackReply(?array $question): string
    {
        if (! $question) {
            return 'ここまで話してくれた内容なら、最初のPlanを作り始められます。分からない部分は、あとで実行しながら一緒に更新できます。';
        }

        return 'ありがとう。今の話を前提に進めます。'.$question['title'];
    }

    private function prompt(GoalContext $context, ?array $question, string $latestUserText): string
    {
        $snapshot = $this->goalContexts->snapshot($context);
        $facts = collect($snapshot['confirmed_facts'] ?? [])
            ->take(10)
            ->map(fn ($fact) => [
                'type' => $fact->type,
                'key' => $fact->key,
                'label' => $fact->label,
                'value' => $fact->value_json,
            ])
            ->values()
            ->all();
        $unknowns = collect($snapshot['known_unknowns'] ?? [])
            ->take(6)
            ->map(fn ($fact) => [
                'key' => $fact->key,
                'label' => $fact->label,
            ])
            ->values()
            ->all();
        $history = $context->discoveryMessages()
            ->latest('id')
            ->limit(8)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (GoalDiscoveryMessage $message) => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->all();

        $contextJson = json_encode([
            'desired_state' => $context->desired_state,
            'current_state_summary' => $context->current_state_summary,
            'readiness_score' => (int) $context->readiness_score,
            'confirmed_facts' => $facts,
            'known_unknowns' => $unknowns,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $historyJson = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $questionJson = json_encode($question, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return <<<PROMPT
あなたはCanoviaの初回伴走AIです。
これは課金前でも体験できる、Canoviaの最初の伴走Conversationです。

【役割】
- ユーザーが話した目標と現在地を受け止め、次に答えやすい状態を作る
- 返答は日本語で短く、自然な会話として1〜3文
- 大げさに褒めず、ユーザーが話した事実を具体的に拾う
- CONTEXTにない事実は作らない
- 不明なことは不明のまま扱う
- PlanやTaskを作成・変更したと断言しない
- NEXT QUESTIONがある場合、Canovia側の質問設計を尊重し、その質問へ自然につなぐ
- NEXT QUESTIONがnullなら、Planを始められる状態になったことを簡潔に伝える

【Invisible Memory】
現在の主目標そのものを重複保存しない。
最新のUSER INPUTに、主目標とは別の「いつかやりたいこと」「なりたい自分」「関心」「困りごと」「大事にしたい価値」が明示されている場合だけmemoriesへ出す。
推測・診断・属性推定は禁止。
source_quoteはLATEST USER INPUTに実際に含まれる原文の連続部分だけを使う。
保存候補がなければ空配列にする。最大2件。

【GOAL CONTEXT】
{$contextJson}

【RECENT CONVERSATION】
{$historyJson}

【LATEST USER INPUT】
{$latestUserText}

【NEXT QUESTION】
{$questionJson}
PROMPT;
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'reply' => ['type' => 'string'],
                'memories' => [
                    'type' => 'array',
                    'maxItems' => 2,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'kind' => [
                                'type' => 'string',
                                'enum' => array_keys(FutureMemo::KINDS),
                            ],
                            'category' => [
                                'type' => 'string',
                                'enum' => array_keys(FutureMemo::CATEGORIES),
                            ],
                            'source_quote' => ['type' => 'string'],
                        ],
                        'required' => ['kind', 'category', 'source_quote'],
                    ],
                ],
            ],
            'required' => ['reply', 'memories'],
        ];
    }
}
