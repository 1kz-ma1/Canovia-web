<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\QuestionPack;
use App\Services\StudyOfficialSyllabusBridgeService;
use App\Services\StudyOfficialSyllabusReviewPacketService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StudyOfficialSyllabusReviewPacketV621Test extends TestCase
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

    /** @return array{QuestionPack, Question} */
    private function publishedPack(): array
    {
        $pack = QuestionPack::create([
            'slug' => 'v621-synthetic-'.Str::lower(Str::random(12)),
            'title' => 'Synthetic review inventory only',
            'exam_code' => 'AP',
            'subject' => '科目A',
            'version' => '1',
            'status' => 'published',
            'metadata' => ['match_terms' => ['AP']],
        ]);
        $question = Question::create([
            'question_pack_id' => $pack->id,
            'external_key' => 'v621-q-1',
            'source_type' => 'canovia_original',
            'prompt' => 'PRIVATE_SYNTHETIC_PROMPT_NOT_FOR_EXPORT',
            'response_schema' => [[
                'id' => 'answer', 'type' => 'single_choice',
                'label' => 'Answer', 'required' => true,
                'choices' => [
                    ['id' => 'A', 'label' => 'correct'],
                    ['id' => 'B', 'label' => 'wrong'],
                ],
            ]],
            'grading_rule' => ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A'],
            'learning_metadata' => ['concepts' => ['synthetic-network']],
            'explanation' => 'PRIVATE_SYNTHETIC_EXPLANATION_NOT_FOR_EXPORT',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return [$pack, $question];
    }

    public function test_packet_has_exact_source_and_hash_but_no_content_or_inferred_official_mapping(): void
    {
        [$pack, $question] = $this->publishedPack();

        $payload = app(StudyOfficialSyllabusReviewPacketService::class)->build($pack->slug);
        $this->assertSame('canovia.learning.syllabus_review_packet.v1', $payload['schema']);
        $this->assertSame('7.2', $payload['official_source']['source_version']);
        $this->assertSame('2026-01-08', $payload['official_source']['published_date']);
        $this->assertSame('source_only', $payload['catalog_state']);
        $this->assertSame('pending_independent_human_content_and_rights_review', $payload['review_status']);
        $this->assertSame([], $payload['scope_nodes']);
        $this->assertSame([], $payload['question_links']);
        $this->assertNull($payload['official_coverage_percent']);
        $this->assertNull($payload['official_coverage_denominator']);
        $this->assertFalse($payload['eligible_for_registry_import']);
        $this->assertFalse($payload['question_content_included']);
        $this->assertTrue($payload['read_only']);
        $this->assertCount(1, $payload['question_candidates']);
        $candidate = $payload['question_candidates'][0];
        $this->assertSame($pack->slug, $candidate['pack_slug']);
        $this->assertSame('v621-q-1', $candidate['question_external_key']);
        $this->assertSame(app(StudyOfficialSyllabusBridgeService::class)->snapshotHash($question),
            $candidate['question_snapshot_sha256']);
        $this->assertNull($candidate['proposed_scope_code']);
        $this->assertNull($candidate['source_page']);
        $this->assertSame('pending', $candidate['rights_review']);

        $text = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE_SYNTHETIC_PROMPT', $text);
        $this->assertStringNotContainsString('PRIVATE_SYNTHETIC_EXPLANATION', $text);
        $this->assertSame('published', $pack->fresh()->status);
        $this->assertSame('PRIVATE_SYNTHETIC_PROMPT_NOT_FOR_EXPORT', $question->fresh()->prompt);
    }

    public function test_snapshot_changes_when_question_changes_without_any_automatic_review(): void
    {
        [$pack, $question] = $this->publishedPack();
        $service = app(StudyOfficialSyllabusReviewPacketService::class);
        $before = $service->build($pack->slug)['question_candidates'][0]['question_snapshot_sha256'];
        $question->update(['grading_rule' =>
            ['type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'B']]);
        $after = $service->build($pack->slug);
        $this->assertNotSame($before, $after['question_candidates'][0]['question_snapshot_sha256']);
        $this->assertSame([], $after['question_links']);
        $this->assertFalse($after['eligible_for_registry_import']);
    }

    public function test_unpublished_non_ap_unrouted_and_mutated_source_are_rejected(): void
    {
        [$pack] = $this->publishedPack();
        $service = app(StudyOfficialSyllabusReviewPacketService::class);

        foreach (['review', 'draft', 'retired'] as $status) {
            $pack->update(['status' => $status]);
            try {
                $service->build($pack->slug);
                $this->fail('Unpublished pack was accepted.');
            } catch (DomainException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
        $pack->update(['status' => 'published', 'exam_code' => 'FE']);
        $this->expectException(DomainException::class);
        $service->build($pack->slug);
    }

    public function test_unrouted_pack_and_wrong_registry_version_are_rejected(): void
    {
        [$pack] = $this->publishedPack();
        $service = app(StudyOfficialSyllabusReviewPacketService::class);

        $pack->update(['metadata' => ['match_terms' => ['not-ap']]]);
        try {
            $service->build($pack->slug);
            $this->fail('Unrouted pack was accepted.');
        } catch (DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $pack->update(['metadata' => ['match_terms' => ['AP']]]);
        config(['study_official_syllabus.ap_subject_a_2026.source_version' => '2027-draft']);
        $this->expectException(DomainException::class);
        $service->build($pack->slug);
    }

    public function test_operator_command_returns_json_in_testing_and_never_mutates_pack(): void
    {
        [$pack] = $this->publishedPack();
        $exit = Artisan::call('study:syllabus-review-packet', ['--pack' => $pack->slug]);
        $this->assertSame(0, $exit);
        $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($pack->slug, $output['pack']['slug']);
        $this->assertSame([], $output['question_links']);
        $this->assertSame('published', $pack->fresh()->status);

        $this->assertSame(1, Artisan::call('study:syllabus-review-packet',
            ['--pack' => 'unknown-v621-review-pack']));
        $this->assertStringNotContainsString('PRIVATE_SYNTHETIC_PROMPT', Artisan::output());
    }
}
