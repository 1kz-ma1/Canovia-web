<?php

namespace App\Http\Controllers;

use App\Services\CareerExplorationAssessmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class CareerExplorationController extends Controller
{
    public function show(Request $request, CareerExplorationAssessmentService $assessment): View
    {
        $draft = $request->session()->get(CareerExplorationAssessmentService::SESSION_KEY);
        $draft = is_array($draft) ? $draft : [];

        // This key is reserved for a future secure, per-field confirmed PDF
        // import. No unconfirmed parsed text can suppress a user question.
        $external = $request->session()->get('canovia.career.import.confirmed.v1', []);
        $external = is_array($external) ? $external : [];
        $known = $assessment->confirmedCoverage($external);

        return view('career.explore', [
            'questions' => $assessment->remainingQuestions($external),
            'known' => $known,
            'answers' => (array) ($draft['answers'] ?? []),
            'directions' => ($draft['completed'] ?? false) === true
                ? $assessment->explorationDirections((array) ($draft['answers'] ?? []))
                : [],
            'completed' => ($draft['completed'] ?? false) === true,
        ]);
    }

    public function store(
        Request $request,
        CareerExplorationAssessmentService $assessment,
    ): RedirectResponse {
        $external = $request->session()->get('canovia.career.import.confirmed.v1', []);
        $external = is_array($external) ? $external : [];
        $known = $assessment->confirmedCoverage($external);
        $remaining = $assessment->remainingQuestions($external);

        $rules = ['answers' => ['nullable', 'array']];
        foreach ($remaining as $key => $definition) {
            $rules['answers.'.$key] = [
                'required',
                'string',
                Rule::in(array_keys($definition['options'])),
            ];
        }

        $validated = $request->validate($rules);
        $submitted = (array) ($validated['answers'] ?? []);
        $answers = $known;
        foreach (array_keys($remaining) as $key) {
            $answers[$key] = $submitted[$key];
        }

        $request->session()->put(CareerExplorationAssessmentService::SESSION_KEY, [
            'version' => 1,
            'answers' => $answers,
            'completed' => true,
            'completed_at' => now()->toIso8601String(),
        ]);

        return redirect()->route('career.explore.show')
            ->with('status', '回答を保存しました。これは職業適性の判定ではなく、職種を比較する出発点です。');
    }

    public function createPlan(
        Request $request,
        CareerExplorationAssessmentService $assessment,
    ): RedirectResponse {
        $draft = $request->session()->get(CareerExplorationAssessmentService::SESSION_KEY, []);
        abort_unless(
            is_array($draft)
            && ($draft['completed'] ?? false) === true
            && is_array($draft['answers'] ?? null),
            404,
        );

        $answers = (array) $draft['answers'];
        $dimensions = $assessment->questions();
        $labels = [];
        foreach ($dimensions as $key => $question) {
            $value = (string) ($answers[$key] ?? '');
            if (isset($question['options'][$value]) && $value !== 'unsure') {
                $labels[] = $question['label'].'：'.$question['options'][$value];
            }
        }

        $request->session()->put('plan_create_prefill', [
            'title' => '自分に合う仕事を探し、就職活動を進める',
            'description' => "本人が確認した就活の初期希望（今後の経験で更新可能）：\n".implode("\n", $labels),
            'category' => '就活・キャリア',
        ]);

        return redirect()->route('plans.create.manual', [
            'workspace_mode' => 'career',
        ]);
    }
}
