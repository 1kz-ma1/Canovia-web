<?php

namespace Tests\Feature;

use App\Models\QuestionPack;
use App\Services\AdminAccessService;
use App\Services\ApExamCandidateAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApExamCandidateReviewQueueTest extends TestCase
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

    public function test_candidate_audit_proves_structure_but_keeps_all_eighty_items_pending_human_review(): void
    {
        $report = app(ApExamCandidateAuditService::class)->inspect();
        $this->assertSame('ap/ap-a-2026-cbt-80-candidate-v1', $report['candidate_key']);
        $this->assertSame('0.3.0-review-required', $report['candidate_version']);
        $this->assertSame(80, $report['count']);
        $this->assertCount(80, $report['items']);
        $this->assertSame(0, $report['structural_failure_count']);
        $this->assertSame(80, $report['independent_review_pending_count']);
        $this->assertSame(['technology' => 50, 'management' => 10, 'strategy' => 20],
            $report['distribution']);
        $this->assertSame([
            'ap-a-ipa-2025-autumn-official-v1' => 35,
            'ap-a-canovia-core-v1' => 35,
            'ap-a-canovia-business-management-supplement-v1' => 10,
        ], $report['source_counts']);
        $this->assertSame(5, $report['known_overlap_count']);
        $this->assertTrue($report['known_overlap_excluded']);
        $this->assertTrue($report['publication_blocked']);

        foreach ($report['items'] as $index => $item) {
            $this->assertSame($index + 1, $item['number']);
            $this->assertSame([], $item['flags'], $item['key']);
            $this->assertSame('pending', $item['independent_review_status']);
            $this->assertCount(4, $item['choices']);
            $this->assertNotEmpty($item['explanation']);
            $this->assertContains($item['answer'], array_column($item['choices'], 'id'));
            $this->assertCount(4, $item['review_tasks']);
        }

        $official = collect($report['items'])->firstWhere('origin_type', 'official');
        $this->assertNotNull($official);
        $this->assertStringStartsWith('https://www.ipa.go.jp/', $official['source_url']);
        $this->assertContains('Canovia独自の解説案・誤答理由の検証', $official['review_tasks']);
        $supplement = collect($report['items'])->firstWhere('origin_type', 'new');
        $this->assertNotNull($supplement);
        $this->assertStringContainsString('strategy-', $supplement['key']);
        $this->assertSame('', $supplement['source_url']);
        $this->assertDatabaseCount('question_packs', 0);
        $this->assertDatabaseCount('learning_runs', 0);
    }

    public function test_only_authorized_admin_can_read_source_answers_and_pending_review_queue(): void
    {
        $this->get(route('admin.question_packs.index'))
            ->assertForbidden()
            ->assertDontSee('data-ap-a-item-review-queue', false);

        $this->withSession([AdminAccessService::SESSION_KEY => true])
            ->get(route('admin.question_packs.index'))
            ->assertOk()
            ->assertSee('data-ap-a-item-review-queue', false)
            ->assertSee('data-ap-a-review-items', false)
            ->assertSee('data-ap-a-automatic-failures', false)
            ->assertSee('data-ap-a-human-review-pending', false)
            ->assertSee('data-ap-a-release-blocked', false)
            ->assertSee('通常公開と本番模試提供はブロック中です。')
            ->assertSee('80問それぞれの出典・正答・解説と監修項目')
            ->assertSee('data-ap-a-review-item="ipa-2025-autumn-ap-am-q03"', false)
            ->assertSee('data-ap-a-review-item="pm-evm-051"', false)
            ->assertSee('data-ap-a-review-item="strategy-pest-001"', false)
            ->assertSee('専門監修待ち')
            ->assertSee('IPA原問題を確認（別タブ）')
            ->assertDontSee('リポジトリで管理している検証済みPackです。');
        $this->assertDatabaseCount('question_packs', 0);
        $this->assertDatabaseCount('learning_answer_events', 0);
    }
}
