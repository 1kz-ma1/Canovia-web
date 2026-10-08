<?php

namespace App\Services;

use App\Intelligence\Study\StudyLearningTypeRouter;
use App\Models\Plan;

/**
 * Versioned, manually verified official reference. This does not scrape,
 * import official content into a learner's confirmed scope, or invent mastery.
 * New qualifications must be reviewed and registered explicitly.
 */
final class StudyOfficialExamReferenceService
{
    public const AP_REFERENCE_URL = 'https://www.ipa.go.jp/shiken/syllabus/gaiyou.html';
    public const AP_EXAM_URL = 'https://www.ipa.go.jp/shiken/2026/ap_koudo_sc_kikan.html';

    public function __construct(
        private readonly StudyLearningTypeRouter $learningTypes,
    ) {}

    /** @return array<string,string>|null */
    public function forPlan(Plan $plan): ?array
    {
        // An explicit study type override takes precedence over title keywords.
        if ($this->learningTypes->route($plan)['key']
            !== StudyLearningTypeRouter::CERTIFICATION_EXAM) {
            return null;
        }

        $text = mb_strtolower(trim(
            (string) $plan->title.' '.(string) $plan->description,
        ));

        $isAp = str_contains($text, '応用情報技術者')
            || str_contains($text, '応用情報')
            || preg_match(
                '/(?:^|[\\s　（(])ap(?:対策|試験|合格|学習|受験|[\\s　）)]|$)/iu',
                $text,
            ) === 1;

        if (! $isAp) {
            return null;
        }

        return [
            'key' => 'ipa_ap_2026',
            'exam_name' => '応用情報技術者試験',
            'issuer' => 'IPA（情報処理推進機構）',
            'source_kind' => 'official_reference',
            'source_url' => self::AP_REFERENCE_URL,
            'exam_url' => self::AP_EXAM_URL,
            'syllabus_version' => '7.2',
            'exam_regulation_version' => '5.6',
            'applicable_period' => '2026年度',
            'reference_verified_at' => '2026-10-08',
            'coverage_status' => 'official_reference_known_user_scope_unregistered',
        ];
    }
}
