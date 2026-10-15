<?php

namespace Tests\Unit;

use App\Services\LearningContextChangeDetectionService;
use PHPUnit\Framework\TestCase;

final class LearningContextChangeDetectionServiceTest extends TestCase
{
    private LearningContextChangeDetectionService $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new LearningContextChangeDetectionService();
    }

    /** @return array<string,mixed> */
    private function capture(int $actor = 23, int $planId = 44): array
    {
        return [
            'actor_id' => $actor,
            'plan_id' => $planId,
            'snapshot' => [
                'schema' => 'canovia.learning_context_snapshot.v1',
                'delivery' => 'ephemeral_explicitly_consented_read_only',
                'purpose' => 'support_one_private_study_plan',
                'computed_at' => '2026-10-15T11:00:00+09:00',
                'sources' => [
                    'raw_evidence_shared' => false,
                    'study_read_at' => '2026-10-15T11:00:00+09:00',
                    'practice_read_at' => '2026-10-15T11:00:00+09:00',
                ],
                'facts' => [
                    'plan' => ['title' => 'Synthetic AP Study', 'owner_recorded_deadline' => '2026-11-10'],
                    'active_tasks' => [
                        'items' => [
                            ['task_id' => 11, 'title' => 'ネットワーク', 'status' => 'doing',
                                'recorded_progress_percent' => 20],
                            ['task_id' => 12, 'title' => 'SQL', 'status' => 'todo',
                                'recorded_progress_percent' => 0],
                        ],
                        'truncated' => false,
                    ],
                    'recent_practice' => [
                        'applied_attempts_considered' => 4,
                        'scored_attempts' => 4,
                        'owner_approved_external_results_considered' => 0,
                        'average_score_percent' => 65,
                        'latest_score_percent' => 65,
                        'truncated' => false,
                    ],
                ],
                'observations' => [
                    'weakness_tags' => [
                        ['topic' => 'DNS', 'mentions_in_sample' => 3,
                            'source' => 'approved_practice_aggregate',
                            'certainty' => 'recorded_not_independently_verified'],
                        ['topic' => 'SQL', 'mentions_in_sample' => 2,
                            'source' => 'approved_practice_aggregate',
                            'certainty' => 'recorded_not_independently_verified'],
                    ],
                    'confidence' => 'bounded_sample',
                ],
                'decision_support' => [
                    'next_study_hint' => [
                        'kind' => 'review_topic', 'topic' => 'DNS',
                        'suggested_question_count' => 10,
                    ],
                ],
            ],
        ];
    }

    public function test_identical_context_ignores_read_clock_and_task_order_without_sending_anything(): void
    {
        $a = $this->capture();
        $b = $this->capture();
        $b['snapshot']['computed_at'] = '2026-10-16T15:00:00+09:00';
        $b['snapshot']['sources']['study_read_at'] = '2026-10-16T15:00:00+09:00';
        $b['snapshot']['sources']['practice_read_at'] = '2026-10-16T15:00:00+09:00';
        $b['snapshot']['decision_support']['next_study_hint']['suggested_question_count'] = 5;
        $b['snapshot']['facts']['active_tasks']['items'] = array_reverse(
            $b['snapshot']['facts']['active_tasks']['items']
        );

        $same = $this->detector->compare($a, $b);
        self::assertSame('unchanged', $same['status']);
        self::assertSame([], $same['events']);
        self::assertSame($same['previous_fingerprint'], $same['current_fingerprint']);
        self::assertFalse($same['notification_eligible']);
        self::assertFalse($same['notification_sent']);
        self::assertFalse($same['mutation_performed']);
        self::assertTrue($same['requires_source_revision_recheck']);
        self::assertTrue($same['requires_owner_permission_recheck']);
    }

    public function test_owner_date_revision_generates_review_candidate_not_exam_alarm(): void
    {
        $a = $this->capture();
        $b = $this->capture();
        $b['snapshot']['facts']['plan']['owner_recorded_deadline'] = '2026-10-31';
        $changed = $this->detector->compare($a, $b);
        self::assertSame('review_candidate', $changed['status']);
        self::assertSame('owner_recorded_deadline_changed', $changed['events'][0]['kind']);
        self::assertSame('2026-10-31', $changed['events'][0]['current_value']);
        self::assertFalse($changed['notification_eligible']);
        self::assertSame($changed['dedupe_fingerprint'],
            $this->detector->compare($a, $b)['dedupe_fingerprint']);
    }

    public function test_same_task_becomes_paused_without_inventing_completion(): void
    {
        $a = $this->capture();
        $b = $this->capture();
        $b['snapshot']['facts']['active_tasks']['items'][0]['status'] = 'paused';
        $changed = $this->detector->compare($a, $b);
        self::assertSame('review_candidate', $changed['status']);
        self::assertSame('recorded_task_paused', $changed['events'][0]['kind']);
        self::assertSame(11, $changed['events'][0]['task_id']);
        self::assertFalse($changed['notification_eligible']);

        unset($b['snapshot']['facts']['active_tasks']['items'][0]);
        $b['snapshot']['facts']['active_tasks']['items'] = array_values(
            $b['snapshot']['facts']['active_tasks']['items']
        );
        $removed = $this->detector->compare($a, $b);
        self::assertSame('changed_no_action', $removed['status']);
        self::assertSame([], $removed['events']);
    }

    public function test_score_sample_changes_require_three_scored_attempts_and_fifteen_points(): void
    {
        $a = $this->capture();
        $b = $this->capture();
        $b['snapshot']['facts']['recent_practice']['average_score_percent'] = 45;
        $changed = $this->detector->compare($a, $b);
        self::assertSame('review_candidate', $changed['status']);
        self::assertSame('recent_practice_sample_changed', $changed['events'][0]['kind']);
        self::assertStringContainsString('NOT a measured learning trend', $changed['events'][0]['caveat']);

        $b['snapshot']['facts']['recent_practice']['average_score_percent'] = 52;
        self::assertSame('changed_no_action', $this->detector->compare($a, $b)['status']);

        $b['snapshot']['facts']['recent_practice']['average_score_percent'] = 45;
        $b['snapshot']['facts']['recent_practice']['applied_attempts_considered'] = 2;
        $b['snapshot']['facts']['recent_practice']['scored_attempts'] = 2;
        self::assertSame('changed_no_action', $this->detector->compare($a, $b)['status']);

        $b = $this->capture();
        $b['snapshot']['facts']['recent_practice']['average_score_percent'] = 45;
        $b['snapshot']['facts']['recent_practice']['truncated'] = true;
        self::assertSame('changed_no_action', $this->detector->compare($a, $b)['status']);
    }

    public function test_recent_weakness_tag_switch_is_review_only_not_mastery(): void
    {
        $a = $this->capture();
        $b = $this->capture();
        $b['snapshot']['observations']['weakness_tags'] = array_reverse(
            $b['snapshot']['observations']['weakness_tags']
        );
        $changed = $this->detector->compare($a, $b);
        self::assertSame('review_candidate', $changed['status']);
        self::assertSame('recent_weakness_tag_priority_changed', $changed['events'][0]['kind']);
        self::assertSame('SQL', $changed['events'][0]['current_topic']);
        self::assertFalse($changed['notification_eligible']);

        $b['snapshot']['facts']['recent_practice']['truncated'] = true;
        self::assertSame('changed_no_action', $this->detector->compare($a, $b)['status']);
    }

    public function test_foreign_owner_or_plan_and_invalid_sources_block_without_value_leak(): void
    {
        $a = $this->capture();
        foreach ([$this->capture(55, 44), $this->capture(23, 555)] as $foreign) {
            $result = $this->detector->compare($a, $foreign);
            self::assertSame('blocked', $result['status']);
            self::assertSame('ACTOR_OR_PLAN_BINDING_INVALID', $result['reason']);
            self::assertSame([], $result['events']);
            self::assertArrayNotHasKey('current_fingerprint', $result);
        }
        $tampered = $this->capture();
        $tampered['snapshot']['sources']['raw_evidence_shared'] = true;
        self::assertSame('blocked', $this->detector->compare($a, $tampered)['status']);
        $tampered = $this->capture();
        $tampered['snapshot']['facts']['active_tasks']['items'][0]['title'] = 'SECRET_UNTRUSTED_HTML';
        $tampered['snapshot']['facts']['active_tasks']['items'][0]['task_id'] = -1;
        self::assertSame('blocked', $this->detector->compare($a, $tampered)['status']);
    }

    public function test_missing_or_invalid_dates_and_tampered_score_samples_fail_closed(): void
    {
        $a = $this->capture();
        $cases = ['2026-02-30', '2026-1-4', 'not-a-date'];
        foreach ($cases as $date) {
            $bad = $this->capture();
            $bad['snapshot']['facts']['plan']['owner_recorded_deadline'] = $date;
            self::assertSame('blocked', $this->detector->compare($a, $bad)['status']);
        }
        $bad = $this->capture();
        $bad['snapshot']['facts']['recent_practice']['scored_attempts'] = 9;
        self::assertSame('blocked', $this->detector->compare($a, $bad)['status']);
        $bad = $this->capture();
        $bad['snapshot']['observations']['weakness_tags'][] = $bad['snapshot']['observations']['weakness_tags'][0];
        self::assertSame('blocked', $this->detector->compare($a, $bad)['status']);
    }

    public function test_same_plan_title_change_is_not_assumed_safe_without_revision_lineage(): void
    {
        $a = $this->capture();
        $b = $this->capture();
        $b['snapshot']['facts']['plan']['title'] = 'Different synthetic plan';
        $changed = $this->detector->compare($a, $b);
        self::assertSame('blocked', $changed['status']);
        self::assertSame('PLAN_IDENTITY_NOT_RECONCILED', $changed['reason']);
    }
}
