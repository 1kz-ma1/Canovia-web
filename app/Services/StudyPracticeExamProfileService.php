<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;

class StudyPracticeExamProfileService
{
    /**
     * Exam profile controls format/calculation difficulty without deciding
     * which weakness should be practiced.
     *
     * @return array<string,mixed>
     */
    public function forPlanTask(Plan $plan, Task $task): array
    {
        $context = mb_strtolower(implode(' ', [
            (string) $plan->title,
            (string) ($plan->description ?? ''),
            (string) ($plan->category ?? ''),
            (string) $task->title,
            (string) ($task->description ?? ''),
        ]));

        $isAp = str_contains($context, '応用情報')
            || preg_match('/(?:^|[^a-z])ap(?:$|[^a-z])/u', $context) === 1;
        $isSubjectA = preg_match('/科目\s*a|科目a|午前/u', $context) === 1;

        if ($isAp && $isSubjectA) {
            return [
                'key' => 'ap_subject_a_exam',
                'version' => 'v1',
                'label' => 'AP科目A 本番準拠',
                'exam_code' => 'AP',
                'subject' => '科目A',
                'preferred_response_type' => 'single_choice',
                'preferred_choice_count' => 4,
                'reasoning_optional' => true,
                'calculation_policy' => [
                    'prefer_neat_values' => true,
                    'avoid_arithmetic_only_difficulty' => true,
                    'allow_reasonable_rounding' => true,
                    'numeric_input_is_exception' => true,
                ],
                'difficulty_policy' => [
                    'raise_with_concept_depth' => true,
                    'raise_with_condition_judgment' => true,
                    'raise_with_cross_concept_reasoning' => true,
                    'do_not_raise_with_awkward_arithmetic' => true,
                ],
            ];
        }

        return [
            'key' => 'generic_study',
            'version' => 'v1',
            'label' => '学習・試験',
            'exam_code' => null,
            'subject' => null,
            'preferred_response_type' => null,
            'preferred_choice_count' => null,
            'reasoning_optional' => true,
            'calculation_policy' => [
                'prefer_neat_values' => true,
                'avoid_arithmetic_only_difficulty' => true,
                'allow_reasonable_rounding' => true,
                'numeric_input_is_exception' => false,
            ],
            'difficulty_policy' => [
                'raise_with_concept_depth' => true,
                'raise_with_condition_judgment' => true,
                'raise_with_cross_concept_reasoning' => true,
                'do_not_raise_with_awkward_arithmetic' => true,
            ],
        ];
    }
}
