<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\CareerCapture;
use App\Models\FutureMemo;
use App\Models\InboxItem;
use App\Models\StudyRecallCandidate;
use App\Models\Task;
use App\Models\WorkSession;
use App\Services\BehaviorIdentityService;
use App\Services\ExecutionPacketService;
use App\Services\FeatureAccessService;
use App\Services\InboxIntelligenceService;
use App\Services\InboxRoutingService;
use App\Services\NativeAiGateway;
use App\Services\PlanOwnershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InboxController extends Controller
{
    public function index(
        Request $request,
        BehaviorIdentityService $identity,
        PlanOwnershipService $ownership,
        FeatureAccessService $featureAccess,
        NativeAiGateway $nativeAi,
    ) {
        $actorToken = $identity->resolve($request);
        $userId = $request->user()?->id;

        $editablePlans = $ownership->ownedPlans($request, ['tasks'])
            ->filter(fn ($plan) => $ownership->canEdit($request, $plan))
            ->values();
        $editablePlanIds = $editablePlans->pluck('id')->map(fn ($id) => (int) $id)->all();

        $items = $this->ownInboxItems($userId, $actorToken)
            ->with('plan')
            ->whereIn('status', ['new', 'review'])
            ->latest('id')
            ->take(50)
            ->get();

        $recentItems = $this->ownInboxItems($userId, $actorToken)
            ->with('plan')
            ->whereIn('status', ['processed', 'archived'])
            ->latest('processed_at')
            ->latest('id')
            ->take(12)
            ->get();

        $recallCandidates = collect();
        $careerCaptures = collect();
        $pendingPlanUpdates = collect();

        if ($editablePlanIds !== []) {
            $recallCandidates = StudyRecallCandidate::query()
                ->with(['plan', 'task', 'source'])
                ->whereIn('plan_id', $editablePlanIds)
                ->where('status', 'pending')
                ->whereHas('source', function (Builder $query) use ($userId, $actorToken) {
                    $this->applyIdentityScope($query, $userId, $actorToken);
                })
                ->latest('id')
                ->take(20)
                ->get();

            $careerCaptures = CareerCapture::query()
                ->with(['plan', 'application'])
                ->whereIn('plan_id', $editablePlanIds)
                ->where('status', 'pending')
                ->where(function (Builder $query) use ($userId, $actorToken) {
                    $this->applyIdentityScope($query, $userId, $actorToken);
                })
                ->latest('captured_at')
                ->latest('id')
                ->take(20)
                ->get();

            $pendingPlanUpdates = WorkSession::query()
                ->with(['plan', 'task'])
                ->whereIn('plan_id', $editablePlanIds)
                ->where('actor_token', $actorToken)
                ->where('needs_plan_update', true)
                ->whereIn('status', ['completed', 'interrupted'])
                ->latest('ended_at')
                ->take(20)
                ->get();
        }

        return view('inbox.index', [
            'items' => $items,
            'recentItems' => $recentItems,
            'editablePlans' => $editablePlans,
            'recallCandidates' => $recallCandidates,
            'careerCaptures' => $careerCaptures,
            'pendingPlanUpdates' => $pendingPlanUpdates,
            'pendingCount' => $items->count()
                + $recallCandidates->count()
                + $careerCaptures->count()
                + $pendingPlanUpdates->count(),
            'canUseInboxAi' => $nativeAi->isConfigured()
                && $featureAccess->canUse($request->user(), FeatureKey::AutomaticAiExecution),
            'routingDestinations' => InboxIntelligenceService::PUBLIC_DESTINATIONS,
            'executionActorTypes' => ExecutionPacketService::ACTOR_TYPES,
        ]);
    }

    public function store(
        Request $request,
        BehaviorIdentityService $identity,
        PlanOwnershipService $ownership,
        FeatureAccessService $featureAccess,
        NativeAiGateway $nativeAi,
        InboxIntelligenceService $intelligence,
    ) {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'plan_id' => ['nullable', 'integer'],
            'content' => ['nullable', 'string', 'max:50000'],
            'source_url' => ['nullable', 'url', 'max:2048'],
            'source_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'intake_mode' => ['nullable', 'in:chat,capture'],
            ...$this->mapReturnRules(),
        ]);

        $file = $request->file('source_file');
        $content = trim((string) ($validated['content'] ?? ''));
        $sourceUrl = trim((string) ($validated['source_url'] ?? ''));
        $intakeMode = (string) ($validated['intake_mode'] ?? 'capture');

        if ($sourceUrl === '' && $content !== '') {
            $sourceUrl = $this->extractFirstUrl($content);
        }

        if (! $file && $content === '' && $sourceUrl === '') {
            throw ValidationException::withMessages([
                'content' => 'テキスト・URL・画像・PDFのいずれかを追加してください。',
            ]);
        }

        $planId = ! empty($validated['plan_id']) ? (int) $validated['plan_id'] : null;
        if ($planId) {
            $plan = $ownership->ownedPlans($request)->firstWhere('id', $planId);
            if (! $plan || ! $ownership->canEdit($request, $plan)) {
                throw ValidationException::withMessages([
                    'plan_id' => 'このPlanへ追加する権限がありません。',
                ]);
            }
        }

        $actorToken = $identity->resolve($request);
        $userId = $request->user()?->id;
        $sourceType = 'text';
        $storagePath = null;
        $mimeType = null;
        $originalName = null;
        $byteSize = null;

        if ($file) {
            $mimeType = (string) $file->getMimeType();
            $sourceType = $mimeType === 'application/pdf' ? 'pdf' : 'image';
            $originalName = (string) $file->getClientOriginalName();
            $byteSize = (int) $file->getSize();
            $extension = strtolower((string) $file->getClientOriginalExtension());
            $storagePath = $file->storeAs(
                'inbox/'.($userId ?: 'guest').'/'.now()->format('Y/m'),
                (string) Str::uuid().'.'.$extension,
            );
        } elseif ($sourceUrl !== '') {
            $sourceType = 'url';
        }

        $title = trim((string) ($validated['title'] ?? ''));
        if ($title === '') {
            $title = $this->inferTitle($sourceType, $content, $sourceUrl, $originalName);
        }

        $intakeMetadata = [];
        if ($intakeMode === 'chat') {
            $isSpaceStation = in_array(
                (string) ($validated['return_to'] ?? ''),
                ['space_station', 'map_station'],
                true,
            );

            $intakeMetadata = [
                'intake_mode' => 'chat',
                'capture_surface' => $isSpaceStation ? 'space_station' : 'inbox',
            ];

            if ($isSpaceStation) {
                $intakeMetadata['map_context'] = array_filter([
                    'level' => $validated['map_level'] ?? null,
                    'intent' => $validated['map_intent'] ?? null,
                    'domain' => $validated['map_domain'] ?? null,
                    'plan_id' => isset($validated['map_plan']) ? (int) $validated['map_plan'] : null,
                    'collaboration_context' => $validated['map_collab_context'] ?? null,
                    'reflection_context' => $validated['map_reflection_context'] ?? null,
                ], fn ($value) => $value !== null && $value !== '');
            }
        }

        $item = InboxItem::query()->create([
            'user_id' => $userId,
            'actor_token' => $userId ? null : $actorToken,
            'plan_id' => $planId,
            'source_type' => $sourceType,
            'status' => 'new',
            'title' => $title,
            'content' => $content !== '' ? $content : null,
            'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
            'storage_path' => $storagePath,
            'mime_type' => $mimeType,
            'original_name' => $originalName,
            'byte_size' => $byteSize,
            'metadata' => $intakeMetadata,
        ]);

        $redirect = $this->redirectAfterAction($request);

        if ($intakeMode === 'chat') {
            $interpreted = false;

            if (
                $nativeAi->isConfigured()
                && $featureAccess->canUse($request->user(), FeatureKey::AutomaticAiExecution)
            ) {
                try {
                    $suggestion = $intelligence->suggest($item, $request->user()?->id);
                    $metadata = is_array($item->metadata) ? $item->metadata : [];
                    $metadata['routing_suggestion'] = $suggestion;

                    $item->update([
                        'status' => 'review',
                        'metadata' => $metadata,
                    ]);
                    $interpreted = true;
                } catch (NativeAiExecutionException) {
                    // Capture must remain available even when interpretation is temporarily unavailable.
                }
            }

            return $redirect
                ->with(
                    'success',
                    $interpreted
                        ? '受け取りました。内容から次の行き先候補も整理しました。'
                        : '受け取りました。まだ整理しなくて大丈夫です。',
                )
                ->with('inbox_focus_id', (int) $item->id);
        }

        return $redirect
            ->with('success', 'Inboxへ追加しました。整理先はあとから決められます。');
    }

    public function suggest(
        Request $request,
        InboxItem $inboxItem,
        BehaviorIdentityService $identity,
        FeatureAccessService $featureAccess,
        InboxIntelligenceService $intelligence,
    ) {
        $request->validate($this->mapReturnRules());

        $this->authorizeItem($request, $inboxItem, $identity);
        $featureAccess->authorizeUse($request->user(), FeatureKey::AutomaticAiExecution);

        try {
            $suggestion = $intelligence->suggest($inboxItem, $request->user()?->id);
        } catch (NativeAiExecutionException $exception) {
            return $this->redirectAfterAction($request)
                ->with('status', $exception->getMessage());
        }

        $metadata = is_array($inboxItem->metadata) ? $inboxItem->metadata : [];
        $metadata['routing_suggestion'] = $suggestion;

        $inboxItem->update([
            'status' => 'review',
            'metadata' => $metadata,
        ]);

        return $this->redirectAfterAction($request)
            ->with('success', '行き先候補を作りました。確認してから確定してください。');
    }

    public function routeItem(
        Request $request,
        InboxItem $inboxItem,
        BehaviorIdentityService $identity,
        PlanOwnershipService $ownership,
        FeatureAccessService $featureAccess,
        InboxRoutingService $routing,
    ) {
        $this->authorizeItem($request, $inboxItem, $identity);

        $validated = $request->validate([
            'destination' => ['required', 'in:'.implode(',', array_keys(InboxIntelligenceService::DESTINATIONS))],
            'plan_id' => ['nullable', 'integer'],
            'task_id' => ['nullable', 'integer'],
            'execution_instruction' => ['required_if:destination,execution_request', 'nullable', 'string', 'max:6000'],
            'execution_actor_type' => ['nullable', 'in:'.implode(',', array_keys(ExecutionPacketService::ACTOR_TYPES))],
            'execution_available_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'future_memo_kind' => ['nullable', 'in:'.implode(',', array_keys(FutureMemo::KINDS))],
            'future_memo_category' => ['nullable', 'in:'.implode(',', array_keys(FutureMemo::CATEGORIES))],
            ...$this->mapReturnRules(),
        ]);

        $editablePlans = $ownership->ownedPlans($request, ['tasks'])
            ->filter(fn ($plan) => $ownership->canEdit($request, $plan))
            ->values();

        $plan = ! empty($validated['plan_id'])
            ? $editablePlans->firstWhere('id', (int) $validated['plan_id'])
            : null;

        if (! empty($validated['plan_id']) && ! $plan) {
            throw ValidationException::withMessages(['plan_id' => 'このPlanへ整理する権限がありません。']);
        }

        $task = null;
        if (! empty($validated['task_id'])) {
            $task = Task::query()->find((int) $validated['task_id']);
            if (! $task || ! $plan || (int) $task->plan_id !== (int) $plan->id) {
                throw ValidationException::withMessages(['task_id' => '選択したPlanのTaskを選んでください。']);
            }
            $ownership->authorizeTask($request, $task);
        }

        if (($validated['destination'] ?? null) === 'recall_material') {
            $featureAccess->authorizeUse(
                $request->user(),
                FeatureKey::AutomaticAiExecution,
                ['plan_id' => $plan?->id, 'task_id' => $task?->id],
            );
        }

        $result = $routing->route(
            $request,
            $inboxItem,
            $validated,
            $plan,
            $task,
            $identity->resolve($request),
        );

        if (
            ($result['destination'] ?? null) === 'execution_request'
            && $plan
            && $task
        ) {
            return redirect()
                ->route('plans.tasks.execution_orchestration.show', [$plan, $task])
                ->with('success', $result['message']);
        }

        $redirect = $this->redirectAfterAction($request)
            ->with('success', $result['message']);

        if (in_array((string) $request->input('return_to'), ['space_station', 'map_station'], true)) {
            $redirect->with('space_station_route_result', [
                'destination' => (string) ($result['destination'] ?? ''),
                'plan_id' => $plan?->id,
                'task_id' => $task?->id,
                'message' => (string) ($result['message'] ?? ''),
            ]);
        }

        return $redirect;
    }

    public function updateStatus(
        Request $request,
        InboxItem $inboxItem,
        BehaviorIdentityService $identity,
    ) {
        $this->authorizeItem($request, $inboxItem, $identity);

        $validated = $request->validate([
            'status' => ['required', 'in:new,processed,archived'],
        ]);

        $status = (string) $validated['status'];
        $inboxItem->update([
            'status' => $status,
            'processed_at' => in_array($status, ['processed', 'archived'], true) ? now() : null,
        ]);

        return redirect()
            ->route('inbox.index')
            ->with('status', match ($status) {
                'processed' => '整理済みにしました。',
                'archived' => 'Inboxからアーカイブしました。',
                default => 'Inboxへ戻しました。',
            });
    }

    public function file(
        Request $request,
        InboxItem $inboxItem,
        BehaviorIdentityService $identity,
    ) {
        $this->authorizeItem($request, $inboxItem, $identity);
        abort_unless($inboxItem->storage_path && Storage::exists($inboxItem->storage_path), 404);

        return Storage::response(
            $inboxItem->storage_path,
            $inboxItem->original_name ?: basename($inboxItem->storage_path),
            [
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function ownInboxItems(?int $userId, string $actorToken): Builder
    {
        return InboxItem::query()->where(function (Builder $query) use ($userId, $actorToken) {
            $this->applyIdentityScope($query, $userId, $actorToken);
        });
    }

    private function applyIdentityScope(Builder $query, ?int $userId, string $actorToken): void
    {
        if ($userId) {
            $query->where('user_id', $userId)
                ->orWhere('actor_token', $actorToken);
            return;
        }

        $query->whereNull('user_id')->where('actor_token', $actorToken);
    }

    private function authorizeItem(
        Request $request,
        InboxItem $item,
        BehaviorIdentityService $identity,
    ): void {
        if ($item->user_id !== null) {
            abort_unless($request->user() && (int) $item->user_id === (int) $request->user()->id, 403);
            return;
        }

        $actorToken = $identity->resolve($request);
        abort_unless(
            is_string($item->actor_token)
            && $item->actor_token !== ''
            && hash_equals($item->actor_token, $actorToken),
            403,
        );
    }

    /**
     * @return array<string,array<int,string>>
     */
    private function mapReturnRules(): array
    {
        return [
            'return_to' => ['nullable', 'in:inbox,space_station,map_station'],
            'map_level' => ['nullable', 'in:l1,l2,l3'],
            'map_intent' => ['nullable', 'in:plan,execution,reflection,collaboration'],
            'map_domain' => ['nullable', 'string', 'regex:/^[a-f0-9]{12}$/'],
            'map_plan' => ['nullable', 'integer', 'min:1'],
            'map_collab_context' => ['nullable', 'in:my_action,review,waiting,external'],
            'map_reflection_context' => ['nullable', 'in:recent,evidence,completed,reflections'],
        ];
    }

    private function redirectAfterAction(Request $request)
    {
        if ($request->input('return_to') === 'space_station') {
            return redirect()->to(
                route('map.index').'#focus='.rawurlencode('intent:space-station')
            );
        }

        if ($request->input('return_to') === 'map_station') {
            $level = in_array($request->input('map_level'), ['l1', 'l2', 'l3'], true)
                ? (string) $request->input('map_level')
                : 'l1';
            $intent = in_array($request->input('map_intent'), ['plan', 'execution', 'reflection', 'collaboration'], true)
                ? (string) $request->input('map_intent')
                : 'plan';

            $params = [
                'level' => $level,
                'intent' => $intent,
            ];

            $domain = trim((string) $request->input('map_domain', ''));
            if (preg_match('/^[a-f0-9]{12}$/', $domain)) {
                $params['domain'] = $domain;
            }

            $planId = max(0, (int) $request->input('map_plan', 0));
            if ($planId > 0) {
                $params['plan'] = $planId;
            }

            $collaborationContext = trim((string) $request->input('map_collab_context', ''));
            if (
                $intent === 'collaboration'
                && in_array($collaborationContext, ['my_action', 'review', 'waiting', 'external'], true)
            ) {
                $params['collab_context'] = $collaborationContext;
            }

            $reflectionContext = trim((string) $request->input('map_reflection_context', ''));
            if (
                $intent === 'reflection'
                && in_array($reflectionContext, ['recent', 'evidence', 'completed', 'reflections'], true)
            ) {
                $params['reflection_context'] = $reflectionContext;
            }

            return redirect()->to(
                route('map.index', $params).'#dock=space-station'
            );
        }

        return redirect()->route('inbox.index');
    }

    private function extractFirstUrl(string $content): string
    {
        if (! preg_match('~https?://[^\s<>]+~u', $content, $matches)) {
            return '';
        }

        $candidate = rtrim((string) ($matches[0] ?? ''), ".,、。)]}」』");

        if (mb_strlen($candidate) > 2048) {
            return '';
        }

        return filter_var($candidate, FILTER_VALIDATE_URL) ? $candidate : '';
    }

    private function inferTitle(
        string $sourceType,
        string $content,
        string $sourceUrl,
        ?string $originalName,
    ): string {
        if ($sourceType === 'image' || $sourceType === 'pdf') {
            return mb_substr($originalName ?: 'ファイル', 0, 255);
        }

        if ($sourceType === 'url') {
            $host = parse_url($sourceUrl, PHP_URL_HOST);

            return mb_substr(is_string($host) && $host !== '' ? $host : $sourceUrl, 0, 255);
        }

        return mb_substr(Str::squish($content), 0, 80);
    }
}
