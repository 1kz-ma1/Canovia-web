<?php

namespace App\Http\Controllers;

use App\Models\PlanArtifact;
use App\Models\Task;
use App\Services\BehaviorIdentityService;
use App\Services\GitHubWorkflowService;
use App\Services\PlanActivityService;
use App\Services\PlanOwnershipService;
use App\Services\TaskEvidenceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class GitHubWorkflowController extends Controller
{
    public function index(
        Request $request,
        GitHubWorkflowService $workflow,
    ) {
        return view('github_workflow.index', $workflow->dashboard($request));
    }

    public function store(
        Request $request,
        GitHubWorkflowService $workflow,
        PlanOwnershipService $ownership,
        PlanActivityService $activity,
        BehaviorIdentityService $identity,
        TaskEvidenceService $evidence,
    ) {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'min:1'],
            'url' => ['required', 'url', 'max:2048'],
            'title' => ['nullable', 'string', 'max:255'],
            'workflow_state' => [
                'required',
                Rule::in(array_keys(PlanArtifact::GITHUB_WORKFLOW_STATES)),
            ],
            'task_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $planId = (int) $validated['plan_id'];
        $plan = $ownership->ownedPlans($request, [
            'tasks',
            'user:id,name,email',
            'memberships.user:id,name,email',
        ])->firstWhere('id', $planId);

        if (! $plan || ! $ownership->canEdit($request, $plan)) {
            throw ValidationException::withMessages([
                'plan_id' => 'このPlanへGitHub項目を追加する権限がありません。',
            ]);
        }

        $parsed = $workflow->parseUrl((string) $validated['url']);
        if (! ($parsed['valid'] ?? false)) {
            throw ValidationException::withMessages([
                'url' => 'github.com のRepository / PR / Issue等のURLを貼ってください。',
            ]);
        }

        $task = null;
        if (! empty($validated['task_id'])) {
            $task = $plan->tasks->firstWhere('id', (int) $validated['task_id']);
            if (! $task instanceof Task) {
                throw ValidationException::withMessages([
                    'task_id' => '選択したPlanのTaskを選んでください。',
                ]);
            }
        }

        $title = trim((string) ($validated['title'] ?? ''));
        if ($title === '') {
            $title = $workflow->suggestedTitle((string) $validated['url']);
        }

        $artifact = $plan->artifacts()->create([
            'created_by_user_id' => $request->user()?->id,
            'assigned_user_id' => null,
            'provider' => 'github',
            'artifact_type' => ($parsed['kind'] ?? null) === 'repository' ? 'repository' : 'link',
            'title' => $title,
            'url' => (string) $validated['url'],
            'metadata' => [
                'github_workflow_state' => (string) $validated['workflow_state'],
            ],
        ]);

        if ($task) {
            $artifact->tasks()->sync([(int) $task->id]);
        }

        $artifact->load('tasks');

        $activity->record(
            $plan,
            $request->user(),
            'artifact_created',
            'plan_artifact',
            (int) $artifact->id,
            ['artifact_title' => $artifact->title],
        );

        $evidence->recordArtifactState(
            $artifact,
            'created',
            userId: $request->user()?->id,
            actorToken: $identity->resolve($request),
        );

        return redirect()
            ->route('github_workflow.index', ['plan_id' => $plan->id])
            ->with('success', 'GitHub項目をCanoviaへ追加しました。');
    }

    public function updateState(
        Request $request,
        PlanArtifact $artifact,
        PlanOwnershipService $ownership,
        PlanActivityService $activity,
    ) {
        $artifact->loadMissing('plan');
        $plan = $artifact->plan;

        abort_unless($plan && $artifact->provider === 'github', 404);
        $ownership->authorizeEdit($request, $plan);

        $validated = $request->validate([
            'workflow_state' => [
                'nullable',
                Rule::in(array_keys(PlanArtifact::GITHUB_WORKFLOW_STATES)),
            ],
        ]);

        $oldState = $artifact->githubWorkflowState();
        $newState = isset($validated['workflow_state'])
            ? trim((string) $validated['workflow_state'])
            : '';

        $metadata = is_array($artifact->metadata) ? $artifact->metadata : [];

        if ($newState !== '') {
            $metadata['github_workflow_state'] = $newState;
        } else {
            unset($metadata['github_workflow_state']);
        }

        $artifact->update([
            'metadata' => $metadata === [] ? null : $metadata,
        ]);

        $activity->record(
            $plan,
            $request->user(),
            'github_workflow_state_updated',
            'plan_artifact',
            (int) $artifact->id,
            [
                'from' => $oldState,
                'to' => $newState !== '' ? $newState : null,
            ],
        );

        return redirect()
            ->to($this->safeReturnUrl($request, $plan->id))
            ->with('success', 'GitHub項目のCanovia状態を更新しました。');
    }

    private function safeReturnUrl(Request $request, int $planId): string
    {
        $selectedPlanId = max(0, (int) $request->input('return_plan_id', 0));

        return route(
            'github_workflow.index',
            $selectedPlanId === $planId ? ['plan_id' => $planId] : [],
        );
    }
}
