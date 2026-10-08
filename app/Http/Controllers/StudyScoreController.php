<?php

namespace App\Http\Controllers;

use App\Intelligence\Study\StudyLearningTypeRouter;
use App\Intelligence\Study\StudyScoreScaleService;
use App\Models\Plan;
use App\Models\StudyScoreObservation;
use App\Services\BookkeepingPlacementDiagnosticService;
use App\Services\BehaviorIdentityService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class StudyScoreController extends Controller
{
    public function index(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        StudyLearningTypeRouter $learningTypes,
        StudyScoreScaleService $scales,
        BehaviorIdentityService $identity,
    ) {
        abort_unless($ownership->canView($request, $plan), 404);
        abort_unless($profiles->forPlan($plan)->key === 'study', 404);

        $learningType = $learningTypes->route($plan);
        $profile = $scales->profile($plan, $learningType);
        $actorToken = $request->user()
            ? null
            : $identity->resolve($request);

        $observations = $this->actorQuery(
            $plan,
            $request->user()?->id,
            $actorToken,
        )
            // A Canovia in-app placement result is NOT an external score.
            ->where('metric_key', '!=', BookkeepingPlacementDiagnosticService::METRIC)
            ->latest('observed_at')
            ->latest('id')
            ->take(20)
            ->get();

        return view('study_score.index', [
            'plan' => $plan,
            'learningType' => $learningType,
            'profile' => $profile,
            'observations' => $observations,
            'latestObservation' => $observations->first(),
            'canEdit' => $ownership->canEdit($request, $plan),
            'scoreSources' => StudyScoreObservation::SOURCES,
            'captureRequestId' => (string) Str::uuid(),
        ]);
    }

    public function store(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        StudyLearningTypeRouter $learningTypes,
        StudyScoreScaleService $scales,
        BehaviorIdentityService $identity,
    ) {
        $ownership->authorizeEdit($request, $plan);
        abort_unless($profiles->forPlan($plan)->key === 'study', 404);

        $validated = $request->validate([
            'request_id' => ['required', 'uuid'],
            'score_value' => ['required', 'numeric'],
            'source_kind' => [
                'required',
                Rule::in(array_keys(StudyScoreObservation::SOURCES)),
            ],
            'source_label' => ['nullable', 'string', 'max:120'],
            'observed_at' => [
                'required',
                'date',
                'before_or_equal:today',
            ],
            'components' => ['nullable', 'array'],
            'components.*' => ['nullable', 'numeric'],
        ]);

        $userId = $request->user()?->id;
        $actorToken = $request->user()
            ? null
            : $identity->resolve($request);

        $existing = StudyScoreObservation::query()
            ->where('request_id', $validated['request_id'])
            ->first();

        if ($existing instanceof StudyScoreObservation) {
            abort_unless(
                (int) $existing->plan_id === (int) $plan->id
                && $this->belongsToActor(
                    $existing,
                    $userId,
                    $actorToken,
                ),
                409,
            );

            return redirect()
                ->route('plans.study_scores.index', $plan)
                ->with('status', 'このスコアはすでに記録されています。');
        }

        $learningType = $learningTypes->route($plan);
        $profile = $scales->profile($plan, $learningType);
        $score = (float) $validated['score_value'];

        $scales->validateScore($score, $profile);

        $components = $scales->validateAndNormalizeComponents(
            $profile,
            is_array($validated['components'] ?? null)
                ? $validated['components']
                : [],
        );

        StudyScoreObservation::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $userId,
            'actor_token' => $userId ? null : $actorToken,
            'request_id' => $validated['request_id'],
            'metric_key' => $profile['metric_key'],
            'metric_label' => $profile['label'],
            'score_value' => $score,
            'scale_min' => $profile['min'],
            'scale_max' => $profile['max'],
            'unit' => $profile['unit'],
            'source_kind' => $validated['source_kind'],
            'source_label' => filled($validated['source_label'] ?? null)
                ? trim((string) $validated['source_label'])
                : null,
            'components' => $components,
            'observed_at' => Carbon::parse(
                $validated['observed_at'],
            )->startOfDay(),
        ]);

        return redirect()
            ->route('plans.study_scores.index', $plan)
            ->with('success', '学習スコアをEvidenceとして記録しました。');
    }

    public function destroy(
        Request $request,
        Plan $plan,
        StudyScoreObservation $studyScoreObservation,
        PlanOwnershipService $ownership,
        BehaviorIdentityService $identity,
    ) {
        $ownership->authorizeEdit($request, $plan);
        abort_unless(
            (int) $studyScoreObservation->plan_id === (int) $plan->id,
            404,
        );

        $userId = $request->user()?->id;
        $actorToken = $request->user()
            ? null
            : $identity->resolve($request);

        abort_unless(
            $this->belongsToActor(
                $studyScoreObservation,
                $userId,
                $actorToken,
            ),
            404,
        );

        $studyScoreObservation->delete();

        return redirect()
            ->route('plans.study_scores.index', $plan)
            ->with('success', 'スコア記録を削除しました。');
    }

    private function actorQuery(
        Plan $plan,
        ?int $userId,
        ?string $actorToken,
    ): Builder {
        $query = StudyScoreObservation::query()
            ->where('plan_id', $plan->id);

        if ($userId !== null) {
            return $query->where('user_id', $userId);
        }

        return $query
            ->whereNull('user_id')
            ->where('actor_token', (string) $actorToken);
    }

    private function belongsToActor(
        StudyScoreObservation $observation,
        ?int $userId,
        ?string $actorToken,
    ): bool {
        if ($userId !== null) {
            return (int) $observation->user_id === $userId;
        }

        return $observation->user_id === null
            && hash_equals(
                (string) $observation->actor_token,
                (string) $actorToken,
            );
    }
}
