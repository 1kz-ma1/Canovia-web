<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PrelaunchInterestContextService;
use App\Services\ReleaseLevelService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Authenticated preparation-only surface; no User/Plan identifier is accepted. */
final class PrelaunchInterestController extends Controller
{
    private function owner(Request $request): User
    {
        abort_unless(app(ReleaseLevelService::class)->preparationOnly(), 404);

        return $request->user();
    }

    public function show(Request $request, PrelaunchInterestContextService $contexts)
    {
        return response()->json($contexts->read($this->owner($request)));
    }

    public function result(Request $request, PrelaunchInterestContextService $contexts)
    {
        return response()->json($contexts->read($this->owner($request)));
    }

    public function store(Request $request, PrelaunchInterestContextService $contexts)
    {
        $user = $this->owner($request);
        $request->merge(['answers' => $request->input('answers', [])]);
        $data = $request->validate($this->identityRules() + [
            'action' => ['required', Rule::in(['save', 'result'])],
            'save_consent' => ['required', 'accepted'],
            'step' => ['required', 'integer', 'between:1,6'],
            'answers' => ['present', 'array:'.implode(',', array_keys(config('prelaunch_interest_diagnostic.items'))), 'max:30'],
            'answers.*' => ['required', function ($attribute, $value, $fail): void {
                if (! in_array($value, [0, 1, 2, 3, '0', '1', '2', '3', 'unknown'], true)) {
                    $fail('回答は表示された選択肢から選んでください。');
                }
            }],
        ]);
        $answers = array_map(fn ($value) => $value === 'unknown' ? 'unknown' : (int) $value, $data['answers']);
        $saved = $contexts->saveAnswers($user, $data['revision'], $answers, (int) $data['step'], $data['action'] === 'result');

        return response()->json(['saved' => true, 'revision' => $saved['revision'],
            'redirect' => route($data['action'] === 'result' ? 'preparation.interests.result' : 'preparation.interests.show')]);
    }

    public function review(Request $request, PrelaunchInterestContextService $contexts)
    {
        $user = $this->owner($request);
        $data = $request->validate($this->identityRules() + [
            'confirmed_categories' => ['sometimes', 'array', 'max:8'],
            'confirmed_categories.*' => ['distinct', Rule::in(array_keys(config('prelaunch_interest_diagnostic.categories')))],
            'requested_support' => ['sometimes', 'array', 'max:8'],
            'requested_support.*' => ['distinct', Rule::in(array_keys(config('prelaunch_interest_diagnostic.categories')))],
        ]);
        $saved = $contexts->saveReview($user, $data['revision'], $data['confirmed_categories'] ?? [], $data['requested_support'] ?? []);

        return response()->json(['saved' => true, 'revision' => $saved['revision'], 'redirect' => route('preparation.interests.result')]);
    }

    public function destroy(Request $request, PrelaunchInterestContextService $contexts)
    {
        $user = $this->owner($request);
        $data = $request->validate($this->identityRules() + ['confirm_delete' => ['required', 'accepted']]);
        $contexts->delete($user, $data['revision']);

        return response()->json(['saved' => true, 'redirect' => route('preparation.interests.show')]);
    }

    private function identityRules(): array
    {
        return ['revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'user_id' => ['prohibited'], 'plan_id' => ['prohibited'], 'scores' => ['prohibited'],
            'analytics_consent' => ['prohibited']];
    }
}
