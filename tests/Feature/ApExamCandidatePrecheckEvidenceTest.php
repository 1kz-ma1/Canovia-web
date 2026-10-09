<?php

namespace Tests\Feature;

use App\Services\AdminAccessService;
use App\Services\ApExamCandidatePrecheckEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApExamCandidatePrecheckEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    private function evidence(string $name): array
    {
        return json_decode((string) file_get_contents(resource_path($name)),
            true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_six_p0_technical_prechecks_and_thirty_five_ipa_credit_fields_are_current_but_not_approved(): void
    {
        $report = app(ApExamCandidatePrecheckEvidenceService::class)->inspect();
        $this->assertSame('0.4.0-review-required', $report['candidate_version']);
        $this->assertTrue($report['structurally_consistent'], implode('; ', $report['issues']));
        $this->assertSame([], $report['issues']);
        $this->assertSame(6, $report['p0_technical_prechecks_current']);
        $this->assertSame(18, $report['p0_wrong_choices_explained']);
        $this->assertSame(35, $report['ipa_source_label_fields_present']);
        $this->assertCount(6, $report['items']);
        $this->assertFalse($report['learner_facing_credit_checked']);
        $this->assertSame(0, $report['independent_human_approved']);
        $this->assertSame(0, $report['rights_approved']);
        $this->assertFalse($report['can_publish']);
        $this->assertSame('https://www.ipa.go.jp/shiken/faq.html',
            $report['ipa_source_policy_url']);
        foreach ($report['items'] as $item) {
            $this->assertSame('ai_technical_precheck_only', $item['status']);
            $this->assertTrue($item['human_review_pending']);
            $this->assertNotEmpty($item['finding']);
            $this->assertNotEmpty($item['risk']);
        }
        $this->assertDatabaseCount('question_packs', 0);
        $this->assertDatabaseCount('learning_runs', 0);
    }

    public function test_all_six_p0_prechecks_expire_if_question_explanation_choice_or_answer_changes(): void
    {
        $tech = $this->evidence(ApExamCandidatePrecheckEvidenceService::CONTENT_PRECHECK);
        $policy = $this->evidence(ApExamCandidatePrecheckEvidenceService::IPA_POLICY);
        $service = app(ApExamCandidatePrecheckEvidenceService::class);
        $this->assertCount(6, $tech['entries']);
        foreach ($tech['entries'] as $index => $entry) {
            foreach (['prompt', 'explanation', 'answer', 'choices'] as $key) {
                $tampered = $tech;
                if ($key === 'choices') {
                    $tampered['entries'][$index]['source_snapshot']['choices'][0]['label'] .= ' changed';
                } elseif ($key === 'answer') {
                    $old = $entry['source_snapshot']['answer'];
                    $tampered['entries'][$index]['source_snapshot']['answer']
                        = $old === 'ア' ? 'イ' : 'ア';
                } else {
                    $tampered['entries'][$index]['source_snapshot'][$key] .= ' changed';
                }
                $this->assertFalse($service->inspect($tampered, $policy)['structurally_consistent'],
                    "{$entry['key']} stale {$key} did not invalidate.");
                $this->assertFalse($service->inspect($tampered, $policy)['can_publish']);
            }
        }

        $fakeSignoff = $tech;
        $fakeSignoff['entries'][0]['human_review_status'] = 'approved';
        $fakeSignoff['entries'][0]['human_reviewer'] = 'fake';
        $this->assertFalse($service->inspect($fakeSignoff, $policy)['structurally_consistent']);

        $fakeRights = $policy;
        $fakeRights['legal_compliance_approved'] = true;
        $this->assertFalse($service->inspect($tech, $fakeRights)['structurally_consistent']);

        $badCredit = $policy;
        $badCredit['entries'][0]['source_label'] = 'IPA より';
        $this->assertFalse($service->inspect($tech, $badCredit)['structurally_consistent']);

        $missingCredit = $policy;
        array_pop($missingCredit['entries']);
        $this->assertFalse($service->inspect($tech, $missingCredit)['structurally_consistent']);

        $missingReason = $tech;
        unset($missingReason['entries'][0]['why_wrong']['イ']);
        $this->assertFalse($service->inspect($missingReason, $policy)['structurally_consistent']);
        $this->assertSame(0, $service->inspect()['rights_approved']);
    }

    public function test_numeric_and_boundary_examples_support_actual_answers_without_expert_signoff(): void
    {
        $tech = $this->evidence(ApExamCandidatePrecheckEvidenceService::CONTENT_PRECHECK);
        $rows = collect($tech['entries'])->keyBy('key');
        $mm1 = $rows->get('calc-mm1-015');
        $this->assertNotNull($mm1);
        $this->assertSame('イ', $mm1['source_snapshot']['answer']);
        $this->assertLessThan(1, .33 / (1 - .33));
        $this->assertEqualsWithDelta(1.0, .50 / (1 - .50), 0.000001);
        $this->assertGreaterThan(1, .67 / (1 - .67));
        $this->assertGreaterThan(1, .80 / (1 - .80));

        $boundary = $rows->get('test-boundary-043');
        $this->assertNotNull($boundary);
        $this->assertSame('イ', $boundary['source_snapshot']['answer']);
        $labels = collect($boundary['source_snapshot']['choices'])->keyBy('id');
        $this->assertSame('17, 18, 65, 66', $labels->get('イ')['label']);
        $this->assertTrue(17 < 18 && 18 <= 65 && 65 < 66);

        $dkim = $rows->get('sec-dkim-023');
        $this->assertStringContainsString('From', $dkim['risk']);
        $this->assertSame('ア', $dkim['source_snapshot']['answer']);
    }

    public function test_quality_review_precheck_is_visible_only_to_authorized_admin(): void
    {
        $this->get(route('admin.question_packs.index'))
            ->assertForbidden()
            ->assertDontSee('data-ap-a-v04-precheck-summary', false);

        $this->withSession([AdminAccessService::SESSION_KEY => true])
            ->get(route('admin.question_packs.index'))
            ->assertOk()
            ->assertSee('data-ap-a-v04-precheck-summary', false)
            ->assertSee('data-ap-a-v04-p0-prechecks', false)
            ->assertSee('data-ap-a-v04-p0-item="sec-dkim-023"', false)
            ->assertSee('IPA出典メタデータ', false)
            ->assertSee('未確認・公開前要確認');

        $this->assertDatabaseCount('question_packs', 0);
        $this->assertDatabaseCount('learning_answer_events', 0);
    }
}
