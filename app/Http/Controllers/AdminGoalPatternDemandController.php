<?php

namespace App\Http\Controllers;

use App\Services\AdminAccessService;
use App\Services\GoalPatternDemandService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminGoalPatternDemandController extends Controller
{
    public function __construct(
        private readonly AdminAccessService $access,
        private readonly GoalPatternDemandService $demand,
    ) {}

    public function index(Request $request)
    {
        if (! $this->access->authorized($request)) {
            abort(403);
        }

        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['7', '30', '90', 'all'])],
            'pattern' => ['nullable', 'string', 'max:40'],
        ]);

        $period = (string) ($validated['period'] ?? '30');
        $selectedPattern = trim((string) ($validated['pattern'] ?? ''));
        $analysis = $this->demand->analyze($period);

        $availablePatterns = collect($analysis['pattern_stats'])
            ->mapWithKeys(fn (array $stat) => [$stat['key'] => $stat['label']]);

        if ($selectedPattern !== '' && ! $availablePatterns->has($selectedPattern)) {
            $selectedPattern = '';
        }

        $selectedPatternStat = $selectedPattern !== ''
            ? collect($analysis['pattern_stats'])->firstWhere('key', $selectedPattern)
            : null;

        return view('admin.goal_pattern_demand.index', [
            ...$analysis,
            'selectedPattern' => $selectedPattern,
            'selectedPatternStat' => $selectedPatternStat,
            'availablePatterns' => $availablePatterns,
        ]);
    }
}
