<?php

namespace Tests\Feature;

use App\Services\ApExamCandidateHumanReviewIntakeService;
use App\Services\ApExamCandidateOwnerReviewWavesService;
use App\Services\ApExamCandidateRevisionPreviewService;
use App\Services\QuestionPackCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ApExamCandidateOwnerReviewWavesV628Test extends TestCase
{
    use RefreshDatabase;

    private function service(): ApExamCandidateOwnerReviewWavesService
    {
        return app(ApExamCandidateOwnerReviewWavesService::class);
    }

    public function test_remaining_74_have_exactly_eight_batches_without_forged_approvals(): void
    {
        $report = $this->service()->plan();
        $this->assertSame('canovia.learning.ap_a_80_remaining_review_waves.v1',
            $report['schema']);
        $this->assertSame('read_only_owner_handoff_not_reviewed',
            $report['status']);
        $this->assertSame('ap/ap-a-2026-cbt-80-candidate-v2',
            $report['candidate_key']);
        $this->assertSame('0.4.0-review-required', $report['candidate_version']);
        $this->assertSame(6, $report['owner_first_pass_documented']);
        $this->assertSame(74, $report['remaining_owner_first_pass']);
        $this->assertSame(35, $report['pending_official']);
        $this->assertSame(39, $report['pending_canovia']);
        $this->assertSame(80, $report['all_independent_human_reviews_pending']);
        $this->assertSame('/admin/question-packs', $report['admin_review_path']);
        $this->assertSame([10, 10, 10, 5, 10, 10, 10, 9],
            array_column($report['waves'], 'count'));
        $this->assertSame(['P1','P1','P1','P1','P2','P2','P2','P2'],
            array_column($report['waves'], 'priority'));
        foreach (['rights_independently_verified', 'publication_authorized',
            'database_import_allowed', 'new_draft_written'] as $flag) {
            $this->assertFalse($report[$flag]);
        }
        $this->assertTrue($report['read_only']);
        $this->assertSame(app(ApExamCandidateHumanReviewIntakeService::class)
            ->worksheet()['candidate_content_sha256'],
            $report['candidate_content_sha256']);
        $this->assertDatabaseCount('question_packs', 0);
    }

    public function test_every_remaining_item_is_unique_frozen_content_bound_and_ipa_first(): void
    {
        $plan = $this->service()->plan();
        $source = app(QuestionPackCatalogService::class)->payload(
            ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $worksheet = app(ApExamCandidateHumanReviewIntakeService::class)->worksheet();
        $p0 = array_column(array_filter($worksheet['entries'],
            fn ($row) => $row['priority'] === 'P0'), 'key');
        $seen = [];
        foreach ($plan['waves'] as $wave) {
            $this->assertSame($wave['count'], count($wave['items']));
            $this->assertSame($wave['keys'], array_column($wave['items'], 'key'));
            $this->assertSame($wave['question_numbers'],
                array_column($wave['items'], 'number'));
            foreach ($wave['items'] as $entry) {
                $this->assertArrayNotHasKey($entry['key'], $seen);
                $this->assertNotContains($entry['key'], $p0);
                $seen[$entry['key']] = true;
                $index = $entry['number'] - 1;
                $this->assertSame($worksheet['entries'][$index]['key'], $entry['key']);
                $this->assertSame($worksheet['entries'][$index]['question_content_sha256'],
                    $entry['question_content_sha256']);
                $this->assertSame($source['questions'][$index]['external_key'],
                    $entry['key']);
                $this->assertSame($wave['priority'], $entry['priority']);
                $this->assertSame('not_yet_submitted', $entry['owner_first_pass']);
                $this->assertSame('pending', $entry['independent_content_review']);
                $this->assertSame('pending', $entry['independent_rights_review']);
                $this->assertSame($source['questions'][$index]['source_reference'],
                    $entry['source_reference']);
                $this->assertArrayNotHasKey('prompt', $entry);
                $this->assertArrayNotHasKey('choices', $entry);
                $this->assertArrayNotHasKey('answer', $entry);
                $this->assertArrayNotHasKey('explanation', $entry);
                $this->assertLessThanOrEqual(4, count($entry['concepts']));
                if ($wave['priority'] === 'P1') {
                    $this->assertSame('official',
                        $source['questions'][$index]['source_type']);
                    $this->assertSame('ipa_2025_autumn', $entry['source_type']);
                } else {
                    $this->assertNotSame('official',
                        $source['questions'][$index]['source_type']);
                    $this->assertContains($entry['source_type'], [
                        'canovia_unrevised_unapproved',
                        'canovia_revised_unapproved',
                    ]);
                }
            }
        }
        $this->assertCount(74, $seen);
        $this->assertCount(80, array_unique(array_merge(array_keys($seen), $p0)));
        $this->assertSame('ipa-2025-autumn-ap-am-q03',
            $plan['waves'][0]['items'][0]['key']);
        $this->assertSame(1, $plan['waves'][0]['items'][0]['number']);
        $this->assertDatabaseCount('question_packs', 0);
    }

    public function test_summary_redacts_all_question_bodies_and_batch_selects_only_one_details_set(): void
    {
        $short = $this->service()->describe();
        $this->assertCount(8, $short['waves']);
        foreach ($short['waves'] as $wave) {
            $this->assertArrayNotHasKey('items', $wave);
        }
        $selected = $this->service()->describe(1);
        $this->assertSame(1, $selected['selected_batch']);
        $this->assertCount(10, $selected['selected_items']);
        $this->assertCount(8, $selected['waves']);
        foreach ($selected['waves'] as $wave) {
            $this->assertArrayNotHasKey('items', $wave);
        }
        $this->expectException(\DomainException::class);
        $this->service()->describe(9);
    }

    public function test_cli_is_local_only_and_rejects_invalid_batch_without_leaking_content(): void
    {
        $this->assertSame(0, Artisan::call('study:ap80-review-wave'));
        $summary = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('read_only_owner_handoff_not_reviewed', $summary['status']);
        $this->assertCount(8, $summary['waves']);
        $this->assertArrayNotHasKey('selected_items', $summary);
        $this->assertSame(0, Artisan::call('study:ap80-review-wave', ['--batch' => '1']));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(10, $report['selected_items']);
        $this->assertFalse($report['publication_authorized']);
        $this->assertStringNotContainsString('"prompt"', Artisan::output());
        $this->assertStringNotContainsString('"explanation"', Artisan::output());
        $this->assertStringNotContainsString('"grading_rule"', Artisan::output());

        $this->assertSame(1, Artisan::call('study:ap80-review-wave',
            ['--batch' => '0']));
        $blocked = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('blocked', $blocked['status']);
        $this->assertFalse($blocked['publication_authorized']);
        $this->assertSame(1, Artisan::call('study:ap80-review-wave',
            ['--batch' => '9']));
        $this->assertSame(1, Artisan::call('study:ap80-review-wave',
            ['--batch' => '1/2']));

        app()->detectEnvironment(fn () => 'production');
        try {
            $this->assertSame(1, Artisan::call('study:ap80-review-wave',
                ['--batch' => '1']));
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
        $this->assertDatabaseCount('question_packs', 0);
    }
}
