<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\StudyScoreObservation;
use App\Services\BehaviorIdentityService;
use App\Services\BookkeepingJournalPracticeService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class BookkeepingJournalPracticeController extends Controller
{
    public function show(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity,
        BookkeepingJournalPracticeService $practice,
    ) {
        $ownership->authorizeView($request, $plan);
        $this->authorizeBookkeeping($plan, $profiles);

        $latest = $this->actorObservations($request, $plan, $identity)
            ->latest('observed_at')
            ->latest('id')
            ->first();

        $result = $request->session()->get('bookkeeping.journal.feedback.'.$plan->id);
        if (! is_array($result)) {
            $result = data_get($latest?->components, 'result', null);
        }

        return view('study.bookkeeping-journal-practice', [
            'plan' => $plan,
            'questions' => $practice->questions(),
            'accounts' => BookkeepingJournalPracticeService::ACCOUNTS,
            'topics' => $practice->topicLabels(),
            'requestId' => (string) Str::uuid(),
            'canEdit' => $ownership->canEdit($request, $plan),
            'practice' => $practice,
            'result' => is_array($result) ? $result : null,
            'latest' => $latest,
        ]);
    }

    public function store(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity,
        BookkeepingJournalPracticeService $practice,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->authorizeBookkeeping($plan, $profiles);

        $rules = [
            'request_id' => ['required', 'uuid'],
            'answers' => ['required', 'array', 'size:4'],
        ];
        foreach ($practice->questions() as $question) {
            $prefix = 'answers.'.$question['id'];
            $rules[$prefix] = ['required', 'array', 'min:1', 'max:4'];
            $rules[$prefix.'.*.side'] = ['nullable', 'string', Rule::in(['debit', 'credit'])];
            $rules[$prefix.'.*.account'] = [
                'nullable', 'string', Rule::in(BookkeepingJournalPracticeService::ACCOUNTS),
            ];
            $rules[$prefix.'.*.amount'] = ['nullable', 'integer', 'between:1,999999999'];
        }

        $validated = $request->validate($rules);
        $userId = $request->user()?->id;
        $actorToken = $userId ? null : $identity->resolve($request);

        $existing = StudyScoreObservation::query()
            ->where('request_id', $validated['request_id'])
            ->first();

        if ($existing) {
            $this->authorizeRecord($existing, $plan, $userId, $actorToken);

            return redirect()->route('plans.bookkeeping_journal.show', $plan)
                ->with('status', 'この仕訳演習はすでに記録されています。重複登録せず、結果を表示しました。');
        }

        $result = $practice->assess((array) $validated['answers']);

        // A score observation is only a dedicated internal practice record,
        // never an external-score baseline or official exam result.
        $saved = StudyScoreObservation::query()->createOrFirst([
            'request_id' => $validated['request_id'],
        ], [
            'plan_id' => $plan->id,
            'user_id' => $userId,
            'actor_token' => $actorToken,
            'metric_key' => BookkeepingJournalPracticeService::METRIC,
            'metric_label' => '簿記仕訳入力演習',
            'score_value' => $result['score_percent'],
            'scale_min' => 0,
            'scale_max' => 100,
            'unit' => 'percent',
            'source_kind' => 'mock_exam',
            'source_label' => BookkeepingJournalPracticeService::SOURCE,
            'components' => [
                'version' => 1,
                'result' => $result,
            ],
            'observed_at' => now(),
        ]);
        $this->authorizeRecord($saved, $plan, $userId, $actorToken);

        return redirect()->route('plans.bookkeeping_journal.show', $plan)
            ->with('bookkeeping.journal.feedback.'.$plan->id, $result)
            ->with('status', '仕訳演習を採点して記録しました。正解例と次の復習候補を確認できます。');
    }

    private function authorizeRecord(
        StudyScoreObservation $record,
        Plan $plan,
        ?int $userId,
        ?string $actorToken,
    ): void {
        abort_unless(
            (int) $record->plan_id === (int) $plan->id
            && $record->metric_key === BookkeepingJournalPracticeService::METRIC
            && ($userId !== null
                ? (int) $record->user_id === $userId
                : ($record->user_id === null
                    && is_string($record->actor_token)
                    && hash_equals($record->actor_token, (string) $actorToken))),
            409,
        );
    }

    private function actorObservations(
        Request $request,
        Plan $plan,
        BehaviorIdentityService $identity,
    ) {
        $query = StudyScoreObservation::query()
            ->where('plan_id', $plan->id)
            ->where('metric_key', BookkeepingJournalPracticeService::METRIC)
            ->where('source_label', BookkeepingJournalPracticeService::SOURCE);

        if ($request->user()) {
            return $query->where('user_id', $request->user()->id);
        }

        return $query->whereNull('user_id')
            ->where('actor_token', $identity->resolve($request));
    }

    private function authorizeBookkeeping(Plan $plan, PlanCategoryProfileService $profiles): void
    {
        abort_unless($profiles->forPlan($plan)->key === 'study', 404);
        abort_unless(
            str_contains($plan->title.' '.($plan->description ?? ''), '簿記'),
            404,
        );
    }
}
