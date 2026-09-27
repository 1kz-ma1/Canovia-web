<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;

final class CalendarPageDataService
{
    public function __construct(
        private readonly CoreContextService $core,
        private readonly CalendarPresentationService $calendarService,
    ) {}

    public function build(
        Request $request,
        ?string $view = null,
        ?string $date = null,
        ?string $selected = null,
    ): array {
        $plans = $this->core->plans($request, [
            'tasks',
            'work_logs',
            'availability',
        ]);

        $view ??= $request->string('view', 'month')->toString();
        $anchor = $this->parseDate($date ?? $request->string('date')->toString()) ?? Carbon::today();
        $selectedDate = $this->parseDate($selected ?? $request->string('selected')->toString()) ?? Carbon::today();
        $calendar = $this->calendarService->calendar($plans, $view, $anchor, $selectedDate);

        return compact('calendar', 'plans');
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
