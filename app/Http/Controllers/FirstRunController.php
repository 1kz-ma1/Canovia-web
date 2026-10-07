<?php

namespace App\Http\Controllers;

use App\Services\FirstRunService;
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

    public function start(Request $request, FirstRunService $firstRun): RedirectResponse
    {
        $firstRun->markPassed($request);

        return redirect()
            ->route('personalization.show', ['source' => 'first_run'])
            ->with('status', 'まず、今進めたいことと現在地を少しだけ教えてください。');
    }
}
