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
        $this->assertSame('0.2.0-review-required', $candidate['pack']['version']);
        $this->assertSame('pending_human_subject_review',
            $candidate['pack']['metadata']['explanation_review_state']);
        $this->assertSame(35, $candidate['pack']['metadata']['official_explanation_draft_count']);
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
        $newExplanations = [];
        $missingExplanations = [];
        foreach ($candidate['questions'] as $index => $question) {
            $key = $question['external_key'];
            $origin = data_get($question, 'learning_metadata.curation.source_pack_slug');
            $original = $sources[$origin] ?? null;
            $this->assertNotNull($original, "Unknown original Pack for {$key}");
            $reference = $original->get($key);
            $this->assertNotNull($reference, "Lost provenance for {$key}");

            // The source question, options, grading key and provenance
            // must remain byte-equivalent. Only explanation can be a new
            // Canovia-authored unreviewed draft for official questions.
            foreach (['source_type','source_reference','prompt','response_schema',
                'grading_rule','difficulty','is_active'] as $field) {
                $this->assertSame($reference[$field] ?? null,
                    $question[$field] ?? null, "Source change: {$key}/{$field}");
            }
            if ($origin === 'ap-a-ipa-2025-autumn-official-v1') {
                $this->assertNull($reference['explanation'] ?? null);
                $this->assertSame('canovia_independent_draft',
                    data_get($question, 'learning_metadata.curation.explanation_origin'));
                $this->assertSame('awaiting_subject_expert_check',
                    data_get($question, 'learning_metadata.curation.explanation_review_status'));
                $this->assertSame('ipa_2025_autumn_official_answer_pdf',
                    data_get($question, 'learning_metadata.curation.answer_key_source'));
                $this->assertGreaterThanOrEqual(90, mb_strlen($question['explanation'] ?? ''));
                $this->assertStringStartsWith('正答は'.$question['grading_rule']['answer'],
                    $question['explanation']);
                $newExplanations[] = $key;
            } else {
                $this->assertSame($reference['explanation'] ?? null, $question['explanation'] ?? null,
                    "Original Canovia explanation was unexpectedly rewritten: {$key}");
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
            if (trim((string) ($question['explanation'] ?? '')) === '') {
                $missingExplanations[] = $key;
            }

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
        // All formerly missing official explanations are now distinct
        // Canovia drafts. Their presence does NOT mean expert review.
        $this->assertSame([], $missingExplanations);
        $this->assertCount(35, $newExplanations);
        $this->assertCount(35, array_filter($newExplanations,
            fn (string $key) => str_starts_with($key, 'ipa-2025-autumn-ap-am-q')));
        $this->assertGreaterThan(10, count($domains));
        $this->assertContains('テクノロジ', array_keys($domains));
        $this->assertContains('マネジメント', array_keys($domains));
        $this->assertContains('ストラテジ', array_keys($domains));
        $this->assertSame(0, QuestionPack::count());
    }

    public function test_key_calculation_explanations_show_the_reasoning_steps_without_changing_source_answers(): void
    {
        $items = collect(app(QuestionPackCatalogService::class)
            ->payload(self::CANDIDATE)['questions'])
            ->keyBy(fn (array $q) => (int) data_get($q,
                'learning_metadata.provenance.question_number', 0));

        $expectations = [
            6 => ['m/2', 'n/(2m)'],
            11 => ['32,000', '640TFLOPS'],
            15 => ['12−1＝11秒', 'Cを2〜5秒'],
            23 => ['500,000Hz', '300マイクロ秒'],
            25 => ['40kHz', '25マイクロ秒'],
        ];
        foreach ($expectations as $questionNumber => $parts) {
            $question = $items->get($questionNumber);
            $this->assertNotNull($question, "Missing official q{$questionNumber}");
            $explanation = (string) ($question['explanation'] ?? '');
            foreach ($parts as $part) {
                $this->assertStringContainsString($part, $explanation,
                    "Missing worked step in official q{$questionNumber}");
            }
        }
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
        $this->assertStringContainsString('解説案が未監修',
            implode(' ', $exam['blocking']));
        $this->assertStringNotContainsString('解説未登録の問題が',
            implode(' ', $exam['blocking']));

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
