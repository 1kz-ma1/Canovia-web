<?php

namespace App\Http\Controllers;

use App\Services\AdminAccessService;
use App\Services\ReleaseGateService;
use App\Services\ReleaseLevelService;
use Illuminate\Http\Request;

final class AdminReleaseGateController extends Controller
{
    public function __construct(
        private readonly AdminAccessService $access,
        private readonly ReleaseGateService $gate,
        private readonly ReleaseLevelService $levels,
    ) {}

    public function index(Request $request)
    {
        abort_unless($this->access->authorized($request), 403);

        $recommendedTarget = $this->gate->recommendedTarget();

        return view('admin.release_gate.index', [
            'assessments' => $this->gate->assessments(),
            'featureInventory' => $this->gate->featureInventory(),
            'publicLevel' => $this->levels->publicLevel(),
            'recommendedTarget' => $recommendedTarget,
            'recommendedAssessment' => $this->gate->assess($recommendedTarget),
        ]);
    }
}
