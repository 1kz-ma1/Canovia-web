<?php

namespace Tests\Feature;

use App\Models\QuestionPack;
use App\Services\AdaptiveExamPackReadinessService;
use App\Services\AdaptiveExamProfileRegistry;
use App\Services\AdminAccessService;
use App\Services\QuestionPackCatalogService;
use App\Services\QuestionPackPublicationReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApExamCandidateBundleAuditTest extends TestCase
{
    use RefreshDatabase;

    private const CANDIDATE = 'ap/ap-a-2026-cbt-80-candidate-v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['session.driver' => 'array',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null]);
    }

    public function test_mixed_source_candidate_has_exactly_80_reproducible_four_choice_questions(): void
    {
        $catalog = app(QuestionPackCatalogService::class);
        $candidate = $catalog->payload(self::CANDIDATE);
        $this->assertSame('1.0', $candidate['schema_version']);
        $this->assertSame('AP', $candidate['pack']['exam_code']);
        $this->assertSame('科目A', $candidate['pack']['subject']);
        $this->assertCount(80, $candidate['questions']);
        $this->assertFalse($candidate['pack']['downloadable']);
        $this->assertSame('pending_human_content_and_rights_review',
            $candidate['pack']['metadata']['review_state']);
        $this->assertArrayNotHasKey('exam_simulation_review', $candidate['pack']['metadata']);
        $this->assertSame('ap-a-cbt-2026-v1',
            $candidate['pack']['metadata']['exam_simulation_profile_key']);

        $sourceKeys = [
            'ap-a-canovia-core-v1' =>
                $catalog->payload('ap/ap-a-canovia-core-v1'),
            'ap-a-ipa-2025-autumn-official-v1' =>
                $catalog->payload('ap/ap-a-ipa-2025-autumn-official-v1'),
        ];
        $sources = [];
        foreach ($sourceKeys as $slug => $payload) {
            $sources[$slug] = collect($payload['questions'])->keyBy('external_key');
        }

        $uniqueKeys = [];
        $uniquePrompts = [];
        $origins = [];
        $domains = [];
        foreach ($candidate['questions'] as $index => $question) {
            $key = $question['external_key'];
            $origin = data_get($question, 'learning_metadata.curation.source_pack_slug');
            $original = $sources[$origin] ?? null;
            $this->assertNotNull($original, "Unknown original Pack for {$key}");
            $reference = $original->get($key);
            $this->assertNotNull($reference, "Lost provenance for {$key}");

            // The assembled copy may change only the order and add an
            // explicit source pointer. Never rewrite an answer or the
            // published IPA text in the merging process.
            foreach (['source_type','source_reference','prompt','response_schema',
                'grading_rule','explanation','difficulty','is_active'] as $field) {
                $this->assertSame($reference[$field] ?? null,
                    $question[$field] ?? null, "Source change: {$key}/{$field}");
            }
            $this->assertSame($index + 1, $question['sort_order']);
            $this->assertSame($key,
                data_get($question, 'learning_metadata.curation.source_external_key'));
            $this->assertSame(
                data_get($reference, 'learning_metadata.concepts'),
                data_get($question, 'learning_metadata.concepts'),
            );

            $answer = collect($question['response_schema'])->firstWhere('id', 'answer');
            $choices = $answer['choices'];
            $this->assertSame('single_choice', $answer['type']);
            $this->assertSame(['ア','イ','ウ','エ'],
                array_column($choices, 'id'));
            $this->assertSame('exact_choice', $question['grading_rule']['type']);
            $this->assertContains($question['grading_rule']['answer'],
                array_column($choices, 'id'));
            $this->assertNotEmpty($question['explanation']);

            $normalizedPrompt = mb_strtolower(preg_replace(
                '/[\s　、。・.,，．:：;；!?？！（）()［］「」『』]+/u', '', $question['prompt'],
            ));
            $this->assertNotContains($key, $uniqueKeys, "Duplicate source ID: {$key}");
            $this->assertNotContains($normalizedPrompt, $uniquePrompts,
                "Duplicate exact prompt: {$key}");
            $uniqueKeys[] = $key;
            $uniquePrompts[] = $normalizedPrompt;
            $origins[$origin] = ($origins[$origin] ?? 0) + 1;
            $domain = data_get($question, 'learning_metadata.concepts.0', '');
            $domains[$domain] = ($domains[$domain] ?? 0) + 1;
        }

        $this->assertSame(35, $origins['ap-a-ipa-2025-autumn-official-v1']);
        $this->assertSame(45, $origins['ap-a-canovia-core-v1']);
        $this->assertGreaterThan(10, count($domains));
        $this->assertContains('テクノロジ', array_keys($domains));
        $this->assertContains('マネジメント', array_keys($domains));
        $this->assertContains('ストラテジ', array_keys($domains));
        $this->assertSame(0, QuestionPack::count());
    }

    public function test_bundled_candidate_imports_as_draft_and_cannot_be_published_until_explicit_review(): void
    {
        $this->get(route('admin.question_packs.index'))->assertForbidden();
        $admin = $this->withSession([AdminAccessService::SESSION_KEY => true]);
        $admin->get(route('admin.question_packs.index'))->assertOk()
            ->assertSee('ap-a-2026-cbt-80-candidate-v1');

        $admin->post(route('admin.question_packs.import_bundled'), [
            'catalog_key' => self::CANDIDATE,
        ])->assertRedirect(route('admin.question_packs.index'))
            ->assertSessionHasNoErrors();

        $pack = QuestionPack::where('slug', 'ap-a-2026-cbt-80-candidate-v1')
            ->firstOrFail();
        $this->assertSame('draft', $pack->status);
        $this->assertSame(80, $pack->questions()->where('is_active', true)->count());
        $this->assertFalse(app(QuestionPackPublicationReadinessService::class)
            ->inspect($pack)['publishable']);
        $exam = app(AdaptiveExamPackReadinessService::class)->inspect(
            $pack, app(AdaptiveExamProfileRegistry::class)
                ->requireVerified('ap-a-cbt-2026-v1'),
        );
        $this->assertFalse($exam['ready']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $exam['content_sha256']);
        $this->assertStringContainsString('SHA-256', implode(' ', $exam['blocking']));

        $admin->patch(route('admin.question_packs.status', $pack), [
            'status' => 'published',
        ])->assertSessionHasErrors('status');
        $this->assertSame('draft', $pack->fresh()->status);
        $admin->get(route('admin.question_packs.index'))
            ->assertOk()
            ->assertSee('data-exam-content-sha256', false)
            ->assertSee('模試セット：未完成・未承認');
        $this->assertDatabaseCount('learning_runs', 0);
        $this->assertDatabaseCount('learning_answer_events', 0);
        $this->assertDatabaseCount('study_practice_attempts', 0);
    }
}
