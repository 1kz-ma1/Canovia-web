<?php

namespace App\Http\Controllers;

use App\Models\LearningContextChangeRecord;
use App\Models\LearningContextWatchState;
use App\Models\Plan;
use App\Models\User;
use App\Services\LearningContextWatchPersistenceService;
use App\Services\PlanCategoryProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Response;

/**
 * Owner-facing opt-in / erase / explicitly requested one-shot comparison.
 * No scheduler, queue, notification, MCP API, or cross-Plan access.
 */
final class LearningContextWatchSettingsController extends Controller
{
    public function show(
        Request $request,
        Plan $plan,
        PlanCategoryProfileService $profiles,
    ): Response {
        $owner = $this->owner($request, $plan);
        $available = $this->available();
        $state = $this->state($owner, $plan);

        // Still allow data erasure if the feature has been disabled or the
        // Plan category/privacy changed since the original consent.
        $eligible = $this->eligible($plan, $profiles);
        abort_unless(($available && $eligible) || $state !== null, 404);
        $candidateCount = $state === null ? 0 : LearningContextChangeRecord::query()
            ->where('user_id', $owner->id)
            ->where('plan_id', $plan->id)
            ->where('status', 'review_only')
            ->where('expires_at', '>', now())
            ->count();

        return response()->view('workspace.study.context-watch-settings', [
            'plan' => $plan,
            'enabledForNewConsent' => $available && $eligible,
            'isActive' => $state !== null && $state->status === 'enabled',
            'consentedAt' => $state?->consented_at,
            'candidateCount' => min($candidateCount, 32),
            'canRefresh' => $available && $eligible && $state?->status === 'enabled',
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(
        Request $request,
        Plan $plan,
        PlanCategoryProfileService $profiles,
        LearningContextWatchPersistenceService $watch,
    ): RedirectResponse {
        $owner = $this->owner($request, $plan);
        abort_unless($this->available() && $this->eligible($plan, $profiles), 404);

        $request->validate(['consent' => ['required', 'accepted']]);
        // An accidental second POST must not erase history or reset the
        // baseline; a separate future reconfirm action can support reset.
        if ($this->state($owner, $plan) !== null) {
            return $this->back($plan)->with('success', 'このPlanの比較記録はすでに有効です。');
        }

        if (! $watch->optIn($owner, $plan->id)) {
            return $this->back($plan)->with('error', '状態の保存を開始できませんでした。設定とPlanの状態をご確認ください。');
        }

        return $this->back($plan)->with('success', 'このPlanの比較記録を有効にしました。通知や自動チェックはまだ行われません。');
    }

    public function refresh(
        Request $request,
        Plan $plan,
        PlanCategoryProfileService $profiles,
        LearningContextWatchPersistenceService $watch,
    ): RedirectResponse {
        $owner = $this->owner($request, $plan);
        abort_unless($this->available() && $this->eligible($plan, $profiles), 404);
        abort_unless($this->state($owner, $plan)?->status === 'enabled', 404);

        $outcome = $watch->advance($owner, $plan->id);
        $status = $outcome['status'] ?? '';
        $messages = [
            'unchanged' => '元データに変化はありませんでした。通知は行っていません。',
            'updated_no_candidate' => '状態を更新しました。対応を要する変化候補はありません。',
            'recorded_review_candidate' => '変化を検出し、確認用の記録を保存しました。通知は行っていません。',
            'duplicate_suppressed' => '以前と同じ変化のため、重複記録を抑止しました。',
            'rebaseline_required' => 'Planの情報が大きく変更されました。比較を再開するにはいったんOFFにして再度同意してください。',
        ];
        if (! isset($messages[$status])) {
            return $this->back($plan)->with('error', '比較を確定できませんでした。古い情報からの通知や自動変更は行われていません。');
        }

        return $this->back($plan)->with('success', $messages[$status]);
    }

    public function destroy(
        Request $request,
        Plan $plan,
        LearningContextWatchPersistenceService $watch,
    ): RedirectResponse {
        $owner = $this->owner($request, $plan);
        // Clearing personal data is allowed even when the flag is OFF or
        // the Plan no longer belongs to the Study domain.
        abort_unless($this->tablesPresent(), 404);
        if (! $watch->optOut($owner, $plan->id)) {
            return $this->back($plan)->with('error', '保存データを削除できませんでした。もう一度お試しください。');
        }

        return redirect()->route('workspace.study.top')
            ->with('success', 'このPlanの保存済みSnapshotと変化記録を削除し、比較をOFFにしました。');
    }

    private function owner(Request $request, Plan $plan): User
    {
        $owner = $request->user();
        abort_unless($owner && $plan->user_id !== null
            && (int) $plan->user_id === (int) $owner->id, 404);

        return $owner;
    }

    private function eligible(Plan $plan, PlanCategoryProfileService $profiles): bool
    {
        return ! (bool) $plan->is_public
            && ! (bool) $plan->is_collaborative
            && $profiles->forPlan($plan)->key === 'study';
    }

    private function available(): bool
    {
        return config('intelligence.learning_context_watch.persistence_enabled') === true
            && config('app.env') !== 'staging'
            && $this->tablesPresent();
    }

    private function tablesPresent(): bool
    {
        return Schema::hasTable('learning_context_watch_states')
            && Schema::hasTable('learning_context_change_records');
    }

    private function state(User $owner, Plan $plan): ?LearningContextWatchState
    {
        if (! $this->tablesPresent()) {
            return null;
        }

        return LearningContextWatchState::query()
            ->where('user_id', $owner->id)
            ->where('plan_id', $plan->id)
            ->first();
    }

    private function back(Plan $plan): RedirectResponse
    {
        return redirect()->route('workspace.study.context_watch.show', ['plan' => $plan->id]);
    }
}
