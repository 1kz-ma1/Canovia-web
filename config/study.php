<?php

return [
    'question_bank_selection' => [
        'exposure_history_session_limit' => 24,
        'recent_session_window' => 3,
    ],

    'exam_convergence' => [
        'history_attempt_limit' => 16,

        'graduation' => [
            'minimum_targeted_sessions' => 2,
            'minimum_targeted_question_budget' => 8,
            'minimum_score_percent' => 80,
        ],

        'reinforcement' => [
            'maximum_sessions_per_cycle' => 3,
            'maximum_question_budget_per_cycle' => 20,
        ],

        'reentry' => [
            'general_exam_window_attempts' => 3,
            'required_failure_attempts' => 2,
        ],

        'deadline' => [
            'general_practice_days' => 30,
            'exam_mode_days' => 14,
        ],

        'practice' => [
            'normal_question_count' => 10,
        ],
    ],
];
