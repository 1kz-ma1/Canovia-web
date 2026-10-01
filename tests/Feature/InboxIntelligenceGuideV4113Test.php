<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\CareerCapture;
use App\Models\FutureMemo;
use App\Models\InboxItem;
use App\Models\NativeAiRun;
use App\Models\Plan;
use App\Models\PlanResource;
use App\Models\StudyRecallCandidate;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class InboxIntelligenceGuideV4113Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'openai',
            'native_ai.providers.openai.base_url' => 'https://api.openai.com/v1',
            'native_ai.providers.openai.api_key' => 'test-key',
            'native_ai.providers.openai.model' => 'gpt-5.6-luna',
        ]);

        Storage::fake('local');
    }

    public function test_free_user_can_route_manually_but_cannot_request_ai_suggestion(): void
    {
        [$user] = $this->scenario();
        $item = $this->inbox($user, [
            'content' => 'AP取得後に新しいゲーム案を作りたい',
        ]);

        $this->actingAs($user)
            ->post(route('inbox.suggest', $item))
            ->assertForbidden();

        Http::assertNothingSent();

        $this->actingAs($user)
            ->post(route('inbox.route', $item), [
                'destination' => 'future_memo',
                'future_memo_kind' => 'want_to_do',
                'future_memo_category' => 'creation',
            ])
            ->assertRedirect(route('inbox.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('future_memos', [
            'user_id' => $user->id,
            'kind' => 'want_to_do',
            'category' => 'creation',
        ]);
        $this->assertSame('processed', $item->fresh()->status);
    }

    public function test_premium_ai_suggestion_stops_at_review_and_never_returns_ids(): void
    {
        [$user] = $this->scenario();
        $this->grantPremium($user);
        $item = $this->inbox($user, [
            'content' => 'TOEIC頻出単語をまとめた教材。abandon は放棄する。',
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'destination' => 'recall_material',
                    'reason' => '単語暗記用の教材だから',
                    'confidence' => 94,
                    'suggested_plan_title' => 'TOEIC',
                    'suggested_task_title' => '英単語',
                    'future_memo_kind' => 'interest',
                    'future_memo_category' => 'study',
                ]),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route('inbox.suggest', $item))
            ->assertRedirect(route('inbox.index'))
            ->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('review', $item->status);
        $this->assertSame('recall_material', data_get($item->metadata, 'routing_suggestion.destination'));
        $this->assertSame(94, data_get($item->metadata, 'routing_suggestion.confidence'));

        $this->assertDatabaseCount('future_memos', 0);
        $this->assertDatabaseCount('career_captures', 0);
        $this->assertDatabaseCount('study_recall_candidates', 0);
        $this->assertDatabaseCount('task_evidences', 0);

        $run = NativeAiRun::firstOrFail();
        $this->assertNull($run->plan_id);
        $this->assertNull($run->task_id);
        $this->assertSame('inbox_routing_suggestion', $run->purpose);

        Http::assertSent(function ($request) {
            $schema = data_get($request->data(), 'text.format.schema.properties', []);

            return ! array_key_exists('plan_id', $schema)
                && ! array_key_exists('task_id', $schema)
                && array_key_exists('suggested_plan_title', $schema)
                && array_key_exists('suggested_task_title', $schema);
        });
    }

    public function test_human_can_route_item_to_task_evidence_without_progress_claim(): void
    {
        [$user, $genericPlan, $genericTask] = $this->scenario();
        $item = $this->inbox($user, [
            'content' => 'README更新とログイン画面の修正を完了した',
        ]);

        $this->actingAs($user)
            ->post(route('inbox.route', $item), [
                'destination' => 'task_evidence',
                'plan_id' => $genericPlan->id,
                'task_id' => $genericTask->id,
            ])
            ->assertRedirect(route('inbox.index'))
            ->assertSessionHasNoErrors();

        $evidence = TaskEvidence::firstOrFail();
        $this->assertSame($genericTask->id, $evidence->task_id);
        $this->assertSame('inbox_observation_confirmed', $evidence->type);
        $this->assertSame(0.5, $evidence->confidence);
        $this->assertSame(20, $genericTask->fresh()->progress_percent);
        $this->assertSame('processed', $item->fresh()->status);
    }

    public function test_human_can_route_url_to_career_capture(): void
    {
        [$user, , , $careerPlan] = $this->scenario();
        $item = $this->inbox($user, [
            'source_type' => 'url',
            'source_url' => 'https://example.com/jobs/backend',
            'title' => 'Backend Engineer求人',
        ]);

        $this->actingAs($user)
            ->post(route('inbox.route', $item), [
                'destination' => 'career_capture',
                'plan_id' => $careerPlan->id,
            ])
            ->assertRedirect(route('inbox.index'))
            ->assertSessionHasNoErrors();

        $capture = CareerCapture::firstOrFail();
        $this->assertSame($careerPlan->id, $capture->plan_id);
        $this->assertSame('url', $capture->source_type);
        $this->assertSame('pending', $capture->status);
        $this->assertSame('processed', $item->fresh()->status);
    }

    public function test_supported_url_can_be_confirmed_as_plan_resource(): void
    {
        [$user, $genericPlan] = $this->scenario();
        $item = $this->inbox($user, [
            'source_type' => 'url',
            'source_url' => 'https://github.com/1kz-ma1/Canovia',
            'title' => 'Canovia repository',
        ]);

        $this->actingAs($user)
            ->post(route('inbox.route', $item), [
                'destination' => 'plan_resource',
                'plan_id' => $genericPlan->id,
            ])
            ->assertRedirect(route('inbox.index'))
            ->assertSessionHasNoErrors();

        $resource = PlanResource::firstOrFail();
        $this->assertSame('github', $resource->provider);
        $this->assertSame($genericPlan->id, $resource->plan_id);
        $this->assertSame('processed', $item->fresh()->status);
    }

    public function test_recall_routing_requires_premium_and_creates_candidate_only_after_human_confirmation(): void
    {
        [$user, , , , $studyPlan, $studyTask] = $this->scenario();
        $item = $this->inbox($user, [
            'content' => "abandon: 放棄する\nmaintain: 維持する",
        ]);

        $this->actingAs($user)
            ->post(route('inbox.route', $item), [
                'destination' => 'recall_material',
                'plan_id' => $studyPlan->id,
                'task_id' => $studyTask->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('study_recall_candidates', 0);
        $this->assertSame('new', $item->fresh()->status);

        $this->grantPremium($user);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    'candidates' => [[
                        'prompt' => 'abandon',
                        'answer' => '放棄する',
                        'note' => '動詞',
                        'tags' => ['TOEIC'],
                        'source_excerpt' => 'abandon: 放棄する',
                        'confidence' => 96,
                    ]],
                ]),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route('inbox.route', $item), [
                'destination' => 'recall_material',
                'plan_id' => $studyPlan->id,
                'task_id' => $studyTask->id,
            ])
            ->assertRedirect(route('inbox.index'))
            ->assertSessionHasNoErrors();

        $candidate = StudyRecallCandidate::firstOrFail();
        $this->assertSame($studyTask->id, $candidate->task_id);
        $this->assertSame('pending', $candidate->status);
        $this->assertSame('processed', $item->fresh()->status);
    }

    public function test_guide_v2_keeps_inbox_capabilities_while_primary_onboarding_uses_execution_constellation_and_companion(): void
    {
        $catalog = config('canovia_guides');
        $script = file_get_contents(resource_path('js/app.js'));
        $guide = file_get_contents(resource_path('views/layouts/partials/guide.blade.php'));

        $this->assertSame(2, $catalog['version']);
        $this->assertArrayHasKey('home_next_action', $catalog['guides']);
        $this->assertArrayHasKey('inbox_capture', $catalog['guides']);
        $this->assertArrayHasKey('inbox_organize', $catalog['guides']);
        $this->assertArrayHasKey('recall', $catalog['guides']);
        $this->assertArrayNotHasKey('today_action', $catalog['guides']);
        $this->assertStringContainsString("title: '全体像は星座で見る'", $script);
        $this->assertStringContainsString("next: null", $script);
        $this->assertStringContainsString("'inbox-nav': {", $script);
        $this->assertStringContainsString("selector: '[data-companion-palette-open]'", $script);
        $this->assertStringContainsString("'today-nav': {", $script);
        $this->assertStringContainsString("selector: '[data-onboarding-target=\"execution-nav\"]'", $script);
        $this->assertStringContainsString('Inbox、Recall、AI演習、共同計画', $guide);
    }

    private function grantPremium(User $user): UserProductGrant
    {
        return UserProductGrant::create([
            'user_id' => $user->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'expires_at' => null,
            'metadata' => ['test' => true],
        ]);
    }

    private function inbox(User $user, array $overrides = []): InboxItem
    {
        return InboxItem::create(array_merge([
            'user_id' => $user->id,
            'source_type' => 'text',
            'status' => 'new',
            'title' => 'Inbox test',
            'content' => 'test',
            'metadata' => [],
        ], $overrides));
    }

    private function responseBody(array $payload): array
    {
        return [
            'id' => 'resp_inbox',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'usage' => [
                'input_tokens' => 120,
                'output_tokens' => 60,
                'total_tokens' => 180,
            ],
        ];
    }

    private function scenario(): array
    {
        $user = User::factory()->create();

        $genericPlan = $this->plan($user, 'Canovia開発', '個人開発');
        $genericTask = $this->task($genericPlan, 'UIを改善する');

        $careerPlan = $this->plan($user, '就職活動', '就活・キャリア');
        $careerTask = $this->task($careerPlan, '応募先を整理する');

        $studyPlan = $this->plan($user, 'TOEIC 800点', '資格学習');
        $studyTask = $this->task($studyPlan, 'TOEIC英単語を暗記する');

        return [$user, $genericPlan, $genericTask, $careerPlan, $studyPlan, $studyTask, $careerTask];
    }

    private function plan(User $user, string $title, string $category): Plan
    {
        return Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function task(Plan $plan, string $title): Task
    {
        return Task::create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => $title,
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);
    }
}
