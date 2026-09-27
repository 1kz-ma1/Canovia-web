<?php

namespace App\Http\Controllers;

use App\Services\CalendarPageDataService;
use App\Services\HomePageDataService;
use App\Services\RoadmapPageDataService;
use App\Services\TimelinePageDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\View;

final class CoreFragmentBundleController extends Controller
{
    private const SURFACES = ['home', 'roadmap', 'timeline', 'calendar'];

    public function __invoke(
        Request $request,
        HomePageDataService $home,
        RoadmapPageDataService $roadmap,
        TimelinePageDataService $timeline,
        CalendarPageDataService $calendar,
    ): JsonResponse {
        $requested = collect(explode(',', (string) $request->query('surfaces', '')))
            ->map(fn ($surface) => trim($surface))
            ->filter(fn ($surface) => in_array($surface, self::SURFACES, true))
            ->unique()
            ->values();

        if ($requested->isEmpty()) {
            $requested = collect(self::SURFACES);
        }

        $fragments = [];

        foreach ($requested as $surface) {
            [$path, $view, $data] = match ($surface) {
                'home' => ['/', 'dashboard.index', $home->build($request, prefetch: true)],
                'roadmap' => [
                    '/roadmap',
                    'roadmap.index',
                    $roadmap->build($request, (int) $request->integer('roadmap_plan_id') ?: null),
                ],
                'timeline' => ['/timeline', 'timeline.index', $timeline->build($request)],
                'calendar' => [
                    '/calendar',
                    'calendar.index',
                    $calendar->build(
                        $request,
                        $request->string('calendar_view', 'month')->toString(),
                        $request->string('calendar_date')->toString() ?: null,
                        $request->string('calendar_selected')->toString() ?: null,
                    ),
                ],
            };

            $html = View::make($view, [
                ...$data,
                'instantFragment' => true,
                'instantSurface' => $surface,
                'instantPath' => $path,
            ])->render();

            $fragments[$path] = $html;
        }

        return response()
            ->json([
                'version' => 1,
                'fragments' => $fragments,
            ])
            ->header('Cache-Control', 'private, no-store');
    }
}
