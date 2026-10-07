<?php

namespace App\Http\Controllers;

use App\Enums\ReleaseLevel;
use App\Services\FirstRunService;
use App\Services\ReleaseLevelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class FirstRunController extends Controller
{
    public function show(Request $request, FirstRunService $firstRun): View|RedirectResponse
    {
        if (! $firstRun->requiresGate($request)) {
            return redirect()->route('home');
        }

        return view('first_run.show', [
            'onboardingVersion' => (int) config('canovia.onboarding_version', 1),
        ]);
    }

    public function start(
        Request $request,
        FirstRunService $firstRun,
        ReleaseLevelService $releaseLevels,
    ): RedirectResponse {
        $firstRun->markPassed($request);

        if (
            ! $releaseLevels->allowsMinimum(
                ReleaseLevel::EarlyAccessCore,
                $request->user(),
                $request,
            )
        ) {
            return redirect()
                ->route('plans.create')
                ->with('status', 'まず、今どうしたいかをそのままCanoviaに話してみてください。');
        }

        return redirect()
            ->route('personalization.show', ['source' => 'first_run'])
            ->with('status', 'まず、今進めたいことと現在地を少しだけ教えてください。');
    }
}
