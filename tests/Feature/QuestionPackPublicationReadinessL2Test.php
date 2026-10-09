<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\QuestionPack;
use App\Services\AdminAccessService;
use App\Services\QuestionPackPublicationReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class QuestionPackPublicationReadinessL2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['session.driver' => 'array']);
    }

    private function draft(string $slug = 'ap-a-publication-preflight'): QuestionPack
    {
        return QuestionPack::create([
            'slug' => $slug,
            'title' => 'AP科目A 管理テスト',
            'exam_code' => 'AP',
            'subject' => '科目A',
            'version' => '1',
            'status' => 'draft',
            'downloadable' => true,
            'metadata' => ['match_terms' => ['応用情報']],
        ]);
    }

    private function choice(QuestionPack $pack, string $key, array $overrides = []): Question
    {
        return Question::create(array_merge([
            'question_pack_id' => $pack->id,
            'external_key' => $key,
            'source_type' => 'official',
            'source_reference' => 'IPAの年度・問番号',
            'prompt' => '説明が正しいものを選択してください。',
            'response_schema' => [[
                'id' => 'answer', 'type' => 'single_choice',
                'label' => '回答', 'required' => true,
                'choices' => [
                    ['id' => 'A', 'label' => '正しい'],
                    ['id' => 'B', 'label' => '誤り'],
                ],
            ]],
            'grading_rule' => [
                'type' => 'exact_choice', 'field_id' => 'answer', 'answer' => 'A',
            ],
            'learning_metadata' => ['concepts' => ['ネットワーク']],
            'explanation' => '選択肢Aの理由を説明します。',
            'difficulty' => 2, 'sort_order' => 1, 'is_active' => true,
        ], $overrides));
    }

    public function test_readiness_counts_only_active_and_genuinely_supported_questions(): void
    {
        $pack = $this->draft();
        $this->choice($pack, 'valid');
        $this->choice($pack, 'inactive', ['is_active' => false]);
        $this->choice($pack, 'unsupported', [
            'grading_rule' => ['type' => 'ai_rubric', 'field_id' => 'answer', 'rubric' => '要レビュー'],
            'explanation' => null,
        ]);
        $this->choice($pack, 'unattributed', [
            'source_reference' => null,
        ]);

        $ready = app(QuestionPackPublicationReadinessService::class)->inspect($pack);

        $this->assertSame(3, $ready['active_count']);
        $this->assertSame(2, $ready['one_question_count']);
        $this->assertSame(1, $ready['missing_explanation_count']);
        $this->assertSame(1, $ready['missing_attribution_count']);
        $this->assertFalse($ready['publishable']);
        $this->assertNotEmpty($ready['blocking']);
        $this->assertNotEmpty($ready['warnings']);
        $this->assertSame('draft', $pack->fresh()->status);
    }

    public function test_admin_preflight_blocks_missing_source_and_renders_reason_without_publishing(): void
    {
        $pack = $this->draft();
        $this->choice($pack, 'without-official-source', ['source_reference' => null]);

        $this->get(route('admin.question_packs.index'))->assertForbidden();

        $this->withSession([AdminAccessService::SESSION_KEY => true])
            ->get(route('admin.question_packs.index'))
            ->assertOk()
            ->assertSee('data-question-pack-readiness="'.$pack->id.'"', false)
            ->assertSee('公開条件：未充足')
            ->assertSee('公式・ライセンス・派生問題の出典がない問題が1問あります。');

        $this->withSession([AdminAccessService::SESSION_KEY => true])
            ->from(route('admin.question_packs.index'))
            ->patch(route('admin.question_packs.status', $pack), ['status' => 'published'])
            ->assertRedirect(route('admin.question_packs.index'))
            ->assertSessionHasErrors('status');

        $this->assertSame('draft', $pack->fresh()->status);
        $this->assertDatabaseCount('learning_runs', 0);
    }

    public function test_admin_can_publish_legacy_only_pack_without_claiming_single_question_compatibility(): void
    {
        $pack = $this->draft('ap-numeric-only');
        $this->choice($pack, 'numeric', [
            'source_type' => 'canovia_original',
            'source_reference' => null,
            'response_schema' => [[
                'id' => 'answer', 'type' => 'number',
                'label' => '数値', 'required' => true,
                'choices' => [],
            ]],
            'grading_rule' => [
                'type' => 'numeric_tolerance', 'field_id' => 'answer',
                'answer' => 42, 'tolerance' => 0,
            ],
            'explanation' => '',
        ]);

        $this->withSession([AdminAccessService::SESSION_KEY => true])
            ->get(route('admin.question_packs.index'))
            ->assertOk()
            ->assertSee('1問ずつ学習では未対応です')
            ->assertSee('解説未登録が1問あります。')
            ->assertSee('公開条件：充足');

        $this->withSession([AdminAccessService::SESSION_KEY => true])
            ->patch(route('admin.question_packs.status', $pack), ['status' => 'published'])
            ->assertSessionHasNoErrors();

        $this->assertSame('published', $pack->fresh()->status);
        $this->assertSame(0, app(QuestionPackPublicationReadinessService::class)
            ->inspect($pack)['one_question_count']);
        $this->assertDatabaseCount('learning_runs', 0);
    }

    public function test_missing_grading_metadata_and_routing_still_block_publication(): void
    {
        $pack = $this->draft('missing-publishing-contract');
        $pack->update(['exam_code' => null, 'metadata' => ['match_terms' => []]]);
        $this->choice($pack, 'incomplete', [
            'grading_rule' => null,
            'learning_metadata' => [],
        ]);

        $result = app(QuestionPackPublicationReadinessService::class)->inspect($pack);
        $this->assertFalse($result['publishable']);
        $this->assertCount(3, $result['blocking']);

        $this->withSession([AdminAccessService::SESSION_KEY => true])
            ->from(route('admin.question_packs.index'))
            ->patch(route('admin.question_packs.status', $pack), ['status' => 'published'])
            ->assertSessionHasErrors('status');
        $this->assertSame('draft', $pack->fresh()->status);
    }
}
