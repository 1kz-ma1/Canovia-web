<?php

namespace App\Services;

use App\Models\InboxItem;
use Illuminate\Support\Facades\Storage;

class InboxIntelligenceService
{
    public const DESTINATIONS = [
        'future_memo' => 'Future Memo',
        'career_capture' => 'Career Capture',
        'recall_material' => 'Recall教材',
        'task_evidence' => 'Task Evidence',
        'plan_resource' => 'Plan Resource',
        'execution_request' => '実行リクエスト',
        'keep_inbox' => 'Inboxに残す',
    ];

    public const PUBLIC_DESTINATIONS = [
        'career_capture' => 'Career Capture',
        'recall_material' => 'Recall教材',
        'task_evidence' => 'Task Evidence',
        'plan_resource' => 'Plan Resource',
        'execution_request' => '実行リクエスト',
        'keep_inbox' => 'Inboxに残す',
    ];

    public function __construct(
        private readonly NativeAiGateway $nativeAi,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function suggest(InboxItem $item, ?int $userId = null): array
    {
        $prompt = implode("\n", [
            'あなたはCanovia Inboxの振り分け候補を作るアシスタントです。',
            '入力内容の意味を読み取り、最も自然な行き先を1つだけ提案してください。',
            '',
            '重要:',
            '- IDは絶対に返さない。Plan/Taskを決める権限はCanoviaと人にある',
            '- 入力にない事実を補完しない',
            '- 自信が低い場合はkeep_inboxを選ぶ',
            '- destinationは指定されたenumだけを使う',
            '- reasonは短く具体的にする',
            '',
            'destinationの意味:',
            '- career_capture: 求人、企業応募、選考に関する外部情報',
            '- recall_material: 単語、用語、参考書ページなど暗記・想起用教材',
            '- task_evidence: 既存Taskで実際に行った作業・成果・確認できる事実',
            '- plan_resource: 既存Planで参照するDrive / OneDrive / GitHub等のURL',
            '- execution_request: 既存Plan / Taskについて、これから何かを進めたい・実行したい・担当やAIへ渡したいという依頼',
            '- keep_inbox: 判断できない、複数解釈、まだ整理不要',
            '- 過去に行った結果はtask_evidence、これから行う依頼はexecution_requestとして区別する',
            '',
            'Inbox Item:',
            'source_type: '.$item->source_type,
            'title: '.($item->title ?: 'なし'),
            'content: '.mb_substr((string) $item->content, 0, 12000),
            'url: '.($item->source_url ?: 'なし'),
            'file: '.($item->original_name ?: 'なし'),
        ]);

        $result = $this->nativeAi->generateStructured(
            purpose: 'inbox_routing_suggestion',
            prompt: $prompt,
            schema: $this->schema(),
            schemaName: 'inbox_routing_suggestion',
            plan: null,
            task: null,
            maxOutputTokens: 1000,
            userId: $userId,
            capacityTier: 'standard',
            metadata: [
                'inbox_item_id' => (int) $item->id,
                'source_type' => $item->source_type,
            ],
            inputParts: $this->inputParts($item),
        );

        $data = $result['data'];
        $destination = (string) ($data['destination'] ?? 'keep_inbox');
        if (! array_key_exists($destination, self::PUBLIC_DESTINATIONS)) {
            $destination = 'keep_inbox';
        }

        return [
            'destination' => $destination,
            'reason' => mb_substr(trim((string) ($data['reason'] ?? '')), 0, 1000),
            'confidence' => max(0, min(100, (int) ($data['confidence'] ?? 50))),
            'suggested_plan_title' => mb_substr(trim((string) ($data['suggested_plan_title'] ?? '')), 0, 255) ?: null,
            'suggested_task_title' => mb_substr(trim((string) ($data['suggested_task_title'] ?? '')), 0, 255) ?: null,
            'run_id' => (int) $result['run_id'],
            'provider' => $result['provider'],
            'model' => $result['model'],
        ];
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'destination',
                'reason',
                'confidence',
                'suggested_plan_title',
                'suggested_task_title',
            ],
            'properties' => [
                'destination' => [
                    'type' => 'string',
                    'enum' => array_keys(self::PUBLIC_DESTINATIONS),
                ],
                'reason' => ['type' => 'string'],
                'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'suggested_plan_title' => ['type' => ['string', 'null']],
                'suggested_task_title' => ['type' => ['string', 'null']],
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function inputParts(InboxItem $item): array
    {
        if (! $item->storage_path || ! Storage::exists($item->storage_path)) {
            return [];
        }

        $bytes = Storage::get($item->storage_path);
        $base64 = base64_encode($bytes);

        if ($item->source_type === 'image') {
            return [[
                'type' => 'input_image',
                'image_url' => 'data:'.($item->mime_type ?: 'image/jpeg').';base64,'.$base64,
                'detail' => 'high',
            ]];
        }

        if ($item->source_type === 'pdf') {
            return [[
                'type' => 'input_file',
                'filename' => $item->original_name ?: 'inbox.pdf',
                'file_data' => 'data:application/pdf;base64,'.$base64,
                'detail' => 'auto',
            ]];
        }

        return [];
    }
}
