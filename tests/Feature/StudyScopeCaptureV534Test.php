<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Models\InboxItem;
use App\Models\IntelligenceStateSnapshot;
use App\Models\NativeAiRun;
use App\Models\Plan;
use App\Models\StudyScopeCapture;
use App\Models\StudyScopeItem;
use App\Models\Task;
use App\Models\User;
use App\Services\FeatureAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyScopeCaptureV534Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'openai',
            'native_ai.providers.openai.base_url' => 'https://api.openai.com/v1',
            'native_ai.providers.openai.api_key' => 'test-key',
            'native_ai.providers.openai.model' => 'gpt-5.6-luna',
        ]);
    }

    public function test_study_scope_capture_is_free_and_supports_an_ordinary_school_test_plan(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, '定期テスト学習');

        $access = app(FeatureAccessService::class)->resolveAccess(
            $user,
            FeatureKey::StudyScopeCapture,
        );

        $this->assertTrue($access->allowed);
        $this->assertSame('free', $access->source?->value);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'exam_title' => '2学期中間テスト',
                    'exam_date_text' => '10月15日',
                    'exam_date' => null,
                    'overall_confidence' => 88,
                    'items' => [
                        [
                            'subject' => '数学',
                            'unit' => '二次関数',
                            'range_text' => '教科書 p.42〜68',
                            'page_start' => 42,
                            'page_end' => 68,
                            'source_excerpt' => '数学 二次関数 p42-68',
                            'confidence' => 94,
                        ],
                        [
                            'subject' => '英語',
                            'unit' => 'Lesson 4',
                            'range_text' => '教科書 Lesson 4',
                            'page_start' => null,
                            'page_end' => null,
                            'source_excerpt' => '英語 Lesson 4',
                            'confidence' => 86,
                        ],
                    ],
                    'ambiguities' => [
                        '試験日の年が資料に明記されていません。',
                    ],
                ]),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route('plans.study_scope.store', $plan), [
                'source_file' => UploadedFile::fake()->image('test-range.png', 900, 1200),
                'note' => '学校でもらった中間テスト範囲表',
            ])
            ->assertRedirect(route('plans.study_scope.index', $plan))
            ->assertSessionHasNoErrors();

        $capture = StudyScopeCapture::firstOrFail();
        $inbox = InboxItem::firstOrFail();
        $run = NativeAiRun::firstOrFail();

        $this->assertSame('review', $capture->status);
        $this->assertSame('2学期中間テスト', $capture->exam_title);
        $this->assertNull($capture->exam_date);
        $this->assertSame('10月15日', $capture->exam_date_text);
        $this->assertSame(0.88, $capture->confidence);
        $this->assertCount(2, data_get($capture->draft_data, 'items', []));
        $this->assertSame('数学', data_get($capture->draft_data, 'items.0.subject'));
        $this->assertSame(
            '試験日の年が資料に明記されていません。',
            data_get($capture->draft_data, 'ambiguities.0'),
        );

        $this->assertSame('image', $inbox->source_type);
        $this->assertSame($plan->id, $inbox->plan_id);
        $this->assertTrue((bool) data_get($inbox->metadata, 'study_scope_capture'));
        Storage::assertExists($inbox->storage_path);

        $this->assertSame('study_scope_capture_extraction', $run->purpose);
        $this->assertSame('study_scope_capture', $run->feature_key);
        $this->assertSame('succeeded', $run->status);

        // Draft extraction is not accepted as Study truth yet.
        $this->assertDatabaseCount('study_scope_items', 0);
        $this->assertDatabaseCount('intelligence_state_snapshots', 0);
        $this->assertDatabaseCount('tasks', 0);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['store'] ?? null) === false
                && data_get($body, 'text.format.name') === 'canovia_study_scope_capture'
                && data_get($body, 'input.0.content.0.type') === 'input_image';
        });
    }

    public function test_human_confirmation_creates_scope_items_and_projects_state_without_generating_tasks(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, '学校の試験勉強');

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'exam_title' => '期末テスト',
                    'exam_date_text' => '2026/11/20',
                    'exam_date' => '2026-11-20',
                    'overall_confidence' => 90,
                    'items' => [[
                        'subject' => '数学',
                        'unit' => '二次関数',
                        'range_text' => '教科書42〜68ページ',
                        'page_start' => 42,
                        'page_end' => 68,
                        'source_excerpt' => '数学 二次関数 42-68',
                        'confidence' => 90,
                    ]],
                    'ambiguities' => [],
                ]),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route('plans.study_scope.store', $plan), [
                'source_file' => UploadedFile::fake()->image('range.jpg', 800, 1000),
            ])
            ->assertRedirect();

        $capture = StudyScopeCapture::firstOrFail();

        $this->actingAs($user)
            ->post(route('plans.study_scope.confirm', [$plan, $capture]), [
                'capture_id' => $capture->id,
                'exam_title' => '2026年 期末テスト',
                'exam_date' => '2026-11-21',
                'items' => [
                    [
                        'draft_index' => 0,
                        'subject' => '数学',
                        'unit' => '二次関数・平方完成',
                        'range_text' => '教科書42〜70ページ',
                        'page_start' => 42,
                        'page_end' => 70,
                    ],
                    [
                        'subject' => '英語',
                        'unit' => 'Lesson 5',
                        'range_text' => 'ワーク 30〜45',
                        'page_start' => null,
                        'page_end' => null,
                    ],
                ],
            ])
            ->assertRedirect(route('plans.study_scope.index', $plan))
            ->assertSessionHasNoErrors();

        $capture->refresh()->load('items', 'inboxItem');

        $this->assertSame('confirmed', $capture->status);
        $this->assertSame('2026年 期末テスト', $capture->exam_title);
        $this->assertSame('2026-11-21', $capture->exam_date?->format('Y-m-d'));
        $this->assertNotNull($capture->confirmed_at);
        $this->assertCount(2, $capture->items);
        $this->assertSame('二次関数・平方完成', $capture->items[0]->unit);
        $this->assertSame(70, $capture->items[0]->page_end);
        $this->assertSame('数学 二次関数 42-68', $capture->items[0]->source_excerpt);
        $this->assertSame(1.0, $capture->items[0]->confidence);
        $this->assertSame('英語', $capture->items[1]->subject);
        $this->assertSame('processed', $capture->inboxItem->status);

        // V53.5 may project confirmed scope into State, but it still must not
        // generate Tasks, progress mutations, or Decisions.
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('intelligence_state_snapshots', 1);
        $this->assertDatabaseCount('intelligence_decision_traces', 0);

        $snapshot = IntelligenceStateSnapshot::firstOrFail();
        $this->assertSame('study_plan', $snapshot->scope_type);
        $this->assertSame(2, data_get($snapshot->metrics, 'confirmed_scope_count'));
        $this->assertSame(0, data_get($snapshot->metrics, 'observed_scope_count'));
        $this->assertSame(0, data_get($snapshot->metrics, 'coverage_percent'));
    }

    public function test_provider_failure_keeps_source_and_manual_confirmation_still_works(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, 'テスト勉強');

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'error' => ['code' => 'rate_limit_exceeded'],
            ], 429),
        ]);

        $this->actingAs($user)
            ->post(route('plans.study_scope.store', $plan), [
                'source_file' => UploadedFile::fake()->image('range.png', 640, 640),
            ])
            ->assertRedirect(route('plans.study_scope.index', $plan));

        $capture = StudyScopeCapture::firstOrFail();
        $inbox = InboxItem::firstOrFail();
        $run = NativeAiRun::firstOrFail();

        $this->assertSame('failed', $capture->status);
        $this->assertSame('openai_rate_limit_exceeded', $capture->failure_code);
        $this->assertSame('failed', $run->status);
        Storage::assertExists($inbox->storage_path);

        $this->actingAs($user)
            ->post(route('plans.study_scope.confirm', [$plan, $capture]), [
                'capture_id' => $capture->id,
                'exam_title' => '中間テスト',
                'exam_date' => '2026-10-20',
                'items' => [[
                    'subject' => '国語',
                    'unit' => '現代文',
                    'range_text' => 'プリント1〜5',
                    'page_start' => null,
                    'page_end' => null,
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $capture->refresh();
        $this->assertSame('confirmed', $capture->status);
        $this->assertDatabaseHas('study_scope_items', [
            'study_scope_capture_id' => $capture->id,
            'subject' => '国語',
            'unit' => '現代文',
        ]);
    }

    public function test_pdf_is_sent_as_file_input_and_non_study_plan_is_rejected(): void
    {
        $user = User::factory()->create();
        $studyPlan = $this->plan($user, '大学試験学習');

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'exam_title' => null,
                    'exam_date_text' => null,
                    'exam_date' => null,
                    'overall_confidence' => 70,
                    'items' => [[
                        'subject' => '統計学',
                        'unit' => null,
                        'range_text' => 'Chapter 1-3',
                        'page_start' => null,
                        'page_end' => null,
                        'source_excerpt' => 'Statistics Chapter 1-3',
                        'confidence' => 70,
                    ]],
                    'ambiguities' => [],
                ]),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route('plans.study_scope.store', $studyPlan), [
                'source_file' => UploadedFile::fake()->create(
                    'range.pdf',
                    64,
                    'application/pdf',
                ),
            ])
            ->assertRedirect();

        Http::assertSent(function ($request) {
            return data_get(
                $request->data(),
                'input.0.content.0.type',
            ) === 'input_file';
        });

        $developmentPlan = $this->plan($user, '個人開発');

        $this->actingAs($user)
            ->get(route('plans.study_scope.index', $developmentPlan))
            ->assertNotFound();
    }

    public function test_unconfirmed_capture_can_be_discarded_with_its_private_source(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan($user, '試験勉強');

        config(['native_ai.driver' => 'disabled']);

        $this->actingAs($user)
            ->post(route('plans.study_scope.store', $plan), [
                'source_file' => UploadedFile::fake()->image('discard.png'),
            ])
            ->assertRedirect();

        $capture = StudyScopeCapture::firstOrFail();
        $inbox = InboxItem::firstOrFail();
        $path = $inbox->storage_path;

        Storage::assertExists($path);

        $this->actingAs($user)
            ->delete(route('plans.study_scope.destroy', [$plan, $capture]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('study_scope_captures', 0);
        $this->assertDatabaseCount('inbox_items', 0);
        Storage::assertMissing($path);
    }

    private function plan(User $user, string $category): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Study Scope Test',
            'category' => $category,
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function responseBody(array $payload): array
    {
        return [
            'id' => 'resp_study_scope',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(
                        $payload,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    ),
                ]],
            ]],
            'usage' => [
                'input_tokens' => 220,
                'output_tokens' => 180,
                'total_tokens' => 400,
            ],
        ];
    }
}
