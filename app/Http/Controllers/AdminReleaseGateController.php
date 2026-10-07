<?php

namespace App\Http\Controllers;

use App\Services\AdminAccessService;
use App\Services\ReleaseGateService;
use App\Services\ReleaseLevelService;
use App\Services\ReleaseReviewService;
use Illuminate\Http\Request;

final class AdminReleaseGateController extends Controller
{
    public function __construct(
        private readonly AdminAccessService $access,
        private readonly ReleaseGateService $gate,
        private readonly ReleaseLevelService $levels,
        private readonly ReleaseReviewService $reviews,
    ) {}

    public function index(Request $request)
    {
        abort_unless($this->access->authorized($request), 403);

        $recommendedTarget = $this->gate->recommendedTarget();

        $recommendedAssessment = $this->gate->assess($recommendedTarget);
        $recommendedReview = $this->reviews->summary($recommendedTarget);

        return view('admin.release_gate.index', [
            'assessments' => $this->gate->assessments(),
            'featureInventory' => $this->gate->featureInventory(),
            'publicLevel' => $this->levels->publicLevel(),
            'recommendedTarget' => $recommendedTarget,
            'recommendedAssessment' => $recommendedAssessment,
            'recommendedReview' => $recommendedReview,
            'recommendedDecision' => $this->decision(
                $recommendedAssessment,
                $recommendedReview,
            ),
        ]);
    }

    public function updateReview(Request $request)
    {
        abort_unless($this->access->authorized($request), 403);

        $validated = $request->validate([
            'release_level' => ['required', 'integer', 'between:0,4'],
            'check_key' => ['required', 'string', 'max:120'],
            'status' => ['required', 'in:passed,failed'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $sourceLevel = \App\Enums\ReleaseLevel::tryFrom(
            (int) $validated['release_level'],
        );
        abort_unless($sourceLevel instanceof \App\Enums\ReleaseLevel, 404);

        $reviewer = $request->user();
        abort_unless($reviewer instanceof \App\Models\User, 403);

        $this->reviews->save(
            $sourceLevel,
            (string) $validated['check_key'],
            (string) $validated['status'],
            $validated['note'] ?? null,
            $reviewer,
        );

        return back()->with(
            'status',
            $validated['status'] === 'passed'
                ? 'Release ReviewをPassedに更新しました。'
                : 'Release ReviewをFailedに更新しました。',
        );
    }

    public function resetReview(Request $request)
    {
        abort_unless($this->access->authorized($request), 403);

        $validated = $request->validate([
            'release_level' => ['required', 'integer', 'between:0,4'],
            'check_key' => ['required', 'string', 'max:120'],
        ]);

        $sourceLevel = \App\Enums\ReleaseLevel::tryFrom(
            (int) $validated['release_level'],
        );
        abort_unless($sourceLevel instanceof \App\Enums\ReleaseLevel, 404);

        $this->reviews->reset(
            $sourceLevel,
            (string) $validated['check_key'],
        );

        return back()->with('status', 'Release ReviewをPendingへ戻しました。');
    }

    /**
     * @param array<string,mixed> $assessment
     * @param array<string,mixed> $review
     */
    private function decision(
        array $assessment,
        array $review,
    ): string {
        if (! (bool) ($assessment['automatic_ready'] ?? false)) {
            return 'blocked';
        }

        if ((int) ($review['failed'] ?? 0) > 0) {
            return 'failed';
        }

        if (! (bool) ($review['complete'] ?? false)) {
            return 'manual_review';
        }

        return 'ready';
    }
}
