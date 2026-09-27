<?php

namespace App\Http\Controllers;

use App\Models\GoalContext;
use App\Services\BehaviorIdentityService;
use App\Services\GoalContextAccessService;
use App\Services\GoalContextService;
use App\Services\GoalDiscoveryPolicyService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class GoalDiscoveryController extends Controller
{
    public function create()
    {
        return view('goal_discovery.create');
    }

    public function store(
        Request $request,
        BehaviorIdentityService $identity,
        GoalContextService $goalContexts,
    ) {
        $validated = $request->validate([
            'desired_state' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        $context = $goalContexts->createDraft(
            desiredState: $validated['desired_state'],
            userId: $request->user()?->id,
            actorToken: $request->user() ? null : $identity->resolve($request),
        );

        return redirect()
            ->route('goal_discovery.show', $context)
            ->with('status', 'まず、Canoviaが現在地を少しずつ理解していきます。');
    }

    public function show(
        Request $request,
        GoalContext $goalContext,
        BehaviorIdentityService $identity,
        GoalContextAccessService $access,
        GoalContextService $goalContexts,
        GoalDiscoveryPolicyService $policy,
    ) {
        $goalContext = $access->authorize($request, $goalContext, $identity);
        $goalContext = $goalContexts->recalculate($goalContext);
        $snapshot = $goalContexts->snapshot($goalContext);
        $question = $policy->nextQuestion($goalContext);
        $profile = $policy->profile($goalContext);
        $presentationMode = $policy->presentationMode($goalContext);

        return view('goal_discovery.show', compact(
            'goalContext',
            'snapshot',
            'question',
            'profile',
            'presentationMode',
        ));
    }

    public function answer(
        Request $request,
        GoalContext $goalContext,
        BehaviorIdentityService $identity,
        GoalContextAccessService $access,
        GoalDiscoveryPolicyService $policy,
    ) {
        $goalContext = $access->authorize($request, $goalContext, $identity);

        $validated = $request->validate([
            'question_id' => ['required', 'string', 'max:64'],
            'answer_mode' => ['required', 'in:quick,text,skip'],
            'choice' => ['nullable', 'string', 'max:64'],
            'answer_text' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $policy->answer(
                $goalContext,
                questionId: $validated['question_id'],
                mode: $validated['answer_mode'],
                choice: $validated['choice'] ?? null,
                text: $validated['answer_text'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withErrors(['goal_discovery' => $exception->getMessage()])
                ->withInput();
        }

        return redirect()->route('goal_discovery.show', $goalContext);
    }
}
