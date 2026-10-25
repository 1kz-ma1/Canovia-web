<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PrelaunchInterestContextService;
use App\Services\PublicRoadmapProjectionService;
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
        return $this->display($request, $contexts, 'preparation.interests');
    }

    public function result(Request $request, PrelaunchInterestContextService $contexts)
    {
        return $this->display($request, $contexts, 'preparation.interest-result');
    }

    private function display(Request $request, PrelaunchInterestContextService $contexts, string $view)
    {
        $data = $contexts->read($this->owner($request));
        if ($request->expectsJson()) {
            return response()->json($data);
        }
        $data['bank'] = config('prelaunch_interest_diagnostic');
        if ($view === 'preparation.interest-result' && $data['record'] === null && ! $data['unsupported']) {
            return redirect()->route('preparation.interests.show');
        }

        $areas = ['全般'];
        $record = $data['record'];
        $interests = $record['confirmed_categories'] ?? [];
        if ($interests === [] && $record !== null && ! isset($record['reviewed_at'])) {
            $ranked = $record['result']['categories'];
            uasort($ranked, fn ($a, $b) => ($b['share_percent'] ?? -1) <=> ($a['share_percent'] ?? -1));
            $interests = array_slice(array_keys(array_filter($ranked, fn ($c) => ($c['share_percent'] ?? 0) > 0)), 0, 2);
        }
        if (in_array('DV', $interests, true)) {
            $areas[] = '開発';
        }
        $data['suggestedRoadmap'] = collect(app(PublicRoadmapProjectionService::class)->build())
            ->flatMap(fn ($section) => $section['items'])->filter(fn ($item) => in_array($item['area'], $areas, true))->values();

        return view($view, $data);
    }

    private function saved(Request $request, string $destination, ?string $revision = null)
    {
        if ($request->expectsJson()) {
            return response()->json(['saved' => true, 'revision' => $revision, 'redirect' => route($destination)]);
        }

        return redirect()->route($destination)->with('status', '保存しました。');
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

        return $this->saved($request, $data['action'] === 'result' ? 'preparation.interests.result' : 'preparation.interests.show', $saved['revision']);
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

        return $this->saved($request, 'preparation.interests.result', $saved['revision']);
    }

    public function destroy(Request $request, PrelaunchInterestContextService $contexts)
    {
        $user = $this->owner($request);
        $data = $request->validate($this->identityRules() + ['confirm_delete' => ['required', 'accepted']]);
        $contexts->delete($user, $data['revision']);

        return $this->saved($request, 'preparation.interests.show');
    }

    private function identityRules(): array
    {
        return ['revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'user_id' => ['prohibited'], 'plan_id' => ['prohibited'], 'scores' => ['prohibited'],
            'analytics_consent' => ['prohibited']];
    }
}
