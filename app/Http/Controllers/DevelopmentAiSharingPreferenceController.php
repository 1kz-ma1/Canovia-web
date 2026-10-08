<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\DevelopmentAiSharingPreferenceService;
use App\Services\PlanCategoryProfileService;
use Illuminate\Http\Request;

final class DevelopmentAiSharingPreferenceController extends Controller
{
    /**
     * Save a future ChatGPT sharing preference — this is NOT OAuth consent,
     * nor does it create an active connection or grant external access.
     */
    public function store(
        Request $request,
        Plan $plan,
        PlanCategoryProfileService $profiles,
        DevelopmentAiSharingPreferenceService $preferences,
    ) {
        $user = $request->user();
        abort_unless(
            $user && $plan->user_id !== null
                && (int) $plan->user_id === (int) $user->id,
            404,
        );
        abort_if((bool) $plan->is_collaborative, 404);
        abort_unless($profiles->forPlan($plan)->key === 'development', 404);

        $validated = $request->validate([
            'scope' => ['required', 'in:overview,tasks'],
            'duration_days' => ['required', 'integer', 'in:1,7,30'],
        ]);

        $preferences->prepare(
            $user,
            $plan,
            (string) $validated['scope'],
            (int) $validated['duration_days'],
        );

        return redirect()->route('workspace.development.index', [
            'plan_id' => $plan->id,
            'surface' => 'work',
        ])->with(
            'success',
            'ChatGPT向けの共有準備設定を保存しました。まだ接続や外部アクセスは許可していません。',
        );
    }

    /**
     * Allow the original owner to cancel even after Plan category/team change.
     */
    public function destroy(
        Request $request,
        Plan $plan,
        DevelopmentAiSharingPreferenceService $preferences,
    ) {
        $user = $request->user();
        abort_unless(
            $user && $plan->user_id !== null
                && (int) $plan->user_id === (int) $user->id,
            404,
        );
        abort_unless($preferences->revoke($user, $plan) !== null, 404);

        return redirect()->route('workspace.development.index', [
            'plan_id' => $plan->id,
            'surface' => 'work',
        ])->with(
            'success',
            'ChatGPT向けの共有準備設定を取り消しました。外部連携は開始されていません。',
        );
    }
}
