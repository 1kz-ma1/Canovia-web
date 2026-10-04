<?php

namespace App\Services;

use App\Execution\ExecutionCapability;
use App\Models\Plan;
use App\Models\Task;
use InvalidArgumentException;

final class ExecutionCapabilityResolver
{
    public function __construct(
        private readonly PlanCategoryProfileService $profiles,
        private readonly StudyActivityPolicyService $studyActivities,
    ) {}

    public function forTask(Plan $plan, Task $task): string
    {
        if ((int) $task->plan_id !== (int) $plan->id) {
            throw new InvalidArgumentException(
                'Execution capability can only be resolved inside the Task Plan.',
            );
        }

        $profile = $this->profiles->forPlan($plan)->key;

        if ($profile === 'study') {
            if (! $this->studyActivities->supportsTask($task)) {
                return ExecutionCapability::GENERAL_TASK;
            }

            $activity = (string) data_get(
                $this->studyActivities->forPlanTask($plan, $task),
                'primary.key',
                StudyActivityPolicyService::QUESTION_PRACTICE,
            );

            return match ($activity) {
                StudyActivityPolicyService::RECALL =>
                    ExecutionCapability::STUDY_RECALL,
                StudyActivityPolicyService::RESOURCE_STUDY =>
                    ExecutionCapability::STUDY_RESOURCE,
                default =>
                    ExecutionCapability::STUDY_PRACTICE,
            };
        }

        if ($profile === 'development') {
            return ExecutionCapability::CODING_REPOSITORY;
        }

        return ExecutionCapability::GENERAL_TASK;
    }
}
