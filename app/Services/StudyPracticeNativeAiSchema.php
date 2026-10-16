<?php

namespace App\Services;

final class StudyPracticeNativeAiSchema
{
    public static function questions(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'schema_version' => ['type' => 'string'],
                'flow' => ['type' => 'string'],
                'target_plan' => self::idObject(),
                'target_task' => self::idObject(),
                'title' => ['type' => 'string'],
                'questions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'prompt' => ['type' => 'string'],
                            'work_input' => [
                                'type' => 'string',
                                'enum' => ['none', 'reasoning', 'calculation'],
                            ],
                            'response_fields' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'maxItems' => 4,
                                'description' => 'One question only. For AP subject A single-choice, exactly one final single_choice answer; supplemental reasoning/calculation textarea must be optional. Never combine answers from independent questions.',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'properties' => [
                                        'id' => ['type' => 'string'],
                                        'type' => [
                                            'type' => 'string',
                                            'enum' => ['single_choice', 'multiple_choice', 'number', 'short_text', 'textarea'],
                                        ],
                                        'label' => ['type' => 'string'],
                                        'required' => ['type' => 'boolean'],
                                        'placeholder' => ['type' => 'string'],
                                        'choices' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'additionalProperties' => false,
                                                'properties' => [
                                                    'id' => ['type' => 'string'],
                                                    'label' => ['type' => 'string'],
                                                ],
                                                'required' => ['id', 'label'],
                                            ],
                                        ],
                                    ],
                                    'required' => ['id', 'type', 'label', 'required', 'placeholder', 'choices'],
                                ],
                            ],
                        ],
                        'required' => ['id', 'prompt', 'work_input', 'response_fields'],
                    ],
                ],
            ],
            'required' => ['schema_version', 'flow', 'target_plan', 'target_task', 'title', 'questions'],
        ];
    }

    public static function assessment(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'schema_version' => ['type' => 'string'],
                'flow' => ['type' => 'string'],
                'target_plan' => self::idObject(),
                'target_task' => self::idObject(),
                'score_percent' => ['type' => 'integer'],
                'question_feedback' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'question_id' => ['type' => 'string'],
                            'correctness' => [
                                'type' => 'string',
                                'enum' => ['correct', 'partial', 'incorrect', 'ungraded'],
                            ],
                            'feedback' => ['type' => 'string'],
                            'reasoning_feedback' => ['type' => 'string'],
                            'error_type' => [
                                'type' => 'string',
                                'enum' => [
                                    'none',
                                    'knowledge_gap',
                                    'concept_gap',
                                    'reasoning_gap',
                                    'condition_reading',
                                    'unit_error',
                                    'calculation_slip',
                                    'careless',
                                    'unknown',
                                ],
                            ],
                            'weakness_topics' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'misconceptions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => [
                            'question_id',
                            'correctness',
                            'feedback',
                            'reasoning_feedback',
                            'error_type',
                            'weakness_topics',
                            'misconceptions',
                        ],
                    ],
                ],
                'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
                'weaknesses' => ['type' => 'array', 'items' => ['type' => 'string']],
                'recommended_task_progress_percent' => ['type' => 'integer'],
                'evidence_summary' => ['type' => 'string'],
                'next_action' => ['type' => 'string'],
                'next_step' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'kind' => [
                            'type' => 'string',
                            'enum' => ['practice', 'review', 'continue_task', 'complete_task', 'plan_update'],
                        ],
                        'label' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                        'focus_topics' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'question_count' => ['type' => 'integer'],
                    ],
                    'required' => ['kind', 'label', 'reason', 'focus_topics', 'question_count'],
                ],
            ],
            'required' => [
                'schema_version',
                'flow',
                'target_plan',
                'target_task',
                'score_percent',
                'question_feedback',
                'strengths',
                'weaknesses',
                'recommended_task_progress_percent',
                'evidence_summary',
                'next_action',
                'next_step',
            ],
        ];
    }

    private static function idObject(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'id' => ['type' => 'integer'],
            ],
            'required' => ['id'],
        ];
    }
}
