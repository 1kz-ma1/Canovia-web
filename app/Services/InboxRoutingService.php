<?php

namespace App\Services;

use App\Enums\EvidenceSource;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\PlanResource;
use App\Models\StudyRecallSource;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InboxRoutingService
{
    public function __construct(
        private readonly FutureMemoService $futureMemos,
        private readonly CareerCaptureService $careerCaptures,
        private readonly StudyRecallCandidateExtractionService $recallExtractor,
        private readonly TaskEvidenceService $evidence,
        private readonly PlanActivityService $activity,
        private readonly ExecutionRequestHandoffService $executionRequests,
    ) {}

    /**
     * @param array<string,mixed> $data
     * @return array{message:string,destination:string}
     */
    public function route(
        Request $request,
        InboxItem $item,
        array $data,
        ?Plan $plan,
        ?Task $task,
        string $actorToken,
    ): array {
        $destination = (string) $data['destination'];

        $message = match ($destination) {
                'future_memo' => $this->toFutureMemo($request, $item, $data),
                'career_capture' => $this->toCareerCapture($request, $item, $plan, $actorToken),
                'recall_material' => $this->toRecall($request, $item, $plan, $task, $actorToken),
                'task_evidence' => $this->toEvidence($request, $item, $plan, $task, $actorToken),
                'plan_resource' => $this->toResource($request, $item, $plan),
                'execution_request' => $this->toExecutionRequest($request, $item, $data, $plan, $task),
                'keep_inbox' => 'Inboxに残しました。',
                default => throw ValidationException::withMessages(['destination' => '未対応の整理先です。']),
            };

            if ($destination !== 'keep_inbox') {
                $metadata = is_array($item->metadata) ? $item->metadata : [];
                $metadata['routing_confirmed'] = [
                    'destination' => $destination,
                    'plan_id' => $plan?->id,
                    'task_id' => $task?->id,
                    'confirmed_at' => now()->toIso8601String(),
                ];

                $item->update([
                    'plan_id' => $plan?->id ?? $item->plan_id,
                    'status' => 'processed',
                    'processed_at' => now(),
                    'metadata' => $metadata,
                ]);
            } else {
                $item->update(['status' => 'new']);
            }

        return ['message' => $message, 'destination' => $destination];
    }

    private function toFutureMemo(Request $request, InboxItem $item, array $data): string
    {
        $content = trim((string) ($item->content ?: $item->title ?: $item->source_url));
        if ($content === '') {
            throw ValidationException::withMessages(['destination' => 'Future Memoへ移す本文がありません。']);
        }

        $this->futureMemos->create($request, [
            'kind' => $data['future_memo_kind'] ?? 'interest',
            'category' => $data['future_memo_category'] ?? 'other',
            'content' => $content,
            'use_for_ai' => true,
        ]);

        return 'Future Memoへ追加しました。';
    }

    private function toCareerCapture(
        Request $request,
        InboxItem $item,
        ?Plan $plan,
        string $actorToken,
    ): string {
        $this->requirePlan($plan);
        if (! in_array((string) $plan->category, ['就活・キャリア', '就活', '就職活動', '就職・将来', '転職', 'キャリア'], true)) {
            throw ValidationException::withMessages(['plan_id' => 'Career Captureには就活・キャリア系Planを選んでください。']);
        }

        $sourceType = match ($item->source_type) {
            'image' => 'screenshot',
            'url' => 'url',
            default => 'manual',
        };

        $screenshotData = null;
        if ($item->source_type === 'image') {
            if (($item->byte_size ?? 0) > 3 * 1024 * 1024) {
                throw ValidationException::withMessages([
                    'destination' => 'Career Captureへ送るスクリーンショットは3MB以下にしてください。',
                ]);
            }
            if (! $item->storage_path || ! Storage::exists($item->storage_path)) {
                throw ValidationException::withMessages(['destination' => 'Inbox画像を読み込めませんでした。']);
            }
            $screenshotData = base64_encode(Storage::get($item->storage_path));
        }

        $this->careerCaptures->record(
            $plan,
            sourceType: $sourceType,
            sourceUrl: $item->source_url,
            screenshotMime: $item->source_type === 'image' ? $item->mime_type : null,
            screenshotOriginalName: $item->source_type === 'image' ? $item->original_name : null,
            screenshotData: $screenshotData,
            screenshotByteSize: $item->source_type === 'image' ? $item->byte_size : null,
            rawText: trim((string) $item->content) ?: null,
            userId: $request->user()?->id,
            actorToken: $actorToken,
        );

        return 'Career Captureへ追加しました。';
    }

    private function toRecall(
        Request $request,
        InboxItem $item,
        ?Plan $plan,
        ?Task $task,
        string $actorToken,
    ): string {
        $this->requireTask($plan, $task);
        if ((string) $plan->category !== '資格学習') {
            throw ValidationException::withMessages(['plan_id' => 'Recall教材には資格学習Planを選んでください。']);
        }

        if ($item->source_type === 'url') {
            throw ValidationException::withMessages([
                'destination' => 'URLだけではRecall教材として抽出しません。教材本文・画像・PDFをInboxへ追加してください。',
            ]);
        }

        $recallPath = null;
        if (in_array($item->source_type, ['image', 'pdf'], true)) {
            if (! $item->storage_path || ! Storage::exists($item->storage_path)) {
                throw ValidationException::withMessages(['destination' => 'Inbox教材ファイルを読み込めませんでした。']);
            }
            $extension = pathinfo((string) $item->storage_path, PATHINFO_EXTENSION);
            $recallPath = 'study-recall-sources/'.$plan->id.'/'.$task->id.'/'.Str::uuid().($extension ? '.'.$extension : '');
            Storage::copy($item->storage_path, $recallPath);
        }

        $source = StudyRecallSource::query()->create([
            'plan_id' => (int) $plan->id,
            'task_id' => (int) $task->id,
            'user_id' => $request->user()?->id,
            'actor_token' => $request->user() ? null : $actorToken,
            'source_type' => in_array($item->source_type, ['image', 'pdf'], true) ? $item->source_type : 'text',
            'original_name' => $item->original_name,
            'mime_type' => $item->mime_type,
            'storage_path' => $recallPath,
            'source_text' => in_array($item->source_type, ['image', 'pdf'], true)
                ? null
                : trim((string) $item->content),
            'status' => 'pending',
        ]);

        $result = $this->recallExtractor->extract($source, $plan, $task, $request->user()?->id);

        return $result['created'].'件のRecall Candidateを作成しました。Deck追加前に確認してください。';
    }

    private function toEvidence(
        Request $request,
        InboxItem $item,
        ?Plan $plan,
        ?Task $task,
        string $actorToken,
    ): string {
        $this->requireTask($plan, $task);

        $source = match ($item->source_type) {
            'image' => EvidenceSource::Image,
            'pdf' => EvidenceSource::File,
            default => EvidenceSource::External,
        };

        $this->evidence->record(
            $task,
            $source,
            'inbox_observation_confirmed',
            [
                'inbox_item_id' => (int) $item->id,
                'title' => $item->title,
                'content' => $item->content,
                'url' => $item->source_url,
                'original_name' => $item->original_name,
            ],
            confidence: 0.5,
            externalKey: 'inbox-item:'.$item->id,
            userId: $request->user()?->id,
            actorToken: $actorToken,
        );

        return 'Task Evidenceへ記録しました。進捗は自動加算しません。';
    }

    private function toExecutionRequest(
        Request $request,
        InboxItem $item,
        array $data,
        ?Plan $plan,
        ?Task $task,
    ): string {
        $this->requireTask($plan, $task);

        $instruction = trim((string) ($data['execution_instruction'] ?? ''));
        $actorType = trim((string) ($data['execution_actor_type'] ?? 'human_ai'));
        $availableMinutes = isset($data['execution_available_minutes'])
            ? (int) $data['execution_available_minutes']
            : null;

        $executionRequest = $this->executionRequests->confirmFromInbox(
            $request,
            $item,
            $plan,
            $task,
            $instruction,
            $actorType,
            $availableMinutes,
        );

        $metadata = is_array($item->metadata) ? $item->metadata : [];
        $metadata['execution_request'] = $executionRequest;
        $item->update(['metadata' => $metadata]);

        return '実行リクエストを確認しました。全体ContextとDependencyを確認して、担当へ渡す指示を組み立てます。';
    }

    private function toResource(Request $request, InboxItem $item, ?Plan $plan): string
    {
        $this->requirePlan($plan);
        $url = trim((string) $item->source_url);
        if ($url === '') {
            throw ValidationException::withMessages(['destination' => 'Plan Resourceへ追加するにはURLが必要です。']);
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $provider = match (true) {
            str_contains($host, 'github.com') => 'github',
            str_contains($host, 'drive.google.com'), str_contains($host, 'docs.google.com') => 'google_drive',
            str_contains($host, 'onedrive'), str_contains($host, 'sharepoint') => 'onedrive',
            default => null,
        };

        if ($provider === null) {
            throw ValidationException::withMessages([
                'destination' => '現行ResourceはGoogle Drive / OneDrive / GitHub URLに対応しています。このURLはInboxに残してください。',
            ]);
        }

        $resource = $plan->resources()->create([
            'created_by_user_id' => $request->user()?->id,
            'provider' => $provider,
            'resource_type' => 'file',
            'title' => $item->title ?: $host,
            'url' => $url,
        ]);

        $this->activity->record($plan, $request->user(), 'resource_created', 'plan_resource', (int) $resource->id, [
            'resource_title' => $resource->title,
            'source' => 'inbox',
        ]);

        return 'Plan Resourceへ追加しました。';
    }

    private function requirePlan(?Plan $plan): void
    {
        if (! $plan) {
            throw ValidationException::withMessages(['plan_id' => 'この整理先にはPlanを選んでください。']);
        }
    }

    private function requireTask(?Plan $plan, ?Task $task): void
    {
        $this->requirePlan($plan);

        if (! $task || (int) $task->plan_id !== (int) $plan->id) {
            throw ValidationException::withMessages(['task_id' => '選択したPlanのTaskを選んでください。']);
        }
    }
}
