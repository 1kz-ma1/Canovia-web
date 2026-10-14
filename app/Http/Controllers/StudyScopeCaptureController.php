<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Intelligence\Enums\IntelligenceDomain;
use App\Intelligence\Presentation\IntelligencePresentationHistoryService;
use App\Intelligence\Presentation\StudyIntelligencePresentationAdapter;
use App\Intelligence\Study\StudyAdaptiveActionService;
use App\Intelligence\Study\StudyPlanIntelligenceService;
use App\Exceptions\NativeAiExecutionException;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\StudyScopeCapture;
use App\Models\StudyScopeItem;
use App\Services\BehaviorIdentityService;
use App\Services\CompanionMembershipCommitGuard;
use App\Services\PrivateFileCleanupGuard;
use App\Services\FeatureAccessService;
use App\Services\NativeAiGateway;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\StudyScopeCaptureAnalysisService;
use App\Services\StudyScopeCaptureCommitFence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StudyScopeCaptureController extends Controller
{
    public function index(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        FeatureAccessService $featureAccess,
        NativeAiGateway $nativeAi,
        StudyAdaptiveActionService $studyActions,
        StudyIntelligencePresentationAdapter $presentationAdapter,
        IntelligencePresentationHistoryService $history,
    ) {
        $ownership->authorizeView($request, $plan);
        $this->authorizeStudyPlan($plan, $profiles);

        $captures = $plan->studyScopeCaptures()
            ->with(['inboxItem', 'nativeAiRun', 'items'])
            ->latest('created_at')
            ->latest('id')
            ->get();

        $adaptiveAction = $studyActions->evaluate($plan);
        $intelligence = $captures->contains(
            fn (StudyScopeCapture $capture) => $capture->status === 'confirmed'
        )
            ? $adaptiveAction->intelligence
            : null;

        $intelligencePresentation = $presentationAdapter->adapt(
            $plan,
            $adaptiveAction,
        );
        $intelligenceHistory = $history->forPlan(
            $request,
            $plan,
            IntelligenceDomain::Study,
        );

        return view('study_scope.index', [
            'plan' => $plan,
            'captures' => $captures,
            'canEdit' => $ownership->canEdit($request, $plan),
            'studyIntelligence' => $intelligence,
            'studyAdaptiveAction' => $adaptiveAction,
            'intelligencePresentation' => $intelligencePresentation,
            'intelligenceHistory' => $intelligenceHistory,
            'canAnalyze' => $nativeAi->isConfigured()
                && $featureAccess->canUse(
                    $request->user(),
                    FeatureKey::StudyScopeCapture,
                    ['plan_id' => (int) $plan->id],
                ),
        ]);
    }

    public function store(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        FeatureAccessService $featureAccess,
        BehaviorIdentityService $identity,
        NativeAiGateway $nativeAi,
        StudyScopeCaptureAnalysisService $analysis,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->authorizeStudyPlan($plan, $profiles);
        $featureAccess->authorizeUse(
            $request->user(),
            FeatureKey::StudyScopeCapture,
            ['plan_id' => (int) $plan->id],
        );

        $validated = $request->validate([
            'source_file' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp',
                'max:10240',
            ],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $file = $request->file('source_file');
        $mimeType = (string) $file->getMimeType();
        $sourceType = $mimeType === 'application/pdf' ? 'pdf' : 'image';
        $userId = $request->user()?->id;
        $actorToken = $identity->resolve($request);
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === '') {
            $extension = $sourceType === 'pdf' ? 'pdf' : 'jpg';
        }

        $storagePath = $file->storeAs(
            'inbox/'.($userId ?: 'guest').'/'.now()->format('Y/m'),
            (string) Str::uuid().'.'.$extension,
        );

        try {
            [$item, $capture] = DB::transaction(function () use (
                $plan,
                $validated,
                $file,
                $mimeType,
                $sourceType,
                $storagePath,
                $userId,
                $actorToken,
            ) {
                $item = InboxItem::query()->create([
                    'user_id' => $userId,
                    'actor_token' => $userId ? null : $actorToken,
                    'plan_id' => (int) $plan->id,
                    'source_type' => $sourceType,
                    'status' => 'new',
                    'title' => mb_substr(
                        (string) $file->getClientOriginalName(),
                        0,
                        255,
                    ),
                    'content' => trim((string) ($validated['note'] ?? '')) ?: null,
                    'storage_path' => $storagePath,
                    'mime_type' => $mimeType,
                    'original_name' => mb_substr(
                        (string) $file->getClientOriginalName(),
                        0,
                        255,
                    ),
                    'byte_size' => (int) $file->getSize(),
                    'metadata' => [
                        'capture_surface' => 'study_scope',
                        'study_scope_capture' => true,
                    ],
                ]);

                $capture = StudyScopeCapture::query()->create([
                    'plan_id' => (int) $plan->id,
                    'user_id' => $userId,
                    'inbox_item_id' => (int) $item->id,
                    'status' => 'captured',
                    'extraction_version' => 'study_scope_v1',
                ]);

                return [$item, $capture];
            });
        } catch (Throwable $exception) {
            Storage::delete($storagePath);
            throw $exception;
        }

        if (! $nativeAi->isConfigured()) {
            return redirect()
                ->route('plans.study_scope.index', $plan)
                ->with('success', '試験範囲を受け取りました。AI解析が使えない間も手動で範囲を確定できます。');
        }

        try {
            $result = $analysis->analyze(
                $capture,
                $plan,
                $userId,
            );

            return redirect()
                ->route('plans.study_scope.index', $plan)
                ->with(
                    'success',
                    $result['item_count'] > 0
                        ? '試験範囲を読み取りました。内容を確認して確定してください。'
                        : '試験範囲を読み取りましたが、確実な範囲を特定できませんでした。手動で補って確認してください。',
                );
        } catch (NativeAiExecutionException $exception) {
            return redirect()
                ->route('plans.study_scope.index', $plan)
                ->with('status', 'ファイルは保存済みです。AI解析は一時的に失敗したため、再試行または手動確認ができます。');
        }
    }

    public function analyze(
        Request $request,
        Plan $plan,
        StudyScopeCapture $capture,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        FeatureAccessService $featureAccess,
        NativeAiGateway $nativeAi,
        StudyScopeCaptureAnalysisService $analysis,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->authorizeStudyPlan($plan, $profiles);
        $this->authorizeCapture($plan, $capture);
        $featureAccess->authorizeUse(
            $request->user(),
            FeatureKey::StudyScopeCapture,
            ['plan_id' => (int) $plan->id],
        );

        if ($capture->status === 'confirmed') {
            throw ValidationException::withMessages([
                'capture' => '確定済みの範囲は再解析せず、確定内容を直接編集してください。',
            ]);
        }

        if (! $nativeAi->isConfigured()) {
            return back()->with('status', '現在AI解析を利用できません。手動で範囲を入力して確定できます。');
        }

        try {
            $analysis->analyze(
                $capture,
                $plan,
                $request->user()?->id,
            );
        } catch (NativeAiExecutionException) {
            return back()->with('status', 'AI解析に失敗しました。元ファイルは残っているため、あとで再試行できます。');
        }

        return redirect()
            ->route('plans.study_scope.index', $plan)
            ->with('success', '試験範囲を再解析しました。確定前に内容を確認してください。');
    }

    public function confirm(
        Request $request,
        Plan $plan,
        StudyScopeCapture $capture,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        StudyAdaptiveActionService $studyActions,
        StudyScopeCaptureCommitFence $commitFence,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->authorizeStudyPlan($plan, $profiles);
        $this->authorizeCapture($plan, $capture);

        $validated = $request->validate([
            'exam_title' => ['nullable', 'string', 'max:255'],
            'exam_date' => ['nullable', 'date_format:Y-m-d'],
            'items' => ['required', 'array', 'max:100'],
            'items.*.item_id' => ['nullable', 'integer'],
            'items.*.draft_index' => ['nullable', 'integer', 'min:0', 'max:99'],
            'items.*.subject' => ['nullable', 'string', 'max:120'],
            'items.*.unit' => ['nullable', 'string', 'max:255'],
            'items.*.range_text' => ['nullable', 'string', 'max:1000'],
            'items.*.page_start' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'items.*.page_end' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        // Lock Plan -> Capture -> Inbox -> Membership before reading draft
        // excerpts or writing authoritative StudyScopeItems. This also
        // rejects a concurrent reanalysis/revocation without partial writes.
        DB::transaction(function () use ($capture, $plan, $request, $validated, $commitFence) {
            // Inspect the original submitted indices as well as validated
            // fields: an explicit draft reference never counts as manual
            // entry simply because a value was filtered by validation.
            $usesDraft = collect($request->input('items', []))
                ->contains(fn ($item) => is_array($item)
                    && array_key_exists('draft_index', $item));
            $liveCapture = $commitFence->assertCanConfirm(
                $capture, $plan, $request->user()?->id, $usesDraft,
            );

            $draftItems = collect(data_get($liveCapture->draft_data, 'items', []));
            $existingItems = $liveCapture->items()->get()->keyBy('id');
            $normalized = [];

            foreach (($validated['items'] ?? []) as $index => $item) {
                $subject = trim((string) ($item['subject'] ?? ''));
                $unit = trim((string) ($item['unit'] ?? ''));
                $rangeText = trim((string) ($item['range_text'] ?? ''));
                $pageStart = isset($item['page_start']) && $item['page_start'] !== ''
                    ? (int) $item['page_start']
                    : null;
                $pageEnd = isset($item['page_end']) && $item['page_end'] !== ''
                    ? (int) $item['page_end']
                    : null;

                $hasContent = $subject !== ''
                    || $unit !== ''
                    || $rangeText !== ''
                    || $pageStart !== null
                    || $pageEnd !== null;

                if (! $hasContent) {
                    continue;
                }

                if ($subject === '') {
                    throw ValidationException::withMessages([
                        "items.{$index}.subject" => '確定する範囲には科目名を入力してください。',
                    ]);
                }

                if ($pageStart !== null && $pageEnd !== null && $pageEnd < $pageStart) {
                    throw ValidationException::withMessages([
                        "items.{$index}.page_end" => '終了ページは開始ページ以降にしてください。',
                    ]);
                }

                $sourceExcerpt = null;
                if (isset($item['item_id'])) {
                    $existing = $existingItems->get((int) $item['item_id']);
                    if ($existing === null) {
                        throw ValidationException::withMessages([
                            "items.{$index}.item_id" => 'このCaptureに属さない範囲は更新できません。',
                        ]);
                    }
                    $sourceExcerpt = $existing->source_excerpt;
                } elseif (isset($item['draft_index'])) {
                    $draft = $draftItems->get((int) $item['draft_index']);
                    if (is_array($draft)) {
                        $sourceExcerpt = filled($draft['source_excerpt'] ?? null)
                            ? mb_substr(trim((string) $draft['source_excerpt']), 0, 1000)
                            : null;
                    }
                }

                $normalized[] = [
                    'subject' => mb_substr($subject, 0, 120),
                    'unit' => $unit !== '' ? mb_substr($unit, 0, 255) : null,
                    'range_text' => $rangeText !== '' ? mb_substr($rangeText, 0, 1000) : null,
                    'page_start' => $pageStart,
                    'page_end' => $pageEnd,
                    'source_excerpt' => $sourceExcerpt,
                ];
            }

            if ($normalized === []) {
                throw ValidationException::withMessages([
                    'items' => '少なくとも1件の試験範囲を入力してください。',
                ]);
            }

            $liveCapture->items()->delete();

            foreach ($normalized as $sortOrder => $item) {
                StudyScopeItem::query()->create([
                    'study_scope_capture_id' => (int) $liveCapture->id,
                    'plan_id' => (int) $liveCapture->plan_id,
                    ...$item,
                    // Human confirmation is the authority boundary.
                    'confidence' => 1.0,
                    'sort_order' => $sortOrder,
                ]);
            }

            $liveCapture->update([
                'status' => 'confirmed',
                'exam_title' => trim((string) ($validated['exam_title'] ?? '')) ?: null,
                'exam_date' => $validated['exam_date'] ?? null,
                'confirmed_at' => now(),
                'failure_code' => null,
            ]);

            if ($liveCapture->inboxItem) {
                $liveCapture->inboxItem->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                ]);
            }
        }, 3);

        $studyActions->tryRefresh($plan, now());

        return redirect()
            ->route('plans.study_scope.index', $plan)
            ->with('success', '試験範囲を確定し、Study Intelligenceを更新しました。Taskや進捗は変更していません。')
            ->with('study_scope_confirmed', true);
    }

    public function destroy(
        Request $request,
        Plan $plan,
        StudyScopeCapture $capture,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        CompanionMembershipCommitGuard $membership,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->authorizeStudyPlan($plan, $profiles);
        $this->authorizeCapture($plan, $capture);

        $deletePath = DB::transaction(function () use (
            $request, $plan, $capture, $membership,
        ): ?string {
            // Match the AI prepare/commit/confirm lock order. A Capture
            // cannot disappear halfway through a locked AI commit, nor can
            // a stale route model bypass an Owner/Editor revocation.
            $livePlan = Plan::query()->whereKey($plan->id)
                ->lockForUpdate()->firstOrFail();
            $liveCapture = StudyScopeCapture::query()
                ->where('plan_id', $livePlan->id)
                ->whereKey($capture->id)
                ->lockForUpdate()->firstOrFail();
            $source = InboxItem::query()->whereKey($liveCapture->inbox_item_id)
                ->lockForUpdate()->first();

            if (! $request->user()
                || ! $membership->canAccess($request->user(), $livePlan, true)) {
                abort(403, 'この試験範囲を破棄する権限がありません。');
            }
            if ($liveCapture->status === 'confirmed') {
                throw ValidationException::withMessages([
                    'capture' => '確定済みの範囲はこの画面から破棄できません。',
                ]);
            }

            if ($source !== null
                && ((int) $source->plan_id !== (int) $livePlan->id
                    || (int) $source->id !== (int) $liveCapture->inbox_item_id)) {
                throw ValidationException::withMessages([
                    'capture' => '元資料の参照先が変更されました。再読み込みしてください。',
                ]);
            }

            // Never unlink a file merely because a route-bound InboxItem
            // happened to have a path: only this Capture's owned file may
            // be removed after the DB transaction commits.
            $ownedSource = $source !== null
                && (bool) data_get($source->metadata, 'study_scope_capture', false)
                && (int) $source->user_id === (int) $liveCapture->user_id;
            $path = $ownedSource && is_string($source->storage_path)
                ? $source->storage_path : null;

            $liveCapture->delete();
            if ($ownedSource) {
                $source->delete();
            }

            return $path;
        }, 3);

        // Physical storage is not transactional. At this point the source
        // row has been committed deleted; a concurrent AI read can either
        // consume its earlier immutable bytes (and fail commit) or observe
        // missing bytes (and fail before provider submission).
        if ($deletePath !== null && $deletePath !== '') {
            app(PrivateFileCleanupGuard::class)->deleteIfUnreferenced($deletePath);
        }

        return redirect()
            ->route('plans.study_scope.index', $plan)
            ->with('success', '未確定の試験範囲を破棄しました。');
    }

    private function authorizeStudyPlan(
        Plan $plan,
        PlanCategoryProfileService $profiles,
    ): void {
        abort_unless($profiles->forPlan($plan)->key === 'study', 404);
    }

    private function authorizeCapture(
        Plan $plan,
        StudyScopeCapture $capture,
    ): void {
        abort_unless((int) $capture->plan_id === (int) $plan->id, 404);
    }
}
