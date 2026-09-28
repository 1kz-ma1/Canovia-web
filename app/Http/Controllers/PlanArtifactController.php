<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Services\BehaviorIdentityService;
use App\Services\PlanActivityService;
use App\Services\PlanOwnershipService;
use App\Services\TaskEvidenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlanArtifactController extends Controller
{
    public function index(Request $request, Plan $plan, PlanOwnershipService $ownership)
    {
        $ownership->authorizeView($request, $plan);

        $plan->load([
            'user:id,name,email',
            'memberships.user:id,name,email',
            'tasks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'artifacts' => fn ($query) => $query
                ->with(['assignedUser:id,name', 'tasks:id,title'])
                ->latest('updated_at')
                ->latest('id'),
        ]);

        $canEdit = $ownership->canEdit($request, $plan);
        $assignees = $this->assigneesFor($plan);
        $providers = PlanArtifact::PROVIDERS;
        $artifactTypes = PlanArtifact::ARTIFACT_TYPES;
        $collaborationStates = PlanArtifact::COLLABORATION_STATES;

        return view('artifacts.index', compact(
            'plan',
            'canEdit',
            'assignees',
            'providers',
            'artifactTypes',
            'collaborationStates',
        ));
    }

    public function store(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanActivityService $activity,
        BehaviorIdentityService $identity,
        TaskEvidenceService $evidenceService,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $plan->loadMissing(['user:id,name,email', 'memberships.user:id,name,email']);
        $validated = $this->validateArtifact($request, $plan);

        $artifact = $plan->artifacts()->create([
            'created_by_user_id' => $request->user()?->id,
            'assigned_user_id' => $validated['assigned_user_id'] ?? null,
            'provider' => $validated['provider'],
            'artifact_type' => $validated['artifact_type'],
            'title' => $validated['title'],
            'url' => $validated['url'],
            'version_label' => $validated['version_label'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'metadata' => $this->collaborationMetadata($plan, $validated),
        ]);

        $artifact->tasks()->sync($this->taskIds($validated));
        $artifact->load('tasks');

        $activity->record($plan, $request->user(), 'artifact_created', 'plan_artifact', (int) $artifact->id, [
            'artifact_title' => $artifact->title,
        ]);
        $evidenceService->recordArtifactState(
            $artifact,
            'created',
            userId: $request->user()?->id,
            actorToken: $identity->resolve($request),
        );

        return redirect()->route('plans.artifacts.index', $plan)
            ->with('success', '制作ファイルを追加しました。');
    }

    public function update(
        Request $request,
        Plan $plan,
        PlanArtifact $artifact,
        PlanOwnershipService $ownership,
        PlanActivityService $activity,
        BehaviorIdentityService $identity,
        TaskEvidenceService $evidenceService,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->ensureArtifactBelongsToPlan($plan, $artifact);
        $plan->loadMissing(['user:id,name,email', 'memberships.user:id,name,email']);
        $validated = $this->validateArtifact($request, $plan);

        $artifact->update([
            'assigned_user_id' => $validated['assigned_user_id'] ?? null,
            'provider' => $validated['provider'],
            'artifact_type' => $validated['artifact_type'],
            'title' => $validated['title'],
            'url' => $validated['url'],
            'version_label' => $validated['version_label'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'metadata' => $this->collaborationMetadata($plan, $validated, (array) ($artifact->metadata ?? [])),
        ]);
        $artifact->tasks()->sync($this->taskIds($validated));
        $artifact->load('tasks');

        $activity->record($plan, $request->user(), 'artifact_updated', 'plan_artifact', (int) $artifact->id, [
            'artifact_title' => $artifact->title,
        ]);
        $evidenceService->recordArtifactState(
            $artifact,
            'updated',
            userId: $request->user()?->id,
            actorToken: $identity->resolve($request),
        );

        return redirect()->route('plans.artifacts.index', $plan)
            ->with('success', '制作ファイルを更新しました。');
    }

    public function destroy(
        Request $request,
        Plan $plan,
        PlanArtifact $artifact,
        PlanOwnershipService $ownership,
        PlanActivityService $activity,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->ensureArtifactBelongsToPlan($plan, $artifact);

        $title = $artifact->title;
        $id = (int) $artifact->id;
        $artifact->delete();

        $activity->record($plan, $request->user(), 'artifact_deleted', 'plan_artifact', $id, [
            'artifact_title' => $title,
        ]);

        return redirect()->route('plans.artifacts.index', $plan)
            ->with('success', '制作ファイルを削除しました。');
    }

    private function validateArtifact(Request $request, Plan $plan): array
    {
        $assigneeIds = $this->assigneesFor($plan)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $validated = $request->validate([
            'provider' => ['required', Rule::in(array_keys(PlanArtifact::PROVIDERS))],
            'artifact_type' => ['required', Rule::in(array_keys(PlanArtifact::ARTIFACT_TYPES))],
            'title' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048', 'url', 'starts_with:http://,https://'],
            'version_label' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'assigned_user_id' => ['nullable', 'integer'],
            'collaboration_state' => [
                'nullable',
                Rule::in(array_keys(PlanArtifact::COLLABORATION_STATES)),
            ],
            'task_ids' => ['nullable', 'array', 'max:100'],
            'task_ids.*' => [
                'integer',
                Rule::exists('tasks', 'id')->where(fn ($query) => $query->where('plan_id', $plan->id)),
            ],
        ]);

        if (! empty($validated['assigned_user_id']) && ! in_array((int) $validated['assigned_user_id'], $assigneeIds, true)) {
            throw ValidationException::withMessages([
                'assigned_user_id' => 'この計画の参加者を担当者に選んでください。',
            ]);
        }

        return $validated;
    }

    /**
     * @param array<string,mixed> $validated
     * @param array<string,mixed> $existing
     * @return array<string,mixed>|null
     */
    private function collaborationMetadata(Plan $plan, array $validated, array $existing = []): ?array
    {
        if (! $plan->is_collaborative) {
            return $existing === [] ? null : $existing;
        }

        $state = $validated['collaboration_state'] ?? null;
        if (is_string($state) && array_key_exists($state, PlanArtifact::COLLABORATION_STATES)) {
            $existing['collaboration_state'] = $state;
        } else {
            unset($existing['collaboration_state']);
        }

        return $existing === [] ? null : $existing;
    }

    /** @return array<int> */
    private function taskIds(array $validated): array
    {
        return collect($validated['task_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function assigneesFor(Plan $plan): Collection
    {
        $people = collect();

        if ($plan->user) {
            $people->push($plan->user);
        }

        if ($plan->is_collaborative) {
            $people = $people->merge($plan->memberships->pluck('user')->filter());
        }

        return $people->unique('id')->values();
    }

    private function ensureArtifactBelongsToPlan(Plan $plan, PlanArtifact $artifact): void
    {
        if ((int) $artifact->plan_id !== (int) $plan->id) {
            abort(404);
        }
    }
}
