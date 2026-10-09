<?php

namespace Tests\Feature;

use App\Services\AdminAccessService;
use App\Services\ApExamCandidateQualityReviewService;
use App\Services\ApExamIpaAttributionReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApExamIpaAttributionReadinessTest extends TestCase
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

    private function sourcePolicy(): array
    {
        return json_decode((string) file_get_contents(resource_path(
            ApExamIpaAttributionReadinessService::POLICY_RECORD
        )), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_all_thirty_five_ipa_question_credits_have_required_metadata_without_rights_signoff(): void
    {
        $r = app(ApExamIpaAttributionReadinessService::class)->inspect();
        $this->assertTrue($r['policy_metadata_precheck_passed'],
            implode('; ', $r['issues']));
        $this->assertSame([], $r['issues']);
        $this->assertSame(35, $r['official_question_count']);
        $this->assertSame(35, $r['metadata_attribution_present']);
        $this->assertFalse($r['learner_facing_attribution_verified']);
        $this->assertFalse($r['usage_context_approved']);
        $this->assertSame(0, $r['rights_approved']);
        $this->assertFalse($r['can_publish']);
        $this->assertSame('https://www.ipa.go.jp/shiken/faq.html', $r['ipa_faq_url']);

        $policy = $this->sourcePolicy();
        $this->assertCount(4, $policy['policy_conditions']);
        $this->assertCount(35, $policy['entries']);
        $this->assertFalse($policy['legal_compliance_approved']);
        $review = app(ApExamCandidateQualityReviewService::class)->inspect();
        $this->assertTrue($review['structurally_consistent'],
            implode('; ', $review['issues']));
        $this->assertSame(35, $review['ipa_attribution_metadata_present']);
        $this->assertFalse($review['ipa_learner_facing_credit_verified']);
        $this->assertSame(0, $review['ipa_rights_approved']);
        $this->assertSame(80, $review['independent_human_review_pending']);
        $this->assertFalse($review['can_publish']);
        $this->assertDatabaseCount('question_packs', 0);
        $this->assertDatabaseCount('learning_runs', 0);
    }

    public function test_forged_ipa_clearance_or_incomplete_source_credits_fail_closed(): void
    {
        $service = app(ApExamIpaAttributionReadinessService::class);
        $policy = $this->sourcePolicy();

        $noLegalReview = $policy;
        $noLegalReview['legal_compliance_approved'] = true;
        $this->assertFalse($service->inspect($noLegalReview)['policy_metadata_precheck_passed']);
        $this->assertFalse($service->inspect($noLegalReview)['can_publish']);

        $wrongVersion = $policy;
        $wrongVersion['candidate_version'] = '0.5.0';
        $this->assertFalse($service->inspect($wrongVersion)['policy_metadata_precheck_passed']);

        $missing = $policy;
        array_pop($missing['entries']);
        $this->assertFalse($service->inspect($missing)['policy_metadata_precheck_passed']);

        $badSource = $policy;
        $badSource['entries'][0]['source_label'] = 'IPAの問題';
        $this->assertFalse($service->inspect($badSource)['policy_metadata_precheck_passed']);

        $wrongSource = $policy;
        $wrongSource['entries'][0]['key'] = 'not-an-official-question';
        $this->assertFalse($service->inspect($wrongSource)['policy_metadata_precheck_passed']);

        $fakeRights = $policy;
        $fakeRights['entries'][0]['rights_clearance_status'] = 'approved';
        $this->assertFalse($service->inspect($fakeRights)['policy_metadata_precheck_passed']);

        $noConditions = $policy;
        $noConditions['policy_conditions'] = [];
        $this->assertFalse($service->inspect($noConditions)['policy_metadata_precheck_passed']);
    }

    public function test_ipa_conditions_and_credit_status_visible_to_admin_not_public(): void
    {
        $this->get(route('admin.question_packs.index'))
            ->assertForbidden()
            ->assertDontSee('data-ap-a-ipa-attribution', false);

        $this->withSession([AdminAccessService::SESSION_KEY => true])
            ->get(route('admin.question_packs.index'))
            ->assertOk()
            ->assertSee('data-ap-a-ipa-attribution', false)
            ->assertSee('IPA公開問題の出典メタデータ')
            ->assertSee('35/35問')
            ->assertSee('IPA公式FAQ（過去問題の使用方法）')
            ->assertSee('学習者向け画面の出典表示：')
            ->assertSee('最終利用条件の承認：0問');

        $this->assertDatabaseCount('question_packs', 0);
        $this->assertDatabaseCount('learning_answer_events', 0);
    }
}
