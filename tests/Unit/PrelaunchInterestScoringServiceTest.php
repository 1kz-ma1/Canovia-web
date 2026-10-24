<?php

namespace Tests\Unit;

use App\Services\PrelaunchInterestScoringService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PrelaunchInterestScoringServiceTest extends TestCase
{
    private array $bank;
    private PrelaunchInterestScoringService $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bank = require __DIR__.'/../../config/prelaunch_interest_diagnostic.php';
        $this->scorer = new PrelaunchInterestScoringService();
    }

    public function test_bank_contains_30_distinct_original_questions_and_8_balanced_categories(): void
    {
        $this->assertCount(30, $this->bank['items']);
        $this->assertCount(8, $this->bank['categories']);
        $primaryCounts = array_fill_keys(array_keys($this->bank['categories']), 0);
        $cross = 0;
        foreach ($this->bank['items'] as $id => $item) {
            $this->assertMatchesRegularExpression('/^Q(?:0[1-9]|[12][0-9]|30)$/', $id);
            $this->assertNotSame('', trim($item['text']));
            $this->assertArrayHasKey($item['primary'], $primaryCounts);
            if (isset($item['secondary'])) {
                $this->assertArrayHasKey($item['secondary'], $primaryCounts);
                $this->assertNotSame($item['primary'], $item['secondary']);
                $cross++;
            } else {
                $primaryCounts[$item['primary']]++;
            }
        }
        $this->assertSame(6, $cross);
        $this->assertSame(array_fill_keys(array_keys($this->bank['categories']), 3), $primaryCounts);
    }

    public function test_unanswered_and_unknown_are_not_treated_as_low_interest(): void
    {
        $result = $this->scorer->calculate(['Q01' => 'unknown', 'Q02' => 3], $this->bank);
        $this->assertSame('insufficient_evidence', $result['status']);
        $this->assertSame(1, $result['answered_count']);
        $this->assertSame(1, $result['unknown_count']);
        $this->assertSame(28, $result['unanswered_count']);
        $this->assertSame('insufficient_evidence', $result['categories']['ST']['status']);
        $this->assertNull($result['categories']['ST']['score']);
        $this->assertNull($result['categories']['ST']['share_percent']);
    }

    public function test_one_sufficient_category_does_not_fake_evidence_for_other_categories(): void
    {
        $result = $this->scorer->calculate(['Q01' => 3, 'Q02' => 3], $this->bank);
        $this->assertSame('ready', $result['status']);
        $this->assertSame(100, $result['categories']['ST']['share_percent']);
        $this->assertSame(3.0, $result['categories']['ST']['score']);
        $this->assertNull($result['categories']['DV']['share_percent']);
        $this->assertSame('insufficient_evidence', $result['categories']['DV']['status']);
    }

    public function test_full_unknown_and_full_zero_do_not_produce_misleading_percentages(): void
    {
        $allUnknown = array_fill_keys(array_keys($this->bank['items']), 'unknown');
        $u = $this->scorer->calculate($allUnknown, $this->bank);
        $this->assertSame('insufficient_evidence', $u['status']);
        $this->assertSame(30, $u['unknown_count']);
        $this->assertSame(0, $u['unanswered_count']);
        $this->assertSame(0, $u['answered_count']);
        $this->assertNull($u['categories']['ST']['share_percent']);

        $allZero = array_fill_keys(array_keys($this->bank['items']), 0);
        $z = $this->scorer->calculate($allZero, $this->bank);
        $this->assertSame('no_relative_signal', $z['status']);
        $this->assertSame(0.0, $z['categories']['ST']['score']);
        $this->assertNull($z['categories']['ST']['share_percent']);
    }

    public function test_all_positive_responses_produce_exactly_100_integer_percentage_points(): void
    {
        $answers = array_fill_keys(array_keys($this->bank['items']), 3);
        $result = $this->scorer->calculate($answers, $this->bank);
        $this->assertSame('ready', $result['status']);
        $shares = array_column($result['categories'], 'share_percent');
        $this->assertSame(100, array_sum($shares));
        $this->assertSame(30, $result['answered_count']);
        $this->assertSame(0, $result['unknown_count']);
        $this->assertSame(0, $result['unanswered_count']);
        foreach ($result['categories'] as $category) {
            $this->assertSame('ready', $category['status']);
            $this->assertEqualsWithDelta(3.0, $category['score'], 0.00001);
            $this->assertContains($category['share_percent'], [12, 13]);
        }
    }

    public function test_cross_category_items_split_weight_and_do_not_duplicate_full_points(): void
    {
        $answers = ['Q01' => 3, 'Q02' => 3, 'Q04' => 1, 'Q05' => 1, 'Q25' => 0, 'Q29' => 3];
        $r = $this->scorer->calculate($answers, $this->bank);
        $this->assertEqualsWithDelta(2.4, $r['categories']['ST']['score'], 0.00001);
        // DV: Q04+Q05 (1+1) + Q25 half*0 + Q29 half*3; weight 3.0.
        $this->assertEqualsWithDelta(3.5 / 3.0, $r['categories']['DV']['score'], 0.00001);
        $this->assertSame(100, $r['categories']['ST']['share_percent'] + $r['categories']['DV']['share_percent']);
    }

    public function test_score_is_independent_of_answer_submission_order(): void
    {
        $answers = array_fill_keys(array_keys($this->bank['items']), 2);
        $first = $this->scorer->calculate($answers, $this->bank);
        $reversed = array_reverse($answers, true);
        $second = $this->scorer->calculate($reversed, $this->bank);
        $this->assertSame($first, $second);
        $this->assertSame($this->bank['item_bank_version'], $first['item_bank_version']);
        $this->assertSame($this->bank['scoring_version'], $first['scoring_version']);
    }

    public function test_rejects_unknown_question_id_and_out_of_range_or_noninteger_values(): void
    {
        foreach ([['Q99' => 2], ['Q01' => 4], ['Q01' => -1], ['Q01' => '3'],
                     ['Q01' => null], ['Q01' => true], ['Q01' => 'skip']] as $invalid) {
            try {
                $this->scorer->calculate($invalid, $this->bank);
                $this->fail('Invalid answer was accepted');
            } catch (InvalidArgumentException $expected) {
                $this->assertNotSame('', $expected->getMessage());
            }
        }
    }
}
