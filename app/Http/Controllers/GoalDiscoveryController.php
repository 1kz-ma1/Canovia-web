<?php

namespace App\Http\Controllers;

use App\Models\GoalContext;
use App\Services\BehaviorIdentityService;
use App\Services\GoalContextAccessService;
use App\Services\GoalContextService;
use App\Services\GoalDiscoveryConversationService;
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
        GoalDiscoveryConversationService $conversation,
    ) {
        $validated = $request->validate([
            'desired_state' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        $context = $goalContexts->createDraft(
            desiredState: $validated['desired_state'],
            userId: $request->user()?->id,
            actorToken: $request->user() ? null : $identity->resolve($request),
        );

        $conversation->begin($request, $context, $validated['desired_state']);

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
        GoalDiscoveryConversationService $conversation,
    ) {
        $goalContext = $access->authorize($request, $goalContext, $identity);
        $goalContext = $goalContexts->recalculate($goalContext);
        $snapshot = $goalContexts->snapshot($goalContext);
        $question = $policy->nextQuestion($goalContext);
        $profile = $policy->profile($goalContext);
        $presentationMode = $policy->presentationMode($goalContext);

        $messages = $conversation->messages($goalContext);

        return view('goal_discovery.show', compact(
            'goalContext',
            'snapshot',
            'question',
            'profile',
            'presentationMode',
            'messages',
        ));
    }

    public function answer(
        Request $request,
        GoalContext $goalContext,
        BehaviorIdentityService $identity,
        GoalContextAccessService $access,
        GoalDiscoveryPolicyService $policy,
        GoalDiscoveryConversationService $conversation,
    ) {
        $goalContext = $access->authorize($request, $goalContext, $identity);

        $validated = $request->validate([
            'question_id' => ['required', 'string', 'max:64'],
            'answer_mode' => ['required', 'in:quick,text,skip'],
            'choice' => ['nullable', 'string', 'max:64'],
            'answer_text' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $goalContext = $policy->answer(
                $goalContext,
                questionId: $validated['question_id'],
                mode: $validated['answer_mode'],
                choice: $validated['choice'] ?? null,
                text: $validated['answer_text'] ?? null,
            );

            $conversation->afterAnswer(
                $request,
                $goalContext,
                $this->answerDisplayText(
                    $validated['question_id'],
                    $validated['answer_mode'],
                    $validated['choice'] ?? null,
                    $validated['answer_text'] ?? null,
                ),
                $validated['question_id'],
            );
        } catch (InvalidArgumentException $exception) {
            return back()
                ->withErrors(['goal_discovery' => $exception->getMessage()])
                ->withInput();
        }

        return redirect()->route('goal_discovery.show', $goalContext);
    }

    private function answerDisplayText(
        string $questionId,
        string $mode,
        ?string $choice,
        ?string $text,
    ): string {
        if ($mode === 'text') {
            return trim((string) $text);
        }

        if ($mode === 'skip') {
            return '今は分からない / 飛ばす';
        }

        $labels = [
            'current_state' => [
                'starting' => 'これから始める',
                'returning' => '前にやっていて、また始めたい',
                'active' => '今も取り組んでいる',
                'measurable' => '実績・数値で説明できる',
            ],
            'success_signal_style' => [
                'numeric' => '数値で決まっている',
                'capability' => 'できるようになりたいことがある',
                'result' => '結果・評価で決まる',
                'unknown' => 'まだ分からない',
            ],
            'constraints' => [
                'none' => '今のところ特にない',
                'time' => '使える時間に制約がある',
                'deadline' => '期限が決まっている',
                'money' => 'お金・予算に制約がある',
                'environment' => '場所・環境に制約がある',
            ],
            'measurement' => [
                'metric' => '回数・成功率などの数値',
                'artifact' => '写真・動画・成果物',
                'external' => '第三者の反応',
                'reflection' => '自分の振り返り',
                'unknown' => 'まだ分からない',
            ],
        ];

        return $labels[$questionId][$choice ?? ''] ?? trim((string) $choice);
    }
}
