<?php

namespace App\Http\Controllers;

use App\Services\CalendarPageDataService;
use App\Services\HomePageDataService;
use App\Services\RoadmapPageDataService;
use App\Services\TimelinePageDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        abort_unless($request->header('X-Canovia-Instant-Navigation') === 'prefetch', 404);

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
                'roadmap' => $this->roadmapFragment($request, $roadmap),
                'timeline' => ['/timeline', 'timeline.index', $timeline->build($request)],
                'calendar' => $this->calendarFragment($request, $calendar),
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

    private function roadmapFragment(Request $request, RoadmapPageDataService $roadmap): array
    {
        $planId = (int) $request->integer('roadmap_plan_id');
        $path = $planId > 0 ? '/roadmap?plan_id='.$planId : '/roadmap';

        return [
            $path,
            'roadmap.index',
            $roadmap->build($request, $planId ?: null),
        ];
    }

    private function calendarFragment(Request $request, CalendarPageDataService $calendar): array
    {
        $view = $request->string('calendar_view', 'month')->toString();
        $date = $request->string('calendar_date')->toString() ?: null;
        $selected = $request->string('calendar_selected')->toString() ?: null;

        $query = array_filter([
            'view' => $view !== 'month' ? $view : null,
            'date' => $date,
            'selected' => $selected,
        ], fn ($value) => $value !== null && $value !== '');

        $path = '/calendar'.($query !== [] ? '?'.http_build_query($query) : '');

        return [
            $path,
            'calendar.index',
            $calendar->build($request, $view, $date, $selected),
        ];
    }
}
