<?php

namespace Tests\Feature;

use App\Services\AdminAccessService;
use App\Services\ApExamCandidateQualityReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApExamCandidateQualityReviewTest extends TestCase
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

    private function manifest(): array
    {
        return json_decode((string) file_get_contents(resource_path(
            ApExamCandidateQualityReviewService::WORKLIST
        )), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_all_eighty_v04_items_have_individual_pending_subject_and_rights_review(): void
    {
        $report = app(ApExamCandidateQualityReviewService::class)->inspect();
        $this->assertSame('ap-a-2026-cbt-80-candidate-v2', $report['candidate_slug']);
        $this->assertSame('0.4.0-review-required', $report['candidate_version']);
        $this->assertTrue($report['structurally_consistent'], implode('; ', $report['issues']));
        $this->assertSame([], $report['issues']);
        $this->assertSame(80, $report['total']);
        $this->assertSame(80, $report['independent_human_review_pending']);
        $this->assertSame(400, $report['review_checks_pending']);
        $this->assertSame(['P0' => 6, 'P1' => 35, 'P2' => 39], $report['priority_counts']);
        $this->assertSame(['official' => 35, 'canovia' => 45], $report['source_counts']);
        $this->assertSame(0, $report['quality_approved']);
        $this->assertSame(0, $report['rights_approved']);
        $this->assertSame(0, $report['signed_off']);
        $this->assertFalse($report['can_publish']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $report['content_sha256']);

        $manifest = $this->manifest();
        $this->assertFalse($manifest['publication_authorized']);
        $this->assertSame(0, $manifest['approval_counts']['complete_items']);
        $this->assertCount(80, $manifest['entries']);
        foreach ($manifest['entries'] as $index => $item) {
            $this->assertSame($index + 1, $item['number']);
            $this->assertSame($item['key'], $report['items'][$index]['key']);
            $this->assertSame('awaiting_independent_human_content_and_rights_review', $item['review_status']);
            $this->assertNull($item['approval_decision']);
            $this->assertNull($item['reviewer_id']);
            $this->assertNull($item['reviewed_at']);
            $this->assertNull($item['content_sha256']);
            $this->assertCount(5, $item['review_checks']);
            $this->assertCount(5, $item['review_tasks']);
            foreach ($item['review_checks'] as $status) {
                $this->assertSame('pending', $status);
            }
        }
        $this->assertDatabaseCount('question_packs', 0);
        $this->assertDatabaseCount('learning_runs', 0);
    }

    public function test_review_worklist_rejects_fake_approvals_wrong_sources_or_missing_items(): void
    {
        $service = app(ApExamCandidateQualityReviewService::class);
        $original = $this->manifest();

        $approved = $original;
        $approved['entries'][0]['review_checks']['explanation_accuracy'] = 'approved';
        $this->assertFalse($service->inspect($approved)['structurally_consistent']);

        $signed = $original;
        $signed['entries'][6]['reviewer_id'] = 'fake-reviewer';
        $signed['entries'][6]['approval_decision'] = 'approved';
        $this->assertFalse($service->inspect($signed)['structurally_consistent']);

        $rights = $original;
        $rights['approval_counts']['item_rights_approved'] = 1;
        $rights['publication_authorized'] = true;
        $this->assertFalse($service->inspect($rights)['structurally_consistent']);
        $this->assertFalse($service->inspect($rights)['can_publish']);

        $wrongVersion = $original;
        $wrongVersion['candidate_version'] = '0.5.0';
        $this->assertFalse($service->inspect($wrongVersion)['structurally_consistent']);

        $missing = $original;
        array_pop($missing['entries']);
        $this->assertFalse($service->inspect($missing)['structurally_consistent']);

        $swapped = $original;
        [$swapped['entries'][0], $swapped['entries'][1]]
            = [$swapped['entries'][1], $swapped['entries'][0]];
        $this->assertFalse($service->inspect($swapped)['structurally_consistent']);

        $stale = $original;
        $stale['entries'][0]['source_pack'] = 'unknown-old-source';
        $this->assertFalse($service->inspect($stale)['structurally_consistent']);

        $missingCaveat = $original;
        $key = array_search('sec-dkim-023', array_column($original['entries'], 'key'), true);
        $this->assertNotFalse($key);
        $missingCaveat['entries'][$key]['known_caveat'] = null;
        $this->assertFalse($service->inspect($missingCaveat)['structurally_consistent']);
        $this->assertSame(0, $service->inspect()['signed_off']);
    }

    public function test_pending_review_items_are_visible_to_admin_only_and_do_not_create_records(): void
    {
        $this->get(route('admin.question_packs.index'))
            ->assertForbidden()
            ->assertDontSee('data-ap-a-v04-review-worklist', false);

        $this->withSession([AdminAccessService::SESSION_KEY => true])
            ->get(route('admin.question_packs.index'))
            ->assertOk()
            ->assertSee('data-ap-a-v04-review-worklist', false)
            ->assertSee('data-ap-a-v04-unreviewed', false)
            ->assertSee('data-ap-a-v04-review-priorities', false)
            ->assertSee('data-ap-a-v04-review-items', false)
            ->assertSee('data-ap-a-v04-review-item="sec-dkim-023"', false)
            ->assertSee('data-ap-a-v04-review-item="ipa-2025-autumn-ap-am-q03"', false)
            ->assertSee('内容・利用条件の独立審査台帳')
            ->assertSee('公開可能：いいえ');

        $this->assertDatabaseCount('question_packs', 0);
        $this->assertDatabaseCount('learning_answer_events', 0);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }
}
