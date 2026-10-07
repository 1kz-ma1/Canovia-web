<?php

namespace App\Http\Controllers;

use App\Services\AdminAccessService;
use App\Services\EarlyAccessInsightsService;
use App\Services\ReleaseGateService;
use App\Services\ReleaseLevelService;
use Illuminate\Http\Request;

final class AdminEarlyAccessController extends Controller
{
    public function __construct(
        private readonly AdminAccessService $access,
        private readonly EarlyAccessInsightsService $insights,
        private readonly ReleaseLevelService $levels,
        private readonly ReleaseGateService $gate,
    ) {}

    public function index(Request $request)
    {
        abort_unless($this->access->authorized($request), 403);

        $days = in_array((int) $request->query('days', 7), [7, 30], true)
            ? (int) $request->query('days', 7)
            : 7;

        return view('admin.early_access.index', [
            'summary' => $this->insights->summary($days),
            'publicLevel' => $this->levels->publicLevel(),
            'targetLevel' => $this->gate->recommendedTarget(),
            'targetAssessment' => $this->gate->assess(
                $this->gate->recommendedTarget(),
            ),
        ]);
    }
}
