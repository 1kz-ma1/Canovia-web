<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\MapPersonalizationPreferenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class MapPersonalizationController extends Controller
{
    public function store(
        Request $request,
        Plan $plan,
        MapPersonalizationPreferenceService $preferences,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless($user && $preferences->canPinPlan($user, $plan), 404);

        $preferences->pinPlan($user, $plan);

        return redirect()
            ->route('map.index')
            ->with('status', 'Shortcutを固定しました。');
    }

    public function destroy(
        Request $request,
        Plan $plan,
        MapPersonalizationPreferenceService $preferences,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless($user && $preferences->ownsPersonalPlan($user, $plan), 404);

        $preferences->unpinPlan($user, $plan);

        return redirect()
            ->route('map.index')
            ->with('status', 'Shortcutの固定を解除しました。');
    }
}
