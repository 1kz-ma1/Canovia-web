<?php

namespace App\Http\Controllers;

use App\Models\StudyScenarioFixture;
use App\Services\StudyScenarioLabService;
use Illuminate\Http\Request;

final class AdminStudyScenarioLabController extends Controller
{
    public function index(
        Request $request,
        StudyScenarioLabService $lab,
    ) {
        $this->guardEnabled();

        return view('admin.study_scenarios.index', [
            'scenarios' => $lab->catalogFor(
                $request->user(),
            ),
        ]);
    }

    public function store(
        Request $request,
        string $scenarioKey,
        StudyScenarioLabService $lab,
    ) {
        $this->guardEnabled();
        abort_unless($lab->has($scenarioKey), 404);

        $fixture = $lab->createOrReplace(
            $request->user(),
            $scenarioKey,
        );

        return redirect()
            ->route('workspace.study.index', [
                'plan_id' => $fixture->plan_id,
            ])
            ->with(
                'success',
                'Study Scenarioを再生成しました。',
            );
    }

    public function destroy(
        Request $request,
        StudyScenarioFixture $fixture,
        StudyScenarioLabService $lab,
    ) {
        $this->guardEnabled();

        $lab->delete(
            $request->user(),
            $fixture,
        );

        return redirect()
            ->route('admin.study_scenarios.index')
            ->with(
                'success',
                'Study Scenarioを削除しました。',
            );
    }

    public function destroyAll(
        Request $request,
        StudyScenarioLabService $lab,
    ) {
        $this->guardEnabled();

        $count = $lab->deleteAll(
            $request->user(),
        );

        return redirect()
            ->route('admin.study_scenarios.index')
            ->with(
                'success',
                "{$count}件のStudy Scenarioを削除しました。",
            );
    }

    private function guardEnabled(): void
    {
        abort_unless(
            (bool) config(
                'canovia.study_scenario_lab_enabled',
                false,
            ),
            404,
        );
    }
}
