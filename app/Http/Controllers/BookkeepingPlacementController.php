<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\StudyScoreObservation;
use App\Services\BehaviorIdentityService;
use App\Services\BookkeepingPlacementDiagnosticService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class BookkeepingPlacementController extends Controller
{
    public function show(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity,
        BookkeepingPlacementDiagnosticService $diagnostic,
    ) {
        $ownership->authorizeView($request, $plan);
        $this->authorizeBookkeeping($plan, $profiles);

        $latest = $this->actorObservations($request, $plan, $identity)
            ->latest('observed_at')
            ->latest('id')
            ->first();

        return view('study.bookkeeping-placement', [
            'plan' => $plan,
            'questions' => $diagnostic->questions(),
            'topics' => BookkeepingPlacementDiagnosticService::TOPICS,
            'requestId' => (string) Str::uuid(),
            'canEdit' => $ownership->canEdit($request, $plan),
            'result' => $request->session()->get('bookkeeping.diagnostic.feedback.'.$plan->id),
            'latest' => $latest,
            'latestPlacement' => $latest
                ? $diagnostic->placement(
                    (int) $latest->score_value,
                    (array) $latest->components,
                    (int) data_get($latest->components, '_advance_interest', 0) === 1,
                )
                : null,
        ]);
    }

    public function store(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity,
        BookkeepingPlacementDiagnosticService $diagnostic,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->authorizeBookkeeping($plan, $profiles);

        $rules = [
            'request_id' => ['required', 'uuid'],
            'wants_advance' => ['required', Rule::in(['0', '1'])],
            'answers' => ['required', 'array', 'size:12'],
        ];
        foreach ($diagnostic->questions() as $question) {
            $rules['answers.'.$question['id']] = [
                'required',
                Rule::in(array_keys($question['choices'])),
            ];
        }

        $validated = $request->validate($rules);
        $userId = $request->user()?->id;
        $actorToken = $userId ? null : $identity->resolve($request);
        $existing = StudyScoreObservation::query()
            ->where('request_id', $validated['request_id'])
            ->first();

        if ($existing) {
            abort_unless(
                (int) $existing->plan_id === (int) $plan->id
                && ($userId
                    ? (int) $existing->user_id === (int) $userId
                    : ($existing->user_id === null
                        && $existing->actor_token === $actorToken)),
                409,
            );

            return redirect()->route('plans.bookkeeping_placement.show', $plan)
                ->with('status', 'この診断結果はすでに保存されています。重複登録せず、最新の結果を表示します。');
        }

        $result = $diagnostic->assess(
            $validated['answers'],
            (string) $validated['wants_advance'] === '1',
        );

        StudyScoreObservation::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $userId,
            'actor_token' => $actorToken,
            'request_id' => $validated['request_id'],
            'metric_key' => BookkeepingPlacementDiagnosticService::METRIC,
            'metric_label' => '簿記3級基礎の現在地',
            'score_value' => $result['score_percent'],
            'scale_min' => 0,
            'scale_max' => 100,
            'unit' => 'percent',
            'source_kind' => 'mock_exam',
            'source_label' => BookkeepingPlacementDiagnosticService::SOURCE,
            'components' => [
                ...$result['topic_scores'],
                '_advance_interest' => $result['wants_advance'] ? 1 : 0,
            ],
            'observed_at' => now(),
        ]);

        return redirect()->route('plans.bookkeeping_placement.show', $plan)
            ->with('bookkeeping.diagnostic.feedback.'.$plan->id, $result)
            ->with('status', '簿記の基礎診断を保存しました。今回の結果から復習と先取りの方向を確認できます。');
    }

    private function actorObservations(
        Request $request,
        Plan $plan,
        BehaviorIdentityService $identity,
    ) {
        $query = StudyScoreObservation::query()
            ->where('plan_id', $plan->id)
            ->where('metric_key', BookkeepingPlacementDiagnosticService::METRIC)
            ->where('source_label', BookkeepingPlacementDiagnosticService::SOURCE);

        if ($request->user()) {
            return $query->where('user_id', $request->user()->id);
        }

        return $query->whereNull('user_id')
            ->where('actor_token', $identity->resolve($request));
    }

    private function authorizeBookkeeping(Plan $plan, PlanCategoryProfileService $profiles): void
    {
        abort_unless($profiles->forPlan($plan)->key === 'study', 404);

        $context = $plan->title.' '.($plan->description ?? '');
        abort_unless(str_contains($context, '簿記'), 404);
    }
}
