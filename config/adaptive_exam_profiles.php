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
 * Do not add an unverified AP preset.
 */
return [
    'profiles' => [],
];
