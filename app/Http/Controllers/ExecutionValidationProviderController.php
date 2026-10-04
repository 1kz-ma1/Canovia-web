<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Execution\ExecutionCapability;
use App\Intelligence\Study\StudyAdaptiveActionService;
use App\Models\Task;
use App\Services\ExecutionActivityService;
use App\Services\ExecutionLaunchResolver;
use App\Services\ExecutionResolver;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ExecutionValidationProviderController extends Controller
{
    public function show(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        ExecutionResolver $resolver,
    ) {
        abort_unless(
            (bool) config(
                'canovia.execution_setup_validation_enabled',
                false,
            ),
            404,
        );
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeTask($request, $task);

        $resolution = $resolver->resolve($plan, $task);

        abort_unless(
            $resolution->provider?->key
                === ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
            404,
        );

        return view('execution.validation-study-practice', [
            'plan' => $plan,
            'task' => $task,
            'provider' => $resolution->provider,
            'activityKey' => (string) Str::uuid(),
        ]);
    }

    public function storeStudyPracticeResult(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        ExecutionResolver $resolver,
        ExecutionActivityService $activities,
        StudyAdaptiveActionService $studyActions,
    ) {
        abort_unless(
            (bool) config(
                'canovia.execution_setup_validation_enabled',
                false,
            ),
            404,
        );
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        $ownership->authorizeTask($request, $task);
        abort_unless($request->user() !== null, 403);

        $resolution = $resolver->resolve($plan, $task);

        abort_unless(
            $resolution->provider?->key
                === ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
            404,
        );

        $validated = $request->validate([
            'activity_key' => ['required', 'uuid'],
            'score_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:720'],
            'strengths' => ['nullable', 'string', 'max:2000'],
            'weaknesses' => ['nullable', 'string', 'max:2000'],
        ]);

        $strengths = $this->listInput($validated['strengths'] ?? null);
        $weaknesses = $this->listInput($validated['weaknesses'] ?? null);
        $durationMinutes = isset($validated['duration_minutes'])
            ? (int) $validated['duration_minutes']
            : null;

        $activity = $activities->record(
            providerKey:
                ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
            capability: ExecutionCapability::STUDY_PRACTICE,
            type: 'study_practice_completed',
            title: 'External Practice result',
            status: 'completed',
            metrics: [
                'score_percent' => (int) $validated['score_percent'],
                'strengths' => $strengths,
                'weaknesses' => $weaknesses,
                'weakness_topics' => $weaknesses,
            ],
            metadata: [
                'validation_surface' => true,
            ],
            externalKey: (string) $validated['activity_key'],
            userId: (int) $request->user()->id,
            completedAt: now(),
            durationSeconds: $durationMinutes !== null
                ? $durationMinutes * 60
                : null,
        );

        $linked = $activities->linkToTask($activity, $task);

        $studyActions->tryRefresh($plan, now());

        return redirect()
            ->route('workspace.study.index', ['plan_id' => $plan->id])
            ->with(
                'status',
                'External Practice結果をActivity / Evidenceへ反映しました。'
                .' Task進捗は自動変更していません。',
            )
            ->with('execution_activity_id', (int) $linked->id);
    }

    /**
     * @return array<int,string>
     */
    private function listInput(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return collect(
            preg_split('/[\\r\\n,、]+/u', $value) ?: [],
        )
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->map(fn ($item) => mb_substr($item, 0, 191))
            ->unique()
            ->take(24)
            ->values()
            ->all();
    }
}
