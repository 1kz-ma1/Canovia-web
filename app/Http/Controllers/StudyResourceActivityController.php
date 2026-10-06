<?php

namespace App\Http\Controllers;

use App\Enums\EvidenceSource;
use App\Models\Plan;
use App\Models\PlanResource;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Services\BehaviorIdentityService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\StudyActivityPolicyService;
use App\Services\TaskEvidenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StudyResourceActivityController extends Controller
{
    public function __construct(
        private readonly PlanCategoryProfileService $categoryProfiles,
    ) {}

    public function show(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        StudyActivityPolicyService $activities,
        BehaviorIdentityService $identity,
    ) {
        $this->authorizeTask(
            $request,
            $plan,
            $task,
            $ownership,
        );

        $definition = collect(
            $activities->forPlanTask($plan, $task)['all']
                ?? [],
        )->firstWhere(
            'key',
            StudyActivityPolicyService::RESOURCE_STUDY,
        );

        abort_unless(is_array($definition), 404);

        $resources = $this->resourcesForTask(
            $plan,
            $task,
        );

        $actorToken = $request->user()
            ? null
            : $identity->resolve($request);

        $recentEvidence = $this->evidenceQuery(
            $task,
            $request->user()?->id,
            $actorToken,
        )
            ->latest('occurred_at')
            ->latest('id')
            ->take(5)
            ->get();

        return view('study_resource.show', [
            'plan' => $plan,
            'task' => $task,
            'definition' => $definition,
            'resources' => $resources,
            'recentEvidence' => $recentEvidence,
            'requestUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        StudyActivityPolicyService $activities,
        BehaviorIdentityService $identity,
        TaskEvidenceService $evidence,
    ) {
        $this->authorizeTask(
            $request,
            $plan,
            $task,
            $ownership,
        );

        $definition = collect(
            $activities->forPlanTask($plan, $task)['all']
                ?? [],
        )->firstWhere(
            'key',
            StudyActivityPolicyService::RESOURCE_STUDY,
        );

        abort_unless(is_array($definition), 404);

        $validated = $request->validate([
            'request_uuid' => [
                'required',
                'uuid',
            ],
            'resource_id' => [
                'nullable',
                'integer',
            ],
            'outcome_rating' => [
                'required',
                Rule::in([
                    'needs_review',
                    'partial',
                    'covered',
                ]),
            ],
            'reflection' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $resources = $this->resourcesForTask(
            $plan,
            $task,
        );
        $resource = null;

        if (filled($validated['resource_id'] ?? null)) {
            $resource = $resources->firstWhere(
                'id',
                (int) $validated['resource_id'],
            );

            abort_unless(
                $resource instanceof PlanResource,
                404,
            );
        }

        $actorToken = $request->user()
            ? null
            : $identity->resolve($request);

        $evidence->record(
            $task,
            EvidenceSource::Native,
            'study_resource_study_completed',
            [
                'resource_id' => $resource?->id,
                'resource_title' =>
                    $resource
                        ? mb_substr(
                            trim(
                                (string) $resource->title,
                            ),
                            0,
                            180,
                        )
                        : null,
                'outcome_rating' =>
                    (string) $validated['outcome_rating'],
                'reflection' => filled(
                    $validated['reflection'] ?? null,
                )
                    ? mb_substr(
                        trim(
                            (string) $validated['reflection'],
                        ),
                        0,
                        2000,
                    )
                    : null,
            ],
            confidence: 0.65,
            externalKey:
                'study-resource:'
                .$validated['request_uuid'],
            userId: $request->user()?->id,
            actorToken: $actorToken,
            occurredAt: now(),
        );

        return redirect()
            ->route(
                'plans.tasks.study_resource.show',
                [$plan, $task],
            )
            ->with(
                'success',
                'Resource Studyの実施結果を記録しました。Task進捗は自動変更していません。',
            );
    }

    private function authorizeTask(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
    ): void {
        abort_unless(
            (int) $task->plan_id === (int) $plan->id,
            404,
        );
        abort_unless(
            $this->categoryProfiles->forPlan($plan)->key
                === 'study',
            404,
        );

        $ownership->authorizeTask($request, $task);
    }

    /**
     * @return Collection<int,PlanResource>
     */
    private function resourcesForTask(
        Plan $plan,
        Task $task,
    ): Collection {
        $taskResources = $task
            ->resources()
            ->orderBy('plan_resources.id')
            ->get();

        if ($taskResources->isNotEmpty()) {
            return $taskResources;
        }

        return PlanResource::query()
            ->where('plan_id', $plan->id)
            ->whereDoesntHave('tasks')
            ->orderBy('id')
            ->get();
    }

    private function evidenceQuery(
        Task $task,
        ?int $userId,
        ?string $actorToken,
    ) {
        $query = $task->evidences()
            ->where(
                'type',
                'study_resource_study_completed',
            );

        if ($userId !== null) {
            return $query->where(
                'user_id',
                $userId,
            );
        }

        return $query
            ->whereNull('user_id')
            ->where(
                'actor_token',
                (string) $actorToken,
            );
    }
}
