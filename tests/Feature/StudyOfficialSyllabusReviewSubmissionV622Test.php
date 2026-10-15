<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\QuestionPack;
use App\Services\StudyOfficialSyllabusReviewPacketService;
use App\Services\StudyOfficialSyllabusReviewSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StudyOfficialSyllabusReviewSubmissionV622Test extends TestCase
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
            'slug' => 'v622-'.Str::lower(Str::random(12)),
            'title' => 'synthetic AP reviewer fixture',
            'exam_code' => 'AP',
            'subject' => '科目A',
            'version' => '1',
            'status' => 'published',
            'metadata' => ['match_terms' => ['AP']],
        ]);
        $question = Question::create([
            'question_pack_id' => $pack->id,
            'external_key' => 'sample-q1',
            'source_type' => 'canovia_original',
            'prompt' => 'SECRET_TEST_QUESTION_DO_NOT_EXPORT',
            'response_schema' => [[
                'id' => 'answer', 'type' => 'single_choice',
                'label' => 'Response', 'required' => true,
                'choices' => [
                    ['id' => 'A', 'label' => 'valid'],
                    ['id' => 'B', 'label' => 'invalid'],
                ],
            ]],
            'grading_rule' => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A'],
            'learning_metadata' => ['concepts' => ['synthetic']],
            'explanation' => 'SECRET_TEST_EXPLANATION_DO_NOT_EXPORT',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        return [$pack, $question];
    }

    private function reviewer(string $id, string $decision = 'confirmed'): array
    {
        return [
            'reviewer_id' => $id,
            'reviewed_at' => today()->toDateString(),
            'decision' => $decision,
        ];
    }

    private function submission(QuestionPack $pack): array
    {
        $packet = app(StudyOfficialSyllabusReviewPacketService::class)->build($pack->slug);
        $candidate = $packet['question_candidates'][0];

        return [
            'schema' => 'canovia.learning.syllabus_review_submission.v1',
            'official_source' => $packet['official_source'],
            'pack' => $packet['pack'],
            'review_stage' => 'human_review_submission',
            'scope_nodes' => [[
                'code' => 'SYNTHETIC-NW-001',
                'label' => '人工テスト用の非公式項目',
                'source_version' => '7.2',
                'source_page' => 2,
                'source_locator' => 'Synthetic fixture: page 2',
                'transcription_review' => $this->reviewer('transcriber-1'),
                'independent_review' => $this->reviewer('reviewer-2'),
            ]],
            'question_links' => [[
                'scope_code' => 'SYNTHETIC-NW-001',
                'source_version' => '7.2',
                'pack_slug' => $candidate['pack_slug'],
                'pack_version' => $candidate['pack_version'],
                'question_external_key' => $candidate['question_external_key'],
                'question_snapshot_sha256' => $candidate['question_snapshot_sha256'],
                'content_review' => $this->reviewer('reviewer-2'),
                'rights_review' => $this->reviewer('rights-reviewer-3', 'permitted_for_intended_use'),
                'rights_evidence_ref' => 'SYNTHETIC-LOCAL-EVIDENCE-001',
            ]],
        ];
    }

    private function inspect(QuestionPack $pack, array $submission): array
    {
        return app(StudyOfficialSyllabusReviewSubmissionService::class)
            ->inspect($pack->slug, $submission);
    }

    public function test_valid_structure_never_means_independent_human_signoff_or_registry_import(): void
    {
        [$pack, $question] = $this->fixture();
        $result = $this->inspect($pack, $this->submission($pack));

        $this->assertSame('structurally_complete_manual_evidence_review_required', $result['status']);
        $this->assertSame([], $result['error_codes']);
        $this->assertSame(1, $result['proposed_scope_count']);
        $this->assertSame(1, $result['proposed_link_count']);
        $this->assertNull($result['official_coverage_percent']);
        $this->assertNull($result['official_coverage_denominator']);
        $this->assertFalse($result['independent_human_review_verified']);
        $this->assertFalse($result['rights_independently_verified']);
        $this->assertFalse($result['eligible_for_registry_import']);
        $this->assertFalse($result['production_authorized']);
        $this->assertTrue($result['read_only']);
        $this->assertSame([], config('study_official_syllabus.ap_subject_a_2026.scope_nodes'));
        $this->assertSame([], config('study_official_syllabus.ap_subject_a_2026.question_links'));
        $this->assertSame('published', $pack->fresh()->status);
        $this->assertSame('SECRET_TEST_QUESTION_DO_NOT_EXPORT', $question->fresh()->prompt);
        $this->assertStringNotContainsString('SECRET_TEST_', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_stale_question_hash_and_unknown_question_fail_closed(): void
    {
        [$pack, $question] = $this->fixture();
        $original = $this->submission($pack);
        $question->update(['prompt' => 'synthetic question modified after review']);
        $stale = $this->inspect($pack, $original);
        $this->assertSame('blocked', $stale['status']);
        $this->assertContains('stale_question_snapshot', $stale['error_codes']);

        $original['question_links'][0]['question_external_key'] = 'missing-question';
        $unknown = $this->inspect($pack, $original);
        $this->assertContains('invalid_question_review', $unknown['error_codes']);
    }

    public function test_same_person_unreviewed_rights_and_bad_page_fail_closed(): void
    {
        [$pack] = $this->fixture();
        $submission = $this->submission($pack);
        $submission['scope_nodes'][0]['independent_review']['reviewer_id'] = 'transcriber-1';
        $submission['question_links'][0]['rights_review']['decision'] = 'pending';
        $submission['scope_nodes'][0]['source_page'] = 0;
        $result = $this->inspect($pack, $submission);
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('invalid_scope_review', $result['error_codes']);
        $this->assertContains('invalid_question_review', $result['error_codes']);

        $submission = $this->submission($pack);
        $submission['question_links'][0]['rights_review']['reviewer_id'] = 'reviewer-2';
        $this->assertContains('invalid_question_review',
            $this->inspect($pack, $submission)['error_codes']);
    }

    public function test_duplicate_node_and_duplicate_link_are_rejected(): void
    {
        [$pack] = $this->fixture();
        $submission = $this->submission($pack);
        $submission['scope_nodes'][] = $submission['scope_nodes'][0];
        $submission['question_links'][] = $submission['question_links'][0];
        $errors = $this->inspect($pack, $submission)['error_codes'];
        $this->assertContains('duplicate_scope_code', $errors);
        $this->assertContains('duplicate_question_link', $errors);
    }

    public function test_forged_source_extra_approval_flags_wrong_pack_and_wrong_version_are_denied(): void
    {
        [$pack] = $this->fixture();
        $submission = $this->submission($pack);
        $submission['official_source']['source_version'] = '2027-draft';
        $submission['pack']['slug'] = 'wrong-slug';
        $submission['official_coverage_percent'] = 100;
        $result = $this->inspect($pack, $submission);
        $this->assertSame('blocked', $result['status']);
        $this->assertContains('source_or_pack_mismatch', $result['error_codes']);
        $this->assertContains('invalid_submission_schema', $result['error_codes']);
        $this->assertNull($result['official_coverage_percent']);

        $submission = $this->submission($pack);
        $submission['scope_nodes'][0]['source_version'] = '2027-draft';
        $this->assertContains('invalid_scope_review', $this->inspect($pack, $submission)['error_codes']);
    }

    public function test_unpublished_pack_and_source_registry_mutation_are_not_accepted(): void
    {
        [$pack] = $this->fixture();
        $submission = $this->submission($pack);
        $pack->update(['status' => 'review']);
        $this->expectException(\DomainException::class);
        $this->inspect($pack, $submission);
    }

    public function test_operator_cli_is_read_only_and_outputs_no_source_material(): void
    {
        [$pack] = $this->fixture();
        $submission = $this->submission($pack);
        $path = tempnam(sys_get_temp_dir(), 'syllabus-v622-');
        $this->assertNotFalse($path);
        try {
            file_put_contents($path, json_encode($submission, JSON_THROW_ON_ERROR));
            $exit = Artisan::call('study:syllabus-review-preflight', [
                '--pack' => $pack->slug,
                '--review' => $path,
            ]);
            $this->assertSame(0, $exit);
            $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('structurally_complete_manual_evidence_review_required',
                $report['status']);
            $this->assertFalse($report['eligible_for_registry_import']);
            $this->assertStringNotContainsString('SECRET_TEST_', Artisan::output());

            file_put_contents($path, '{bad-json');
            $this->assertSame(1, Artisan::call('study:syllabus-review-preflight', [
                '--pack' => $pack->slug,
                '--review' => $path,
            ]));
            $this->assertStringNotContainsString($path, Artisan::output());
        } finally {
            unlink($path);
        }
        $this->assertSame('published', $pack->fresh()->status);
        $this->assertSame([], config('study_official_syllabus.ap_subject_a_2026.scope_nodes'));
    }
}
