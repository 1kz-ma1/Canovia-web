<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\QuestionPack;
use App\Models\Task;
use App\Models\User;
use App\Services\AdminAccessService;
use App\Services\QuestionBankCoverageService;
use App\Services\QuestionBankStudyPracticeQuestionProvider;
use App\Services\QuestionPackCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IpaOfficialQuestionPackV562Test extends TestCase
{
    use RefreshDatabase;

    public function test_official_ipa_pack_is_discoverable_and_imports_with_provenance(): void
    {
        $catalog = app(QuestionPackCatalogService::class);
        $item = $catalog->all()->firstWhere(
            'key',
            'ap/ap-a-ipa-2025-autumn-official-v1',
        );

        $this->assertNotNull($item);
        $this->assertSame('AP', $item['exam_code']);
        $this->assertSame('科目A', $item['subject']);
        $this->assertSame(35, $item['question_count']);

        $this->importBundled(
            'ap/ap-a-ipa-2025-autumn-official-v1',
        );

        $pack = QuestionPack::query()
            ->where('slug', 'ap-a-ipa-2025-autumn-official-v1')
            ->firstOrFail();

        $this->assertSame('draft', $pack->status);
        $this->assertSame(35, $pack->questions()->count());
        $this->assertSame(
            100,
            (int) data_get($pack->metadata, 'selection_priority'),
        );
        $this->assertSame(
            'official_past_exam_curated',
            data_get($pack->metadata, 'content_kind'),
        );

        foreach ($pack->questions as $question) {
            $this->assertSame('official', $question->source_type);
            $this->assertNotEmpty($question->source_reference);
            $this->assertStringContainsString(
                '令和7年度 秋期 応用情報技術者試験 午前 問',
                $question->source_reference,
            );
            $this->assertSame(
                '独立行政法人情報処理推進機構（IPA）',
                data_get(
                    $question->learning_metadata,
                    'provenance.publisher',
                ),
            );
            $this->assertSame(
                'AP',
                data_get(
                    $question->learning_metadata,
                    'provenance.exam_code',
                ),
            );
            $this->assertSame(
                '午前',
                data_get(
                    $question->learning_metadata,
                    'provenance.source_section',
                ),
            );
            $this->assertNotEmpty(data_get(
                $question->learning_metadata,
                'provenance.problem_url',
            ));
            $this->assertNotEmpty(data_get(
                $question->learning_metadata,
                'provenance.answer_url',
            ));
        }
    }

    public function test_curated_official_answers_match_published_answer_key(): void
    {
        $this->importBundled(
            'ap/ap-a-ipa-2025-autumn-official-v1',
        );

        $pack = QuestionPack::query()
            ->where('slug', 'ap-a-ipa-2025-autumn-official-v1')
            ->firstOrFail();

        $answers = [
            3 => 'イ',
            4 => 'ウ',
            5 => 'イ',
            6 => 'イ',
            7 => 'ア',
            8 => 'エ',
            9 => 'イ',
            11 => 'イ',
            12 => 'ウ',
            13 => 'ア',
            14 => 'イ',
            15 => 'エ',
            16 => 'イ',
            17 => 'ウ',
            18 => 'イ',
            19 => 'エ',
            23 => 'エ',
            25 => 'ウ',
            26 => 'イ',
            27 => 'エ',
            56 => 'ア',
            57 => 'イ',
            58 => 'エ',
            59 => 'ウ',
            60 => 'イ',
            62 => 'エ',
            63 => 'イ',
            64 => 'ウ',
            66 => 'ウ',
            67 => 'ウ',
            68 => 'イ',
            69 => 'ア',
            71 => 'イ',
            72 => 'イ',
            73 => 'ウ',
        ];

        $this->assertCount(35, $answers);

        foreach ($answers as $number => $answer) {
            $question = $pack->questions()
                ->where(
                    'external_key',
                    sprintf(
                        'ipa-2025-autumn-ap-am-q%02d',
                        $number,
                    ),
                )
                ->firstOrFail();

            $this->assertSame(
                $answer,
                data_get($question->grading_rule, 'answer'),
                'Unexpected official answer for question '.$number,
            );
            $this->assertSame(
                $number,
                (int) data_get(
                    $question->learning_metadata,
                    'provenance.question_number',
                ),
            );
        }
    }

    public function test_official_pack_is_preferred_for_general_practice(): void
    {
        $official = $this->importAndPublish(
            'ap/ap-a-ipa-2025-autumn-official-v1',
        );
        $this->importAndPublish('ap/ap-a-canovia-core-v1');

        [, $plan, $task] = $this->studyPlan();
        $strategy = $this->generalStrategy();

        $coverage = app(QuestionBankCoverageService::class)
            ->evaluate($plan, $task, $strategy);

        $this->assertTrue($coverage['available']);
        $this->assertSame(
            $official->id,
            $coverage['pack']?->id,
        );

        $prepared = app(
            QuestionBankStudyPracticeQuestionProvider::class,
        )->prepare(
            $plan,
            $task,
            collect(),
            $strategy,
        );

        $this->assertSame(
            'ap-a-ipa-2025-autumn-official-v1',
            data_get($prepared, 'payload.pack.slug'),
        );
        $this->assertCount(10, $prepared['questions']);
        $this->assertCount(10, $prepared['selected_questions']);

        foreach ($prepared['questions'] as $question) {
            $this->assertSame('official', $question['source_type']);
            $this->assertNotEmpty($question['source_reference']);
        }

        $domains = collect($prepared['selected_questions'])
            ->pluck('selection_domain')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->assertContains('テクノロジ', $domains);
        $this->assertContains('マネジメント', $domains);
        $this->assertContains('ストラテジ', $domains);
    }

    public function test_core_pack_still_wins_when_official_pack_lacks_weakness_coverage(): void
    {
        $this->importAndPublish(
            'ap/ap-a-ipa-2025-autumn-official-v1',
        );
        $core = $this->importAndPublish(
            'ap/ap-a-canovia-core-v1',
        );

        [, $plan, $task] = $this->studyPlan();

        $coverage = app(QuestionBankCoverageService::class)
            ->evaluate($plan, $task, [
                'key' => 'weakness_reinforcement',
                'target_question_count' => 10,
                'focus_topics' => ['MTU計算'],
                'question_mix' => [
                    'primary' => 5,
                    'secondary' => 0,
                    'diagnostic' => 5,
                ],
            ]);

        $this->assertTrue($coverage['available']);
        $this->assertSame($core->id, $coverage['pack']?->id);
        $this->assertSame(
            'ap-a-canovia-core-v1',
            $coverage['pack']?->slug,
        );
        $this->assertGreaterThanOrEqual(
            3,
            $coverage['focus_match_count'],
        );
    }

    private function generalStrategy(): array
    {
        return [
            'key' => 'general_practice',
            'label' => '総合演習',
            'target_question_count' => 10,
            'focus_topics' => [],
            'weakness_priority' => [
                'primary_topics' => [],
                'secondary_topics' => [],
            ],
            'question_mix' => [
                'primary' => 0,
                'secondary' => 0,
                'diagnostic' => 10,
            ],
        ];
    }

    private function importBundled(string $catalogKey): void
    {
        $this->withSession([
            AdminAccessService::SESSION_KEY => true,
        ])
            ->post(route('admin.question_packs.import_bundled'), [
                'catalog_key' => $catalogKey,
            ])
            ->assertRedirect(route('admin.question_packs.index'))
            ->assertSessionHasNoErrors();
    }

    private function importAndPublish(string $catalogKey): QuestionPack
    {
        $this->importBundled($catalogKey);

        $slug = $catalogKey === 'ap/ap-a-canovia-core-v1'
            ? 'ap-a-canovia-core-v1'
            : 'ap-a-ipa-2025-autumn-official-v1';

        $pack = QuestionPack::query()
            ->where('slug', $slug)
            ->firstOrFail();

        $this->withSession([
            AdminAccessService::SESSION_KEY => true,
        ])
            ->patch(route('admin.question_packs.status', $pack), [
                'status' => 'published',
            ])
            ->assertSessionHasNoErrors();

        return $pack->fresh();
    }

    private function studyPlan(): array
    {
        $user = User::factory()->create();

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'AP 応用情報技術者試験 科目A',
            'description' => '科目Aの本番得点を上げる',
            'category' => '資格学習',
            'priority_mode' => 'auto',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'AP科目A 総合演習',
            'description' => '公式過去問を中心に科目A全体を確認する',
            'estimated_minutes' => 120,
            'remaining_minutes' => 120,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
