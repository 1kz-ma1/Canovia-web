<?php

namespace App\Services;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\Task;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class BehaviorEventLogger
{
    private const ONCE_SESSION_PREFIX = 'canovia.behavior_once.';
    public function record(
        string $actorToken,
        BehaviorEventType $type,
        Request $request,
        ?Plan $plan = null,
        ?Task $task = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null,
    ): BehaviorEvent {
        return BehaviorEvent::create([
            'actor_token' => $actorToken,
            'event_type' => $type,
            'plan_id' => $plan?->id,
            'task_id' => $task?->id,
            'session_id' => $request->session()->getId(),
            'occurred_at' => $occurredAt ?? now(),
            'metadata' => $this->sanitizeMetadata($metadata),
        ]);
    }

    public function recordOnce(
        string $actorToken,
        BehaviorEventType $type,
        Request $request,
        ?Plan $plan = null,
        ?Task $task = null,
        array $metadata = [],
        int $withinMinutes = 30,
    ): ?BehaviorEvent {
        if ($this->recordedRecentlyInSession($request, $type, $plan, $task, $withinMinutes)) {
            return null;
        }

        $exists = BehaviorEvent::query()
            ->where('actor_token', $actorToken)
            ->where('event_type', $type->value)
            ->where('session_id', $request->session()->getId())
            ->when($plan, fn ($query) => $query->where('plan_id', $plan->id))
            ->when(! $plan, fn ($query) => $query->whereNull('plan_id'))
            ->when($task, fn ($query) => $query->where('task_id', $task->id))
            ->when(! $task, fn ($query) => $query->whereNull('task_id'))
            ->where('occurred_at', '>=', now()->subMinutes($withinMinutes))
            ->exists();

        if ($exists) {
            $this->markRecordedInSession($request, $type, $plan, $task);

            return null;
        }

        $event = $this->record($actorToken, $type, $request, $plan, $task, $metadata);
        $this->markRecordedInSession($request, $type, $plan, $task);

        return $event;
    }

    public function recordSafely(
        string $actorToken,
        BehaviorEventType $type,
        Request $request,
        ?Plan $plan = null,
        ?Task $task = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null,
    ): ?BehaviorEvent {
        $sanitized = $this->sanitizeMetadata($metadata);
        $event = null;
        $persisted = false;

        try {
            $event = $this->record($actorToken, $type, $request, $plan, $task, $sanitized, $occurredAt);
            $persisted = true;
        } catch (Throwable $exception) {
            $this->safeLog('warning', 'behavior_event_persist_failed', [
                'event_type' => $type->value,
                'error_class' => class_basename($exception),
            ]);
        }

        // Render keeps this structured line even if the analytics table is
        // temporarily unavailable. Never include prompt/JSON/user text here.
        $this->safeLog('info', 'canovia_funnel_event', [
            'event_type' => $type->value,
            'actor_ref' => substr(hash('sha256', $actorToken), 0, 16),
            'plan_ref' => $plan ? substr(hash('sha256', 'plan:'.$plan->id), 0, 16) : null,
            'task_ref' => $task ? substr(hash('sha256', 'task:'.$task->id), 0, 16) : null,
            'session_ref' => substr(hash('sha256', $request->session()->getId()), 0, 16),
            'persisted' => $persisted,
            'metadata' => $sanitized,
        ]);

        return $event;
    }

    public function recordOnceSafely(
        string $actorToken,
        BehaviorEventType $type,
        Request $request,
        ?Plan $plan = null,
        ?Task $task = null,
        array $metadata = [],
        int $withinMinutes = 30,
    ): ?BehaviorEvent {
        if ($this->recordedRecentlyInSession($request, $type, $plan, $task, $withinMinutes)) {
            return null;
        }

        try {
            $exists = BehaviorEvent::query()
                ->where('actor_token', $actorToken)
                ->where('event_type', $type->value)
                ->where('session_id', $request->session()->getId())
                ->when($plan, fn ($query) => $query->where('plan_id', $plan->id))
                ->when(! $plan, fn ($query) => $query->whereNull('plan_id'))
                ->when($task, fn ($query) => $query->where('task_id', $task->id))
                ->when(! $task, fn ($query) => $query->whereNull('task_id'))
                ->where('occurred_at', '>=', now()->subMinutes($withinMinutes))
                ->exists();

            if ($exists) {
                $this->markRecordedInSession($request, $type, $plan, $task);

                return null;
            }
        } catch (Throwable $exception) {
            $this->safeLog('warning', 'behavior_event_dedupe_failed', [
                'event_type' => $type->value,
                'error_class' => class_basename($exception),
            ]);
        }

        $event = $this->recordSafely($actorToken, $type, $request, $plan, $task, $metadata);
        if ($event) {
            $this->markRecordedInSession($request, $type, $plan, $task);
        }

        return $event;
    }

    private function recordedRecentlyInSession(
        Request $request,
        BehaviorEventType $type,
        ?Plan $plan,
        ?Task $task,
        int $withinMinutes,
    ): bool {
        $recordedAt = $request->session()->get($this->onceSessionKey($type, $plan, $task));

        if (! is_numeric($recordedAt)) {
            return false;
        }

        $windowSeconds = max(1, $withinMinutes) * 60;

        return ((int) now()->timestamp - (int) $recordedAt) <= $windowSeconds;
    }

    private function markRecordedInSession(
        Request $request,
        BehaviorEventType $type,
        ?Plan $plan,
        ?Task $task,
    ): void {
        $request->session()->put(
            $this->onceSessionKey($type, $plan, $task),
            (int) now()->timestamp,
        );
    }

    private function onceSessionKey(
        BehaviorEventType $type,
        ?Plan $plan,
        ?Task $task,
    ): string {
        $scope = implode('|', [
            $type->value,
            (string) ($plan?->id ?? 0),
            (string) ($task?->id ?? 0),
        ]);

        return self::ONCE_SESSION_PREFIX.hash('sha256', $scope);
    }

    private function safeLog(string $level, string $message, array $context): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (Throwable) {
            // Observability must never become a product outage.
        }
    }

    private function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];

        foreach (array_slice($metadata, 0, 25, true) as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-z0-9_]{1,64}$/', $key)) {
                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = mb_substr($value, 0, 500);
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $sanitized[$key] = $value;
            } elseif (is_array($value)) {
                $sanitized[$key] = array_slice($value, 0, 30);
            }
        }

        return $sanitized;
    }
}
