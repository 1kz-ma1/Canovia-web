<?php

namespace App\Http\Controllers;

use App\Enums\ReleaseLevel;
use App\Services\FirstRunService;
use App\Services\ReleaseLevelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class FirstRunController extends Controller
{
    public function show(
        Request $request,
        FirstRunService $firstRun,
        ReleaseLevelService $releaseLevels,
    ): View|RedirectResponse
    {
        if (! $firstRun->requiresGate($request)) {
            return redirect()->route('home');
        }

        return view('first_run.show', [
            'onboardingVersion' => (int) config('canovia.onboarding_version', 1),
            'canChooseEntry' => $releaseLevels->allowsMinimum(
                ReleaseLevel::EarlyAccessCore,
                $request->user(),
                $request,
            ),
        ]);
    }

    public function start(
        Request $request,
        FirstRunService $firstRun,
        ReleaseLevelService $releaseLevels,
    ): RedirectResponse {
        // Keep the old no-payload POST as "diagnosis" for cached welcome
        // pages and already shipped webviews. Reject unknown choices
        // BEFORE writing the durable first-run pass.
        $validated = $request->validate([
            'entry' => ['nullable', 'string', Rule::in([
                'diagnosis', 'conversation', 'browse',
            ])],
        ]);
        $entry = (string) ($validated['entry'] ?? 'diagnosis');

        $canChooseEntry = $releaseLevels->allowsMinimum(
            ReleaseLevel::EarlyAccessCore,
            $request->user(),
            $request,
        );

        $firstRun->markPassed($request);

        if (! $canChooseEntry) {
            // L0 retains its existing, permitted core entry. An injected
            // "browse" or "diagnosis" value cannot bypass the release gate.
            return redirect()
                ->route('plans.create')
                ->with('status', 'まず、今どうしたいかをそのままCanoviaに話してみてください。');
        }

        return match ($entry) {
            'conversation' => redirect()
                ->route('plans.create')
                ->with('status', 'やりたいことが決まっていなくても大丈夫です。まずは話して整理しましょう。'),
            'browse' => redirect()
                ->route('workspace.overview.index')
                ->with('status', '最初のPlanを作らずにCanoviaを見られます。診断や相談はあとから選べます。'),
            default => redirect()
                ->route('personalization.show', ['source' => 'first_run'])
                ->with('status', 'まず、今進めたいことと現在地を少しだけ教えてください。'),
        };
    }
}
