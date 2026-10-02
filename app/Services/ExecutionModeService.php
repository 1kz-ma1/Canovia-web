<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;
use Illuminate\Support\Collection;

final class ExecutionModeService
{
    public const STUDY = 'study';
    public const DEVELOPMENT = 'development';
    public const CAREER = 'career';
    public const GENERAL = 'general';

    public function __construct(
        private readonly PlanCategoryProfileService $profiles,
    ) {}

    /**
     * @return array<string,array<string,mixed>>
     */
    public function availableModes(Collection $plans): array
    {
        $grouped = $plans
            ->groupBy(fn (Plan $plan) => $this->modeForPlan($plan));

        $definitions = $this->definitions();

        return collect([
            self::STUDY,
            self::DEVELOPMENT,
            self::CAREER,
            self::GENERAL,
        ])
            ->filter(fn (string $mode) => $grouped->has($mode))
            ->mapWithKeys(function (string $mode) use ($grouped, $definitions) {
                $modePlans = $grouped->get($mode, collect())->values();

                return [$mode => [
                    ...$definitions[$mode],
                    'key' => $mode,
                    'plan_count' => $modePlans->count(),
                    'plan_ids' => $modePlans
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->values()
                        ->all(),
                ]];
            })
            ->all();
    }

    public function modeForPlan(Plan $plan): string
    {
        // StudyActivityController currently has an exact qualification-study
        // boundary. Keep Execution mode aligned with the existing specialized
        // capability instead of routing generic learning Plans into a 404.
        if (trim((string) $plan->category) === '資格学習') {
            return self::STUDY;
        }

        return match ($this->profiles->forPlan($plan)->key) {
            'development' => self::DEVELOPMENT,
            'career' => self::CAREER,
            default => self::GENERAL,
        };
    }

    public function plansForMode(Collection $plans, ?string $mode): Collection
    {
        if ($mode === null) {
            return collect();
        }

        return $plans
            ->filter(fn (Plan $plan) => $this->modeForPlan($plan) === $mode)
            ->values();
    }

    /**
     * Describe the execution handoff after a Task has been selected.
     *
     * @return array<string,mixed>
     */
    public function actionFor(Plan $plan, Task $task): array
    {
        $mode = $this->modeForPlan($plan);

        return match ($mode) {
            self::STUDY => [
                'mode' => $mode,
                'action_id' => 'study_activity',
                'label' => '学習Workspaceで進める',
                'description' => '問題演習・想起・教材学習から、このTaskに合う学習Activityを選びます。',
                'route_name' => 'plans.tasks.study_activity.show',
                'route_parameters' => [$plan->id, $task->id],
                'supports_timer' => false,
            ],
            self::DEVELOPMENT => [
                'mode' => $mode,
                'action_id' => 'execution_orchestration',
                'label' => '開発Workspaceで進める',
                'description' => 'Task Context・GitHub・Execution Packetを使って実装作業へ入ります。',
                'route_name' => 'plans.tasks.execution_orchestration.show',
                'route_parameters' => [$plan->id, $task->id],
                'supports_timer' => false,
            ],
            self::CAREER => [
                'mode' => $mode,
                'action_id' => 'career_workspace',
                'label' => 'Career Workspaceで進める',
                'description' => '応募・選考・面接・Captureを、このPlanのCareer Pipelineで進めます。',
                'route_name' => 'plans.career.index',
                'route_parameters' => [$plan->id],
                'supports_timer' => false,
            ],
            default => [
                'mode' => self::GENERAL,
                'action_id' => 'timer',
                'label' => 'このまま開始',
                'description' => 'Taskを開始して作業時間を記録します。',
                'route_name' => null,
                'route_parameters' => [],
                'supports_timer' => true,
            ],
        };
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function definitions(): array
    {
        return [
            self::STUDY => [
                'label' => '学習',
                'eyebrow' => 'STUDY',
                'icon' => '◈',
                'description' => '問題演習・想起・教材学習など、学習内容に合わせた実行へ。',
                'specialized' => true,
            ],
            self::DEVELOPMENT => [
                'label' => '開発',
                'eyebrow' => 'DEVELOPMENT',
                'icon' => '⌘',
                'description' => '実装Context・GitHub・成果物をつないで開発作業へ。',
                'specialized' => true,
            ],
            self::CAREER => [
                'label' => 'キャリア',
                'eyebrow' => 'CAREER',
                'icon' => '◇',
                'description' => '応募・選考・面接の現在地に合わせて実行へ。',
                'specialized' => true,
            ],
            self::GENERAL => [
                'label' => '汎用',
                'eyebrow' => 'GENERAL',
                'icon' => '→',
                'description' => 'その他のPlanを、Task RecommendationとTimerで進めます。',
                'specialized' => false,
            ],
        ];
    }
}
