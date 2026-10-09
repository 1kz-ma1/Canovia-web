<?php
/**
 * Verified exam profiles only. Empty by design until an administrator reviews
 * official requirements, Question Bank coverage and source permissions.
 *
 * Expected profile shape (one entry keyed by exam+version):
 * 'some-exam-v1' => [
 *   'status' => 'verified', 'version' => '1',
 *   'exam_code' => 'SOME', 'subject' => 'Section A',
 *   'question_count' => 50, 'duration_minutes' => 90,
 *   'response_format' => 'single_choice',
 *   'source_reference' => 'official spec URL/title',
 *   'verified_at' => 'YYYY-MM-DD',
 * ],
 * Compatible published QuestionPack metadata must include matching
 * exam_simulation_profile_key and exam_simulation_profile_version.
 * The AP 2026 Subject A format below is sourced directly from IPA;
 * it does NOT certify that any Question Pack has 80 reviewed questions.
 * Subject B is descriptive with 11 offered/5 answered, not compatible with
 * this single-choice exam engine. Do not enable B as a multiple-choice quiz.
 */
return [
    'profiles' => [
        'ap-a-cbt-2026-v1' => [
            'status' => 'verified',
            'version' => '2026-v1',
            'exam_code' => 'AP',
            'subject' => '科目A',
            'question_count' => 80,
            'duration_minutes' => 150,
            'response_format' => 'single_choice',
            'choices_per_question' => 4,
            'requires_pack_review' => true,
            'source_reference' => 'https://www.ipa.go.jp/shiken/kubun/ap.html',
            'verified_at' => '2026-10-09',
        ],
    ],
];
