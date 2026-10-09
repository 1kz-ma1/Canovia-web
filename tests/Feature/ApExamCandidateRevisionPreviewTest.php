<?php

namespace Tests\Feature;

use App\Models\QuestionPack;
use App\Services\AdminAccessService;
use App\Services\AdaptiveExamPackReadinessService;
use App\Services\AdaptiveExamProfileRegistry;
use App\Services\ApExamCandidateRevisionPreviewService;
use App\Services\QuestionPackCatalogService;
use App\Services\QuestionPackPublicationReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApExamCandidateRevisionPreviewTest extends TestCase
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

    public function test_six_proposals_are_applied_only_to_a_separate_immutable_preview(): void
    {
        $catalog = app(QuestionPackCatalogService::class);
        $base = $catalog->payload(ApExamCandidateRevisionPreviewService::BASE_KEY);
        $next = $catalog->payload(ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $inspection = app(ApExamCandidateRevisionPreviewService::class)->inspect();

        $this->assertSame('0.3.0-review-required', $base['pack']['version']);
        $this->assertSame('0.4.0-review-required', $next['pack']['version']);
        $this->assertNotSame($base['pack']['slug'], $next['pack']['slug']);
        $this->assertCount(80, $base['questions']);
        $this->assertCount(80, $next['questions']);
        $this->assertTrue($inspection['structurally_consistent'],
            implode('; ', $inspection['issues']));
        $this->assertSame([], $inspection['issues']);
        $this->assertSame(6, count($inspection['changed_keys']));
        $this->assertSame(4, $inspection['prompt_changes']);
        $this->assertSame(6, $inspection['explanation_changes']);
        $this->assertSame(35, $inspection['official_unchanged']);
        $this->assertSame(45, $inspection['choice_draft_current']);
        $this->assertSame(35, $inspection['official_source_evidence_carried']);
        $this->assertSame(80, $inspection['independent_review_pending']);
        $this->assertFalse($inspection['publication_authorized']);
        $this->assertFalse($next['pack']['downloadable']);
        $this->assertSame('pending_human_content_and_rights_review',
            $next['pack']['metadata']['review_state']);
        $this->assertSame('pending_human_subject_review',
            $next['pack']['metadata']['explanation_review_state']);
        $this->assertArrayNotHasKey('exam_simulation_review', $next['pack']['metadata']);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/',
            $inspection['base_content_sha256']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/',
            $inspection['preview_content_sha256']);
        $this->assertNotSame($inspection['base_content_sha256'],
            $inspection['preview_content_sha256']);
        $this->assertSame('ap-a-2026-cbt-80-candidate-v1', $base['pack']['slug']);
        $this->assertDatabaseCount('question_packs', 0);
        $this->assertDatabaseCount('learning_runs', 0);
    }

    public function test_every_source_change_has_a_fresh_proposal_snapshot_and_ledger(): void
    {
        $catalog = app(QuestionPackCatalogService::class);
        $base = $catalog->payload(ApExamCandidateRevisionPreviewService::BASE_KEY);
        $next = $catalog->payload(ApExamCandidateRevisionPreviewService::PREVIEW_KEY);
        $baseByKey = collect($base['questions'])->keyBy('external_key');
        $ledger = json_decode((string) file_get_contents(resource_path(
            'learning_review/ap-a-2026-v04-preview-change-ledger.json'
        )), true, 512, JSON_THROW_ON_ERROR);
        $expected = collect($ledger['changed_questions'])->keyBy('key');

        $this->assertSame(80, $ledger['counts']['questions']);
        $this->assertSame(35, $ledger['counts']['official_unchanged']);
        $this->assertSame(6, $ledger['counts']['changed_original_questions']);
        $this->assertSame(0, $ledger['counts']['answer_key_changes']);
        $this->assertFalse($ledger['publication_authorized']);
        $this->assertCount(6, $expected);

        foreach ($next['questions'] as $question) {
            $key = $question['external_key'];
            $before = $baseByKey->get($key);
            $this->assertNotNull($before);
            foreach (['external_key', 'source_type', 'source_reference',
                'response_schema', 'grading_rule', 'sort_order', 'difficulty',
                'is_active'] as $field) {
                $this->assertSame($before[$field] ?? null, $question[$field] ?? null,
                    "Unapproved change in {$key}/{$field}");
            }
            $delta = $expected->get($key);
            if ($delta === null) {
                $this->assertSame($before, $question,
                    "Non-proposed question altered: {$key}");
            } else {
                $this->assertSame('pending_independent_subject_review',
                    $delta['review_status']);
                $this->assertSame('not_independently_reviewed',
                    data_get($question, 'learning_metadata.curation.revision_review_status'));
                $this->assertSame($before['grading_rule']['answer'],
                    $question['grading_rule']['answer']);
            }
        }
    }

    public function test_admin_can_preview_hash_but_public_cannot_view_correct_answers(): void
    {
        $this->get(route('admin.question_packs.index'))
            ->assertForbidden()
            ->assertDontSee('data-ap-a-v04-preview', false);

        $this->withSession([AdminAccessService::SESSION_KEY => true])
            ->get(route('admin.question_packs.index'))
            ->assertOk()
            ->assertSee('data-ap-a-v04-preview', false)
            ->assertSee('data-ap-a-v04-integrity', false)
            ->assertSee('data-ap-a-v03-sha', false)
            ->assertSee('data-ap-a-v04-sha', false)
            ->assertSee('校正候補 v0.4（未承認・未公開）')
            ->assertSee('公開承認はなしです。')
            ->assertSee('誤答理由案45/45件')
            ->assertSee('公式原典照合の引継ぎ35/35件');

        $this->assertDatabaseCount('question_packs', 0);
        $this->assertDatabaseCount('learning_answer_events', 0);
    }

    public function test_import_of_new_slug_in_test_database_stays_draft_and_publication_fails_closed(): void
    {
        $admin = $this->withSession([AdminAccessService::SESSION_KEY => true]);
        $admin->post(route('admin.question_packs.import_bundled'), [
            'catalog_key' => ApExamCandidateRevisionPreviewService::PREVIEW_KEY,
        ])->assertRedirect(route('admin.question_packs.index'))
            ->assertSessionHasNoErrors();

        $next = QuestionPack::where('slug', 'ap-a-2026-cbt-80-candidate-v2')
            ->firstOrFail();
        $this->assertSame('draft', $next->status);
        $this->assertSame(80, $next->questions()->where('is_active', true)->count());
        $this->assertFalse(app(QuestionPackPublicationReadinessService::class)
            ->inspect($next)['publishable']);

        $exam = app(AdaptiveExamPackReadinessService::class)->inspect(
            $next, app(AdaptiveExamProfileRegistry::class)
                ->requireVerified('ap-a-cbt-2026-v1'),
        );
        $this->assertFalse($exam['ready']);
        $this->assertSame(
            app(ApExamCandidateRevisionPreviewService::class)
                ->inspect()['preview_content_sha256'],
            $exam['content_sha256'],
        );
        $this->assertStringContainsString('解説案が未監修',
            implode(' ', $exam['blocking']));
        $this->assertStringContainsString('SHA-256',
            implode(' ', $exam['blocking']));

        $admin->patch(route('admin.question_packs.status', $next), [
            'status' => 'published',
        ])->assertSessionHasErrors('status');
        $this->assertSame('draft', $next->fresh()->status);
        $this->assertDatabaseCount('learning_runs', 0);
        $this->assertDatabaseCount('learning_answer_events', 0);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }
}
