<?php

namespace App\Http\Controllers;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\Task;
use App\Services\BehaviorEventLogger;
use App\Services\BehaviorIdentityService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BehaviorEventController extends Controller
{
    public function store(
        Request $request,
        BehaviorIdentityService $identity,
        BehaviorEventLogger $logger,
        PlanOwnershipService $ownership,
    ) {
        $validated = $request->validate([
            'event_type' => ['required', Rule::in(BehaviorEventType::clientRecordable())],
            'plan_id' => ['nullable', 'integer', 'min:1'],
            'task_id' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
        ]);

        if (strlen(json_encode($validated['metadata'] ?? [])) > 8000) {
            throw ValidationException::withMessages(['metadata' => 'イベント情報が大きすぎます。']);
        }

        $plan = isset($validated['plan_id']) ? Plan::find($validated['plan_id']) : null;
        $task = isset($validated['task_id']) ? Task::with('plan')->find($validated['task_id']) : null;

        if ($plan) {
            $ownership->authorizeView($request, $plan);
        }

        if ($task) {
            $ownership->authorizeTaskView($request, $task);

            if ($plan && $task->plan_id !== $plan->id) {
                throw ValidationException::withMessages(['task_id' => 'TaskとPlanの組み合わせが正しくありません。']);
            }

            $plan ??= $task->plan;
        }

        if ((isset($validated['plan_id']) && ! $plan) || (isset($validated['task_id']) && ! $task)) {
            throw ValidationException::withMessages(['event_type' => '参照先が見つかりません。']);
        }

        $type = BehaviorEventType::from($validated['event_type']);
        $metadata = $validated['metadata'] ?? [];
        $actorToken = $identity->resolve($request);

        if ($type === BehaviorEventType::PlanTabViewed && ! $plan) {
            throw ValidationException::withMessages(['plan_id' => 'Planタブの記録にはPlanが必要です。']);
        }

        if ($type === BehaviorEventType::TaskViewed && ! $task) {
            throw ValidationException::withMessages(['task_id' => 'Task表示の記録にはTaskが必要です。']);
        }

        if ($type === BehaviorEventType::DashboardIdle) {
            $elapsed = (int) data_get($metadata, 'elapsed_seconds', 0);
            $switches = (int) data_get($metadata, 'plan_switches', 0);
            $taskViews = (int) data_get($metadata, 'task_views', 0);
            $visible = filter_var(data_get($metadata, 'page_visible', false), FILTER_VALIDATE_BOOLEAN);
            $workStarted = filter_var(data_get($metadata, 'work_started', false), FILTER_VALIDATE_BOOLEAN);
            $alreadyStartedToday = BehaviorEvent::query()
                ->where('actor_token', $actorToken)
                ->where('event_type', BehaviorEventType::WorkStarted->value)
                ->where('occurred_at', '>=', today())
                ->exists();

            if (! $visible || $workStarted || $alreadyStartedToday || $elapsed < 60 || ($switches + $taskViews) < 2) {
                return response()->noContent();
            }
        }

        $mapClientTypes = [
            BehaviorEventType::MapViewed,
            BehaviorEventType::MapNodeFocused,
            BehaviorEventType::MapBackUsed,
            BehaviorEventType::MapClassicActionOpened,
            BehaviorEventType::MapCompanionOpened,
            BehaviorEventType::MapClassicHomeOpened,
            BehaviorEventType::MapReprojected,
        ];

        if (in_array($type, $mapClientTypes, true)) {
            $flowId = is_string($metadata['flow_id'] ?? null)
                && preg_match('/^[0-9a-f-]{36}$/i', (string) $metadata['flow_id'])
                    ? strtolower((string) $metadata['flow_id'])
                    : null;
            if (! $flowId) {
                throw ValidationException::withMessages(['metadata.flow_id' => 'Map flowを確認してください。']);
            }

            $nodeTypes = [
                'goal',
                'plan',
                'task',
                'tool',
                'evidence',
                'inbox',
                'space_station',
                'intent',
                'domain',
                'intent_context',
                'satellite_plan',
                'satellite_tool',
            ];
            $positionRoles = [
                'future-goal',
                'future-plan',
                'future-next',
                'now',
                'action-tool',
                'past-evidence',
                'input-inbox',
                'space-station',
                'intent-plan',
                'intent-execution',
                'intent-reflection',
                'intent-collaboration',
                'intent-context',
                'hierarchy-parent',
                'hierarchy-child',
                'spatial-dock',
                'satellite-1',
                'satellite-2',
                'satellite-3',
                'satellite-4',
            ];
            $actionRoles = ['primary', 'secondary', 'direct', 'zoom', 'satellite', 'companion', 'home'];

            $safeMetadata = array_filter([
                'flow_id' => $flowId,
                'surface' => in_array(($metadata['surface'] ?? null), ['web', 'pwa'], true)
                    ? $metadata['surface']
                    : 'unknown',
                'device' => in_array(($metadata['device'] ?? null), ['mobile', 'desktop'], true)
                    ? $metadata['device']
                    : 'unknown',
                'node_type' => in_array(($metadata['node_type'] ?? null), $nodeTypes, true)
                    ? $metadata['node_type']
                    : null,
                'position_role' => in_array(($metadata['position_role'] ?? null), $positionRoles, true)
                    ? $metadata['position_role']
                    : null,
                'action_role' => in_array(($metadata['action_role'] ?? null), $actionRoles, true)
                    ? $metadata['action_role']
                    : null,
                'is_primary' => isset($metadata['is_primary'])
                    ? filter_var($metadata['is_primary'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    : null,
                'elapsed_ms' => isset($metadata['elapsed_ms'])
                    ? max(0, min(3600000, (int) $metadata['elapsed_ms']))
                    : null,
                'step_count' => isset($metadata['step_count'])
                    ? max(0, min(100, (int) $metadata['step_count']))
                    : null,
            ], fn ($value) => $value !== null);

            $logger->recordSafely(
                $actorToken,
                $type,
                $request,
                $plan,
                $task,
                $safeMetadata,
            );

            return response()->noContent();
        }

        $funnelClientTypes = [
            BehaviorEventType::PlanGenerationPromptCopyClicked,
            BehaviorEventType::PlanUpdatePromptCopyClicked,
        ];

        if (in_array($type, $funnelClientTypes, true)) {
            // Funnel client events only retain the environment flags required
            // for Web/PWA diagnosis. Ignore arbitrary client metadata so user
            // text can never leak into structured Render logs.
            $safeMetadata = [
                'surface' => in_array(($metadata['surface'] ?? null), ['web', 'pwa'], true)
                    ? $metadata['surface']
                    : 'unknown',
                'device' => in_array(($metadata['device'] ?? null), ['mobile', 'desktop'], true)
                    ? $metadata['device']
                    : 'unknown',
            ];

            $logger->recordOnceSafely(
                $actorToken,
                $type,
                $request,
                $plan,
                $task,
                $safeMetadata,
                withinMinutes: 5,
            );
        } else {
            $logger->recordOnce(
                $actorToken,
                $type,
                $request,
                $plan,
                $task,
                $metadata,
                withinMinutes: $type === BehaviorEventType::DashboardIdle ? 15 : 5,
            );
        }

        return response()->noContent();
    }
}
