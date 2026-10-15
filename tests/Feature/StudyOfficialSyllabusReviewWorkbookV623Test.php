<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\QuestionPack;
use App\Services\StudyOfficialSyllabusReviewSubmissionService;
use App\Services\StudyOfficialSyllabusReviewWorkbookService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StudyOfficialSyllabusReviewWorkbookV623Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'session.driver' => 'array',
            'cache.default' => 'array',
            'native_ai.driver' => 'disabled',
        ]);
    }

    /** @return array{QuestionPack,Question} */
    private function fixture(): array
    {
        $pack = QuestionPack::create([
            'slug' => 'v623-'.Str::lower(Str::random(12)),
            'title' => 'Synthetic AP review worksheet source',
            'exam_code' => 'AP',
            'subject' => '科目A',
            'version' => '1',
            'status' => 'published',
            'metadata' => ['match_terms' => ['AP']],
        ]);
        $question = Question::create([
            'question_pack_id' => $pack->id,
            'external_key' => 'synthetic-question-1',
            'source_type' => 'canovia_original',
            'prompt' => 'SECRET_QUESTION_BODY_NOT_FOR_HANDOFF',
            'response_schema' => [[
                'id' => 'answer',
                'type' => 'single_choice',
                'label' => 'Response',
                'required' => true,
                'choices' => [
                    ['id' => 'A', 'label' => 'one'],
                    ['id' => 'B', 'label' => 'two'],
                ],
            ]],
            'grading_rule' => [
                'type' => 'exact_choice',
                'field_id' => 'answer',
                'answer' => 'A',
            ],
            'learning_metadata' => ['concepts' => ['synthetic']],
            'explanation' => 'SECRET_EXPLANATION_NOT_FOR_HANDOFF',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return [$pack, $question];
    }

    public function test_workbook_has_real_question_identity_but_all_official_and_review_fields_are_pending(): void
    {
        [$pack, $question] = $this->fixture();
        $workbook = app(StudyOfficialSyllabusReviewWorkbookService::class)->build($pack->slug);

        $this->assertSame('canovia.learning.syllabus_review_workbook.v1', $workbook['schema']);
        $this->assertSame('7.2', $workbook['official_source']['source_version']);
        $this->assertSame('draft_pending_independent_human_reviews', $workbook['state']);
        $this->assertSame([], $workbook['submission_draft']['scope_nodes']);
        $this->assertSame([], $workbook['submission_draft']['question_links']);
        $this->assertSame('draft_pending_review', $workbook['submission_draft']['review_stage']);
        $this->assertCount(1, $workbook['question_candidate_rows']);
        $row = $workbook['question_candidate_rows'][0];
        $this->assertSame('synthetic-question-1', $row['question_external_key']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['question_snapshot_sha256']);
        $this->assertNull($row['proposed_scope_code']);
        $this->assertNull($row['source_page']);
        $this->assertNull($row['source_locator']);
        $this->assertNull($row['content_review']['reviewer_id']);
        $this->assertSame('pending', $row['content_review']['decision']);
        $this->assertNull($row['rights_review']['reviewed_at']);
        $this->assertSame('pending', $row['rights_review']['decision']);
        $this->assertNull($row['rights_evidence_ref']);
        $this->assertFalse($workbook['independent_human_review_verified']);
        $this->assertFalse($workbook['rights_independently_verified']);
        $this->assertFalse($workbook['eligible_for_registry_import']);
        $this->assertFalse($workbook['question_content_included']);
        $this->assertNull($workbook['official_coverage_percent']);
        $this->assertNull($workbook['official_coverage_denominator']);
        $this->assertTrue($workbook['read_only']);
        $this->assertSame([], config('study_official_syllabus.ap_subject_a_2026.scope_nodes'));
        $this->assertSame([], config('study_official_syllabus.ap_subject_a_2026.question_links'));
        $this->assertSame('published', $pack->fresh()->status);
        $this->assertSame('SECRET_QUESTION_BODY_NOT_FOR_HANDOFF', $question->fresh()->prompt);
        $json = json_encode($workbook, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('SECRET_QUESTION_BODY', $json);
        $this->assertStringNotContainsString('SECRET_EXPLANATION', $json);

        // A blank worksheet cannot be laundered into a V6.22 approved submission.
        $preflight = app(StudyOfficialSyllabusReviewSubmissionService::class)
            ->inspect($pack->slug, $workbook['submission_draft']);
        $this->assertSame('blocked', $preflight['status']);
        $this->assertFalse($preflight['eligible_for_registry_import']);
    }

    public function test_regenerating_after_question_change_replaces_hash_but_does_not_invent_review(): void
    {
        [$pack, $question] = $this->fixture();
        $workbooks = app(StudyOfficialSyllabusReviewWorkbookService::class);
        $before = $workbooks->build($pack->slug);
        $question->update(['grading_rule' => [
            'type' => 'exact_choice',
            'field_id' => 'answer',
            'answer' => 'B',
        ]]);
        $after = $workbooks->build($pack->slug);
        $this->assertNotSame(
            $before['question_candidate_rows'][0]['question_snapshot_sha256'],
            $after['question_candidate_rows'][0]['question_snapshot_sha256'],
        );
        $this->assertSame('pending', $after['question_candidate_rows'][0]['content_review']['decision']);
        $this->assertSame([], $after['submission_draft']['question_links']);
    }

    public function test_unpublished_pack_or_changed_source_registry_fails_closed(): void
    {
        [$pack] = $this->fixture();
        $workbooks = app(StudyOfficialSyllabusReviewWorkbookService::class);
        $pack->update(['status' => 'draft']);
        try {
            $workbooks->build($pack->slug);
            $this->fail('Draft Pack must not be eligible.');
        } catch (DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $pack->update(['status' => 'published']);
        config(['study_official_syllabus.ap_subject_a_2026.source_version' => '2027-draft']);
        $this->expectException(DomainException::class);
        $workbooks->build($pack->slug);
    }

    public function test_command_stdout_contains_no_private_content_and_no_database_mutation(): void
    {
        [$pack] = $this->fixture();
        $this->assertSame(0, Artisan::call('study:syllabus-review-workbook', [
            '--pack' => $pack->slug,
        ]));
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($pack->slug, $result['pack']['slug']);
        $this->assertSame([], $result['submission_draft']['scope_nodes']);
        $this->assertStringNotContainsString('SECRET_', Artisan::output());
        $this->assertSame(1, Artisan::call('study:syllabus-review-workbook', [
            '--pack' => 'unknown-synthetic-pack',
        ]));
        $this->assertStringNotContainsString('SECRET_', Artisan::output());
        $this->assertSame('published', $pack->fresh()->status);
    }

    public function test_command_rejects_non_sqlite_before_any_pack_read(): void
    {
        // RefreshDatabase opens a SQLite transaction in setUp. Restore the
        // default connection BEFORE teardown so subsequent suites keep their
        // isolated transaction manager and cannot accidentally target MySQL.
        $previous = config('database.default');
        try {
            config(['database.default' => 'mysql']);
            $this->assertSame(1, Artisan::call('study:syllabus-review-workbook', [
                '--pack' => 'nonexistent-pack',
            ]));
            $this->assertStringContainsString('local/testing SQLite', Artisan::output());
        } finally {
            config(['database.default' => $previous]);
        }
    }
}
