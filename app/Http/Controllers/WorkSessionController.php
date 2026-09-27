<?php

namespace App\Http\Controllers;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Task;
use App\Models\WorkLog;
use App\Models\WorkSession;
use App\Services\BehaviorEventLogger;
use App\Services\BehaviorIdentityService;
use App\Services\PlanOwnershipService;
use App\Services\TaskEvidenceService;
use App\Services\WorkSessionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkSessionController extends Controller
{
    public function start(
        Request $request,
        BehaviorIdentityService $identity,
        BehaviorEventLogger $logger,
        PlanOwnershipService $ownership,
    ) {
        $validated = $request->validate([
            'task_id' => ['required', 'integer', 'min:1'],
            'intended_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'source' => ['required', 'in:dashboard,navigation,plan,roadmap'],
            'start_request_id' => ['nullable', 'uuid'],
            'map_flow_id' => ['nullable', 'uuid'],
            'map_flow_elapsed_ms' => ['nullable', 'integer', 'min:0', 'max:3600000'],
            'map_flow_step_count' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);
        $task = Task::with('plan')->findOrFail($validated['task_id']);
        $ownership->authorizeTask($request, $task);

        if (in_array($task->status, ['done', 'cancelled'], true)) {
            throw ValidationException::withMessages(['task_id' => '完了または中止済みのTaskは開始できません。']);
        }

        $actorToken = $identity->resolve($request);
        $startRequestId = $validated['start_request_id'] ?? (string) Str::uuid();

        $lastEntry = in_array($validated['source'], ['dashboard', 'navigation'], true)
            ? BehaviorEvent::query()
                ->where('actor_token', $actorToken)
                ->where('session_id', $request->session()->getId())
                ->whereIn('event_type', [
                    BehaviorEventType::DashboardViewed->value,
                    BehaviorEventType::NavigationStarted->value,
                    BehaviorEventType::RecommendationShown->value,
                ])
                ->latest('occurred_at')
                ->first()
            : null;
        $startLatency = $lastEntry
            ? min(21600, max(0, (int) $lastEntry->occurred_at->diffInSeconds(now())))
            : null;

        $startResult = DB::transaction(function () use ($request, $validated, $task, $actorToken, $logger, $startLatency, $startRequestId) {
            // Serialize "start work" for one actor. A normal SELECT on active
            // sessions is race-prone because two requests can both observe an
            // empty set before either inserts. This tiny lock row closes that gap.
            DB::table('work_session_actor_locks')->insertOrIgnore([
                'actor_token' => $actorToken,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('work_session_actor_locks')
                ->where('actor_token', $actorToken)
                ->lockForUpdate()
                ->first();

            $sameRequest = WorkSession::query()
                ->where('start_request_id', $startRequestId)
                ->first();

            if ($sameRequest) {
                if (! hash_equals((string) $sameRequest->actor_token, (string) $actorToken)) {
                    abort(409, 'この作業開始リクエストは別の利用者で使用済みです。');
                }

                return ['session' => $sameRequest, 'reason' => 'same_request'];
            }

            $active = WorkSession::query()
                ->whereIn('status', ['active', 'paused'])
                ->where('actor_token', $actorToken)
                ->latest('started_at')
                ->first();

            if ($active) {
                return ['session' => $active, 'reason' => 'active'];
            }

            $session = WorkSession::create([
                'actor_token' => $actorToken,
                'browser_session_id' => $request->session()->getId(),
                'start_request_id' => $startRequestId,
                'plan_id' => $task->plan_id,
                'task_id' => $task->id,
                'status' => 'active',
                'intended_minutes' => $validated['intended_minutes'] ?? null,
                'started_at' => now(),
                'paused_seconds' => 0,
                'source' => $validated['source'],
            ]);

            if (in_array($validated['source'], ['dashboard', 'navigation'], true)) {
                $logger->record($actorToken, BehaviorEventType::RecommendationAccepted, $request, $task->plan, $task, [
                    'source' => $validated['source'],
                    'recommended_minutes' => $validated['intended_minutes'] ?? null,
                ]);
            }

            $logger->record($actorToken, BehaviorEventType::TaskStarted, $request, $task->plan, $task, [
                'source' => $validated['source'],
            ]);
            $logger->record($actorToken, BehaviorEventType::WorkStarted, $request, $task->plan, $task, [
                'source' => $validated['source'],
                'intended_minutes' => $validated['intended_minutes'] ?? null,
                'start_latency_seconds' => $startLatency,
                'work_session_id' => $session->id,
            ]);

            if (filled($validated['map_flow_id'] ?? null)) {
                $logger->recordSafely(
                    $actorToken,
                    BehaviorEventType::MapExecutionStarted,
                    $request,
                    $task->plan,
                    $task,
                    [
                        'flow_id' => (string) $validated['map_flow_id'],
                        'elapsed_ms' => (int) ($validated['map_flow_elapsed_ms'] ?? 0),
                        'step_count' => (int) ($validated['map_flow_step_count'] ?? 0),
                        'source' => $validated['source'],
                    ],
                );
            }

            return ['session' => $session, 'reason' => 'created'];
        });

        /** @var WorkSession $workSession */
        $workSession = $startResult['session'];

        if ($startResult['reason'] !== 'created') {
            $message = $startResult['reason'] === 'same_request'
                ? 'この作業はすでに開始済みです。同じタイマーへ戻りました。'
                : '進行中の作業があります。新しいタイマーは作らず、進行中の作業へ戻りました。';

            return redirect()->route('work_sessions.active', $workSession)->with('status', $message);
        }

        $request->session()->forget(['dashboard.recommendation_excluded', 'navigation.draft']);

        return redirect()->route('work_sessions.active', $workSession);
    }

    public function active(
        Request $request,
        WorkSession $workSession,
        BehaviorIdentityService $identity,
        PlanOwnershipService $ownership,
        WorkSessionService $sessions,
    ) {
        $this->authorizeSession($request, $workSession, $identity, $ownership);
        $workSession->load(['plan', 'task']);
        $activeSeconds = $sessions->activeSeconds($workSession);

        return view('work_sessions.active', compact('workSession', 'activeSeconds'));
    }

    public function pause(
        Request $request,
        WorkSession $workSession,
        BehaviorIdentityService $identity,
        PlanOwnershipService $ownership,
        WorkSessionService $sessions,
    ) {
        $this->authorizeSession($request, $workSession, $identity, $ownership);
        $sessions->pause($workSession);

        return redirect()->route('work_sessions.active', $workSession)->with('status', '一時停止しました。');
    }

    public function resume(
        Request $request,
        WorkSession $workSession,
        BehaviorIdentityService $identity,
        PlanOwnershipService $ownership,
        WorkSessionService $sessions,
    ) {
        $this->authorizeSession($request, $workSession, $identity, $ownership);
        $sessions->resume($workSession);

        return redirect()->route('work_sessions.active', $workSession)->with('status', '作業を再開しました。');
    }

    public function complete(
        Request $request,
        WorkSession $workSession,
        BehaviorIdentityService $identity,
        BehaviorEventLogger $logger,
        PlanOwnershipService $ownership,
        WorkSessionService $sessions,
        TaskEvidenceService $evidenceService,
    ) {
        $this->authorizeSession($request, $workSession, $identity, $ownership);
        $workSession->load(['plan', 'task']);
        $actorToken = $identity->resolve($request);
        [$finishOptions, $timerMetadata, $durationConfirmed] = $this->resolveTimerAdjustment($request, $workSession);
        $preview = $sessions->previewFinish($workSession, $finishOptions);
        $this->guardLongDuration($workSession, $preview['active_seconds'], $durationConfirmed, $sessions);

        $metrics = DB::transaction(function () use ($request, $workSession, $actorToken, $logger, $sessions, $evidenceService, $finishOptions, $timerMetadata) {
            $metrics = $sessions->finish($workSession, 'completed', $finishOptions);
            $metadata = $this->appendTimerMetadata($workSession->metadata, $timerMetadata, $metrics);
            $workSession->forceFill([
                'needs_plan_update' => true,
                'plan_updated_at' => null,
                'metadata' => $metadata,
            ])->save();

            if ($workSession->plan && $workSession->task) {
                WorkLog::updateOrCreate(
                    ['work_session_id' => $workSession->id],
                    [
                        'plan_id' => $workSession->plan_id,
                        'task_id' => $workSession->task_id,
                        'task_title_snapshot' => $workSession->task->title,
                        'worked_on' => $metrics['ended_at']->toDateString(),
                        'actual_minutes' => $metrics['actual_minutes'],
                        'progress_delta_percent' => 0,
                        'progress_before_percent' => $workSession->task->progress_percent,
                        'progress_after_percent' => $workSession->task->progress_percent,
                        'remaining_minutes_before' => $workSession->task->remaining_minutes,
                        'remaining_minutes_after' => $workSession->task->remaining_minutes,
                        'memo' => null,
                        'outcome' => '作業セッションを終了',
                    ]
                );
            }

            if ($workSession->task) {
                $evidenceService->recordFocusSession($workSession, $metrics, 'completed');
            }

            $logger->record($actorToken, BehaviorEventType::WorkCompleted, $request, $workSession->plan, $workSession->task, [
                'work_session_id' => $workSession->id,
                'duration_seconds' => $metrics['active_seconds'],
                'wall_seconds' => $metrics['wall_seconds'],
                'paused_seconds' => $metrics['paused_seconds'],
                'actual_minutes' => $metrics['actual_minutes'],
                'intended_minutes' => $workSession->intended_minutes,
                'timer_adjusted' => $timerMetadata !== [],
                'timer_action' => $timerMetadata['action'] ?? 'normal',
            ]);

            return $metrics;
        });

        if (! $workSession->plan) {
            return redirect()->route('home')->with('success', "{$metrics['actual_minutes']}分の作業を記録しました。");
        }

        return redirect()
            ->route('plans.review_assistant.show', [
                'plan' => $workSession->plan,
                'work_session_id' => $workSession->id,
            ])
            ->with('status', "{$metrics['actual_minutes']}分の作業事実を記録しました。必要なら、このままAIで計画へ意味付けできます。");
    }

    public function review(
        Request $request,
        WorkSession $workSession,
        BehaviorIdentityService $identity,
        PlanOwnershipService $ownership,
    ) {
        $this->authorizeSession($request, $workSession, $identity, $ownership);
        $workSession->load(['plan', 'task']);

        if ($workSession->status !== 'completed' || ! $workSession->plan) {
            return redirect()->route('home');
        }

        return redirect()->route('plans.review_assistant.show', [
            'plan' => $workSession->plan,
            'work_session_id' => $workSession->id,
        ]);
    }

    public function storeReview(
        Request $request,
        WorkSession $workSession,
        BehaviorIdentityService $identity,
        PlanOwnershipService $ownership,
    ) {
        $this->authorizeSession($request, $workSession, $identity, $ownership);
        $workSession->load(['plan', 'task']);

        if ($workSession->status !== 'completed' || ! $workSession->plan) {
            return redirect()->route('home');
        }

        return redirect()
            ->route('plans.review_assistant.show', [
                'plan' => $workSession->plan,
                'work_session_id' => $workSession->id,
            ])
            ->with('status', '作業結果の判断は、共通の計画更新フローへ統合されました。');
    }

    public function interrupt(
        Request $request,
        WorkSession $workSession,
        BehaviorIdentityService $identity,
        BehaviorEventLogger $logger,
        PlanOwnershipService $ownership,
        WorkSessionService $sessions,
        TaskEvidenceService $evidenceService,
    ) {
        $this->authorizeSession($request, $workSession, $identity, $ownership);
        $workSession->load(['plan', 'task']);
        $actorToken = $identity->resolve($request);
        [$finishOptions, $timerMetadata, $durationConfirmed] = $this->resolveTimerAdjustment($request, $workSession);
        $preview = $sessions->previewFinish($workSession, $finishOptions);
        $this->guardLongDuration($workSession, $preview['active_seconds'], $durationConfirmed, $sessions);

        $metrics = DB::transaction(function () use ($request, $workSession, $actorToken, $logger, $sessions, $evidenceService, $finishOptions, $timerMetadata) {
            $metrics = $sessions->finish($workSession, 'interrupted', $finishOptions);
            $metadata = $this->appendTimerMetadata($workSession->metadata, $timerMetadata, $metrics);
            if (($metrics['actual_minutes'] ?? 0) >= 2) {
                $workSession->forceFill([
                    'needs_plan_update' => true,
                    'plan_updated_at' => null,
                    'metadata' => $metadata,
                ])->save();
            } elseif ($timerMetadata !== []) {
                $workSession->forceFill(['metadata' => $metadata])->save();
            }

            if ($workSession->plan && $workSession->task && $metrics['actual_minutes'] >= 2) {
                WorkLog::updateOrCreate(
                    ['work_session_id' => $workSession->id],
                    [
                        'plan_id' => $workSession->plan_id,
                        'task_id' => $workSession->task_id,
                        'task_title_snapshot' => $workSession->task->title,
                        'worked_on' => $metrics['ended_at']->toDateString(),
                        'actual_minutes' => $metrics['actual_minutes'],
                        'progress_delta_percent' => 0,
                        'progress_before_percent' => $workSession->task->progress_percent,
                        'progress_after_percent' => $workSession->task->progress_percent,
                        'remaining_minutes_before' => $workSession->task->remaining_minutes,
                        'remaining_minutes_after' => $workSession->task->remaining_minutes,
                        'difficulty' => 'stuck',
                        'outcome' => '作業を中断',
                    ]
                );
            }

            if ($workSession->task && ($metrics['actual_minutes'] ?? 0) >= 2) {
                $evidenceService->recordFocusSession($workSession, $metrics, 'interrupted');
            }

            $logger->record($actorToken, BehaviorEventType::WorkInterrupted, $request, $workSession->plan, $workSession->task, [
                'work_session_id' => $workSession->id,
                'duration_seconds' => $metrics['active_seconds'],
                'wall_seconds' => $metrics['wall_seconds'],
                'paused_seconds' => $metrics['paused_seconds'],
                'actual_minutes' => $metrics['actual_minutes'],
                'intended_minutes' => $workSession->intended_minutes,
                'timer_adjusted' => $timerMetadata !== [],
                'timer_action' => $timerMetadata['action'] ?? 'normal',
            ]);

            return $metrics;
        });

        return redirect()->route('home')->with('status', "{$metrics['actual_minutes']}分で中断しました。取り組んだ記録は残っています。");
    }

    /** @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: bool} */
    private function resolveTimerAdjustment(Request $request, WorkSession $workSession): array
    {
        $validated = $request->validate([
            'timer_action' => ['nullable', 'in:normal,continued,away_end,manual'],
            'away_started_at' => ['nullable', 'date'],
            'additional_paused_seconds' => ['nullable', 'integer', 'min:0', 'max:31536000'],
            'manual_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'duration_confirmed' => ['nullable', 'boolean'],
        ]);

        $action = $validated['timer_action'] ?? 'normal';
        $additionalPaused = max(0, (int) ($validated['additional_paused_seconds'] ?? 0));
        $options = ['additional_paused_seconds' => $additionalPaused];
        $metadata = [];
        $explicitChoice = in_array($action, ['continued', 'away_end', 'manual'], true);
        $durationConfirmed = $explicitChoice || (bool) ($validated['duration_confirmed'] ?? false);

        if ($additionalPaused > 0) {
            $metadata['reviewed_break_seconds'] = $additionalPaused;
        }

        if ($action === 'away_end') {
            if (empty($validated['away_started_at'])) {
                throw ValidationException::withMessages(['timer' => '離れた時刻を確認できませんでした。もう一度お試しください。']);
            }
            $awayAt = Carbon::parse($validated['away_started_at']);
            if ($awayAt->lt($workSession->started_at) || $awayAt->gt(now())) {
                throw ValidationException::withMessages(['timer' => '離れた時刻が作業時間の範囲外です。']);
            }
            $options['ended_at'] = $awayAt;
            $metadata['away_started_at'] = $awayAt->toIso8601String();
        }

        if ($action === 'manual') {
            $minutes = (int) ($validated['manual_minutes'] ?? 0);
            if ($minutes < 1) {
                throw ValidationException::withMessages(['timer' => '実際の作業時間を入力してください。']);
            }
            $options['manual_active_seconds'] = $minutes * 60;
            $metadata['manual_minutes'] = $minutes;
        }

        if ($action !== 'normal' || $metadata !== []) {
            $metadata['action'] = $action;
            $metadata['adjusted_at'] = now()->toIso8601String();
        }

        return [$options, $metadata, $durationConfirmed];
    }

    private function guardLongDuration(
        WorkSession $workSession,
        int $activeSeconds,
        bool $durationConfirmed,
        WorkSessionService $sessions,
    ): void {
        if ($durationConfirmed || $activeSeconds <= $sessions->durationConfirmationThresholdSeconds($workSession)) {
            return;
        }

        $minutes = max(1, (int) ceil($activeSeconds / 60));
        throw ValidationException::withMessages([
            'timer' => "作業時間が{$minutes}分になっています。終了忘れの可能性があるため、画面の確認から時間を確定してください。",
        ]);
    }

    /** @param array<string,mixed>|null $existing */
    private function appendTimerMetadata(?array $existing, array $adjustment, array $metrics): array
    {
        $metadata = $existing ?? [];
        if ($adjustment === []) {
            return $metadata;
        }

        $history = is_array($metadata['timer_adjustments'] ?? null) ? $metadata['timer_adjustments'] : [];
        $history[] = array_merge($adjustment, [
            'recorded_active_seconds' => $metrics['active_seconds'],
            'recorded_wall_seconds' => $metrics['wall_seconds'],
        ]);
        $metadata['timer_adjustments'] = array_slice($history, -10);

        return $metadata;
    }

    private function authorizeSession(
        Request $request,
        WorkSession $workSession,
        BehaviorIdentityService $identity,
        PlanOwnershipService $ownership,
    ): void {
        $workSession->loadMissing('plan');

        if (! $workSession->plan) {
            abort(403);
        }

        $ownership->authorizeEdit($request, $workSession->plan);

        if (! hash_equals($workSession->actor_token, $identity->resolve($request))) {
            abort(403, '他のメンバーの作業タイマーは操作できません。');
        }
    }
}
