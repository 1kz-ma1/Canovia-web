<?php

namespace App\Services;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Feedback;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class EarlyAccessInsightsService
{
    /**
     * @return array<string,mixed>
     */
    public function summary(int $days = 7): array
    {
        $days = in_array($days, [7, 30], true) ? $days : 7;
        $since = now()->subDays($days)->startOfDay();

        $cohort = User::query()
            ->where('created_at', '>=', $since)
            ->get([
                'id',
                'created_at',
                'first_run_completed_at',
            ]);

        $userIds = $cohort->pluck('id')->map(fn ($id) => (int) $id);

        $planUserIds = $userIds->isEmpty()
            ? collect()
            : Plan::query()
                ->whereIn('user_id', $userIds)
                ->whereNotNull('user_id')
                ->distinct()
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id);

        $taskUserIds = $userIds->isEmpty()
            ? collect()
            : Task::query()
                ->join('plans', 'tasks.plan_id', '=', 'plans.id')
                ->whereIn('plans.user_id', $userIds)
                ->whereNotNull('plans.user_id')
                ->distinct()
                ->pluck('plans.user_id')
                ->map(fn ($id) => (int) $id);

        $workStartedUserIds = $this->workEventUsers(
            $userIds,
            BehaviorEventType::WorkStarted,
            $since,
        );
        $workCompletedUserIds = $this->workEventUsers(
            $userIds,
            BehaviorEventType::WorkCompleted,
            $since,
        );

        $registered = $cohort->count();

        $activation = collect([
            [
                'key' => 'registered',
                'label' => '登録',
                'users' => $registered,
            ],
            [
                'key' => 'first_run',
                'label' => '初回導線完了',
                'users' => $cohort
                    ->filter(fn (User $user) => $user->first_run_completed_at !== null)
                    ->count(),
            ],
            [
                'key' => 'plan',
                'label' => 'Plan作成',
                'users' => $planUserIds->unique()->count(),
            ],
            [
                'key' => 'task',
                'label' => 'Task作成',
                'users' => $taskUserIds->unique()->count(),
            ],
            [
                'key' => 'started',
                'label' => '実行開始',
                'users' => $workStartedUserIds->unique()->count(),
            ],
            [
                'key' => 'completed',
                'label' => '実行完了',
                'users' => $workCompletedUserIds->unique()->count(),
            ],
        ])->map(function (array $stage) use ($registered) {
            $stage['conversion_percent'] = $registered > 0
                ? round(($stage['users'] / $registered) * 100, 1)
                : null;

            return $stage;
        });

        $retention = $this->retention($since);

        $feedback = $userIds->isEmpty()
            ? collect()
            : Feedback::query()
                ->whereIn('user_id', $userIds)
                ->where('created_at', '>=', $since)
                ->get(['user_id', 'type', 'rating', 'created_at']);

        $previewEvents = BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::ProductPreviewViewed->value,
            )
            ->where('occurred_at', '>=', $since)
            ->get(['actor_token', 'occurred_at']);

        $dailyActive = BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::EarlyAccessSessionStarted->value,
            )
            ->where('occurred_at', '>=', $since)
            ->get(['actor_token', 'occurred_at'])
            ->groupBy(
                fn (BehaviorEvent $event) =>
                    $event->occurred_at?->toDateString() ?? 'unknown',
            )
            ->map(
                fn (Collection $events) =>
                    $events->pluck('actor_token')->unique()->count(),
            )
            ->sortKeys();

        return [
            'days' => $days,
            'since' => $since,
            'registered_users' => $registered,
            'activation' => $activation,
            'retention' => $retention,
            'feedback' => [
                'total' => $feedback->count(),
                'actors' => $feedback->pluck('user_id')->unique()->count(),
                'bugs' => $feedback->where('type', 'bug')->count(),
                'usability' => $feedback->where('type', 'usability')->count(),
                'requests' => $feedback->where('type', 'request')->count(),
                'positive' => $feedback->where('type', 'positive')->count(),
                'average_rating' => $feedback
                    ->whereNotNull('rating')
                    ->avg('rating'),
            ],
            'product_preview' => [
                'views' => $previewEvents->count(),
                'actors' => $previewEvents
                    ->pluck('actor_token')
                    ->unique()
                    ->count(),
            ],
            'daily_active' => $dailyActive,
        ];
    }

    /**
     * @param Collection<int,int> $userIds
     * @return Collection<int,int>
     */
    private function workEventUsers(
        Collection $userIds,
        BehaviorEventType $type,
        CarbonInterface $since,
    ): Collection {
        if ($userIds->isEmpty()) {
            return collect();
        }

        return BehaviorEvent::query()
            ->join('plans', 'behavior_events.plan_id', '=', 'plans.id')
            ->where('behavior_events.event_type', $type->value)
            ->where('behavior_events.occurred_at', '>=', $since)
            ->whereIn('plans.user_id', $userIds)
            ->whereNotNull('plans.user_id')
            ->distinct()
            ->pluck('plans.user_id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * @return array<string,array<string,int|float|null>>
     */
    private function retention(CarbonInterface $since): array
    {
        $registrations = BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::EarlyAccessRegistered->value,
            )
            ->where('occurred_at', '>=', $since)
            ->orderBy('occurred_at')
            ->get(['actor_token', 'occurred_at'])
            ->groupBy('actor_token')
            ->map(fn (Collection $events) => $events->first())
            ->filter();

        $sessionsByActor = BehaviorEvent::query()
            ->where(
                'event_type',
                BehaviorEventType::EarlyAccessSessionStarted->value,
            )
            ->where('occurred_at', '>=', $since)
            ->get(['actor_token', 'occurred_at'])
            ->groupBy('actor_token');

        return [
            'd1' => $this->retentionAtDay(
                $registrations,
                $sessionsByActor,
                1,
            ),
            'd7' => $this->retentionAtDay(
                $registrations,
                $sessionsByActor,
                7,
            ),
        ];
    }

    /**
     * @param Collection<string,BehaviorEvent> $registrations
     * @param Collection<string,Collection<int,BehaviorEvent>> $sessionsByActor
     * @return array<string,int|float|null>
     */
    private function retentionAtDay(
        Collection $registrations,
        Collection $sessionsByActor,
        int $day,
    ): array {
        $eligible = 0;
        $returned = 0;
        $today = now()->startOfDay();

        foreach ($registrations as $actorToken => $registration) {
            $registeredDay = $registration->occurred_at?->copy()->startOfDay();
            if (! $registeredDay) {
                continue;
            }

            $targetDay = $registeredDay->copy()->addDays($day);
            if ($targetDay->greaterThanOrEqualTo($today)) {
                continue;
            }

            $eligible++;

            $returnedOnTargetDay = collect(
                $sessionsByActor->get($actorToken, collect()),
            )->contains(
                fn (BehaviorEvent $event) =>
                    $event->occurred_at?->isSameDay($targetDay) ?? false,
            );

            if ($returnedOnTargetDay) {
                $returned++;
            }
        }

        return [
            'eligible' => $eligible,
            'returned' => $returned,
            'rate' => $eligible > 0
                ? round(($returned / $eligible) * 100, 1)
                : null,
        ];
    }
}
