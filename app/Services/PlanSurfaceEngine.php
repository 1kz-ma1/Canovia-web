<?php

namespace App\Services;

use App\Data\PlanCategoryProfileData;
use App\Data\PlanSurfaceModuleData;
use App\Enums\WorkspaceMode;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Support\Collection;

class PlanSurfaceEngine
{
    public function __construct(
        private readonly ?ReleaseLevelService $releaseLevels = null,
    ) {}

    /**
     * Build a deterministic list of registered UI modules.
     *
     * Today this is rule-based. A future AI policy can change priority /
     * visibility using the same module ids without generating arbitrary UI.
     *
     * @param array<string,mixed> $situation
     * @return Collection<int,PlanSurfaceModuleData>
     */
    public function build(
        Plan $plan,
        PlanCategoryProfileData $profile,
        array $situation,
        ?Task $currentTask,
    ): Collection {
        $modules = collect();

        if ($currentTask) {
            $modules->push($this->module(
                'current_task',
                'dashboard.surfaces.current-task',
                100,
                'primary',
                '今取り組むTaskは全カテゴリ共通で最優先です。',
            ));
        }

        if (
            $profile->key === 'career'
            && ($this->releaseLevels ?? app(ReleaseLevelService::class))
                ->allowsWorkspace(WorkspaceMode::Career)
        ) {
            $reviewDue = $situation['career_review_due_event'] ?? null;
            $nextInterview = $situation['career_next_interview_event'] ?? null;
            $resultWaiting = collect($situation['career_result_waiting_events'] ?? []);

            if ($reviewDue) {
                $modules->push($this->module(
                    'career_interview_review',
                    'dashboard.surfaces.career-interview-review',
                    99,
                    'primary',
                    '面接後は記憶が新しいうちに振り返り、次の選考へ学びを残す価値が高いためです。',
                    ['event' => $reviewDue],
                ));
            } elseif ($nextInterview) {
                $modules->push($this->module(
                    'career_interview_prep',
                    'dashboard.surfaces.career-interview-prep',
                    97,
                    'primary',
                    '次の面接が予定されている間だけ、準備を前面に出します。',
                    ['event' => $nextInterview],
                ));
            } elseif ((bool) ($situation['career_has_interview'] ?? false)) {
                $modules->push($this->module(
                    'career_interview_focus',
                    'dashboard.surfaces.career-interview-focus',
                    96,
                    'primary',
                    '面接・選考Taskが存在する間だけ面接対策を前面に出します。',
                    ['tasks' => $situation['career_interview_tasks'] ?? collect()],
                ));
            }

            $modules->push($this->module(
                'career_pipeline',
                'dashboard.surfaces.career-pipeline',
                94,
                'primary',
                '就活では複数企業・選考段階の現在地を同時に把握する価値が高いためです。',
                [
                    'pipeline' => $situation['career_pipeline'] ?? collect(),
                    'source' => $situation['career_pipeline_source'] ?? 'tasks',
                    'application_count' => (int) ($situation['career_application_count'] ?? 0),
                ],
            ));

            if ((int) ($situation['career_pending_capture_count'] ?? 0) > 0) {
                $modules->push($this->module(
                    'career_capture_inbox',
                    'dashboard.surfaces.career-capture-inbox',
                    88,
                    'supporting',
                    '取り込んだ求人・応募情報が未整理の間だけCapture Inboxを表示します。',
                    ['pending_count' => (int) $situation['career_pending_capture_count']],
                ));
            }

            if ($resultWaiting->isNotEmpty()) {
                $modules->push($this->module(
                    'career_result_waiting',
                    'dashboard.surfaces.career-result-waiting',
                    82,
                    'supporting',
                    '振り返り完了後は、結果待ちの状態を次の応募や面接対策と分けて確認します。',
                    ['events' => $resultWaiting->take(3)->values()],
                ));
            }
        }

        if ($profile->key === 'study' && ((bool) ($situation['has_ai_practice'] ?? false) || (bool) ($situation['study_has_assessment'] ?? false))) {
            $modules->push($this->module(
                'study_focus',
                'dashboard.surfaces.study-focus',
                92,
                'supporting',
                '資格学習では演習結果と弱点・次Actionが日々の判断材料になるためです。',
                [
                    'score' => $situation['study_latest_score'] ?? null,
                    'weaknesses' => $situation['study_weaknesses'] ?? [],
                    'next_action' => $situation['study_next_action'] ?? null,
                ],
            ));
        }

        if (in_array($profile->key, ['development', 'creative'], true)
            && ((int) ($situation['delivery_artifact_count'] ?? 0) > 0 || (bool) ($situation['delivery_has_artifact_evidence'] ?? false))) {
            $modules->push($this->module(
                'delivery_focus',
                'dashboard.surfaces.delivery-focus',
                86,
                'supporting',
                '開発・制作では成果物の状態変化が強い実行Signalになるためです。',
                [
                    'artifact_count' => (int) ($situation['delivery_artifact_count'] ?? 0),
                    'latest_artifact' => $situation['delivery_latest_artifact'] ?? null,
                ],
            ));
        }

        if ((bool) ($situation['has_recent_evidence'] ?? false)) {
            $modules->push($this->module(
                'recent_evidence',
                'dashboard.surfaces.recent-evidence',
                78,
                'supporting',
                'Canoviaが確認できた直近の事実を、進捗率とは分けて返します。',
            ));
        }

        if ((int) ($situation['active_task_count'] ?? 0) > 0) {
            $modules->push($this->module(
                'task_list',
                'dashboard.surfaces.task-list',
                60,
                'supporting',
                'Current Taskの次に控えるTaskを短く確認できるようにします。',
            ));
        }

        $modules->push($this->module(
            'plan_tools',
            'dashboard.surfaces.plan-tools',
            40,
            'available',
            '必要なToolへの入口は残しつつ、主要カードより下へ置きます。',
        ));

        $modules->push($this->module(
            'recent_activity',
            'dashboard.surfaces.recent-activity',
            20,
            'available',
            '履歴は重要ですが、次Actionより優先しません。',
        ));

        return $modules
            ->sortByDesc(fn (PlanSurfaceModuleData $module) => $module->priority)
            ->values();
    }

    /**
     * Safe input contract for a future AI surface policy.
     *
     * The AI receives module ids and reasons, never Blade view paths. It can
     * later return ordered_module_ids / hidden_module_ids, which are applied
     * through applyDecision() against the registered module set.
     *
     * @param array<string,mixed> $situation
     * @param Collection<int,PlanSurfaceModuleData> $modules
     * @return array<string,mixed>
     */
    public function policyContext(
        Plan $plan,
        PlanCategoryProfileData $profile,
        array $situation,
        Collection $modules,
    ): array {
        return [
            'schema_version' => 1,
            'plan' => [
                'id' => (int) $plan->id,
                'title' => (string) $plan->title,
                'category' => (string) ($plan->category ?? ''),
            ],
            'category_profile' => $profile->toArray(),
            'situation' => collect($situation)->only([
                'active_task_count',
                'done_task_count',
                'has_current_task',
                'deadline_days',
                'deadline_soon',
                'deadline_overdue',
                'recent_evidence_count',
                'has_recent_evidence',
                'has_artifacts',
                'has_resources',
                'has_ai_practice',
                'career_stage',
                'career_has_interview',
                'career_interview_is_current',
                'career_application_count',
                'career_interview_count',
                'career_pending_capture_count',
                'career_result_waiting_count',
                'career_pipeline_source',
                'career_review_due',
                'career_review_due_company',
                'career_next_interview_at',
                'career_next_interview_company',
                'study_has_assessment',
                'study_latest_score',
                'study_weaknesses',
                'study_next_action',
                'delivery_artifact_count',
                'delivery_has_artifact_evidence',
            ])->all(),
            'available_modules' => $modules
                ->map(fn (PlanSurfaceModuleData $module) => [
                    'id' => $module->id,
                    'default_priority' => $module->priority,
                    'default_visibility' => $module->visibility,
                    'reason' => $module->reason,
                ])
                ->values()
                ->all(),
            'allowed_output' => [
                'ordered_module_ids' => 'array<string>',
                'hidden_module_ids' => 'array<string>',
            ],
            'protected_module_ids' => ['current_task', 'plan_tools'],
        ];
    }

    /**
     * Apply a future rules/AI decision without allowing arbitrary UI modules.
     *
     * Unknown ids are ignored and protected modules cannot be hidden.
     *
     * @param Collection<int,PlanSurfaceModuleData> $modules
     * @param array<string,mixed> $decision
     * @return Collection<int,PlanSurfaceModuleData>
     */
    public function applyDecision(Collection $modules, array $decision): Collection
    {
        $registered = $modules->keyBy(fn (PlanSurfaceModuleData $module) => $module->id);
        $protected = collect(['current_task', 'plan_tools']);

        $hidden = collect($decision['hidden_module_ids'] ?? [])
            ->filter(fn ($id) => is_string($id) && $registered->has($id) && ! $protected->contains($id))
            ->unique()
            ->values();

        $remaining = $registered->reject(fn (PlanSurfaceModuleData $module) => $hidden->contains($module->id));

        $orderedIds = collect($decision['ordered_module_ids'] ?? [])
            ->filter(fn ($id) => is_string($id) && $remaining->has($id))
            ->unique()
            ->values();

        $ordered = $orderedIds
            ->map(fn (string $id) => $remaining->get($id))
            ->filter();

        $fallback = $remaining
            ->reject(fn (PlanSurfaceModuleData $module) => $orderedIds->contains($module->id))
            ->sortByDesc(fn (PlanSurfaceModuleData $module) => $module->priority)
            ->values();

        return $ordered->concat($fallback)->values();
    }

    private function module(
        string $id,
        string $view,
        int $priority,
        string $visibility,
        string $reason,
        array $payload = [],
    ): PlanSurfaceModuleData {
        return new PlanSurfaceModuleData(
            id: $id,
            view: $view,
            priority: $priority,
            visibility: $visibility,
            reason: $reason,
            payload: $payload,
        );
    }
}
