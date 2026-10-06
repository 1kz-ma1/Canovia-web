<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\PlanResource;
use App\Models\StudyRecallSource;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SafePlanResourceRecallHandoffV5811Test extends TestCase
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
            'native_ai.providers.openai.base_url' =>
                'https://api.openai.com/v1',
            'native_ai.providers.openai.api_key' => 'test-key',
            'native_ai.providers.openai.model' => 'gpt-5.6-luna',
        ]);

        Storage::fake('local');
    }

    public function test_task_linked_resource_creates_recall_source_from_explicit_pdf_without_fetching_resource_url(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $resource = $this->resource(
            $user,
            $plan,
            'https://drive.google.com/file/d/private-reference/view',
        );
        $resource->tasks()->attach($task->id);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody(),
                200,
            ),
            '*' => Http::response([], 599),
        ]);

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.resources.extract',
                [$plan, $task, $resource],
            ), [
                'source_file' => $this->pdfUpload('chapter.pdf'),
            ])
            ->assertRedirect(
                route('plans.tasks.study_recall.show', [$plan, $task]),
            )
            ->assertSessionHasNoErrors();

        $source = StudyRecallSource::query()->firstOrFail();

        $this->assertSame($resource->id, $source->plan_resource_id);
        $this->assertSame('pdf', $source->source_type);
        $this->assertSame('chapter.pdf', $source->original_name);
        $this->assertSame('ready', $source->status);
        Storage::assertExists($source->storage_path);

        $this->assertDatabaseHas('study_recall_candidates', [
            'study_recall_source_id' => $source->id,
            'task_id' => $task->id,
            'prompt' => 'abandon',
            'answer' => '放棄する',
        ]);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($resource) {
            $encoded = json_encode(
                $request->data(),
                JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES,
            );

            return $request->url()
                    === 'https://api.openai.com/v1/responses'
                && ! str_contains(
                    (string) $encoded,
                    $resource->url,
                );
        });
    }

    public function test_unscoped_plan_resource_can_create_recall_source_from_explicit_text(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $resource = $this->resource(
            $user,
            $plan,
            'https://github.com/example/study-notes',
        );

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody(),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.resources.extract',
                [$plan, $task, $resource],
            ), [
                'source_text' => 'abandon は「放棄する」という意味。',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $source = StudyRecallSource::query()->firstOrFail();

        $this->assertSame($resource->id, $source->plan_resource_id);
        $this->assertSame('text', $source->source_type);
        $this->assertSame(
            'abandon は「放棄する」という意味。',
            $source->source_text,
        );
        $this->assertNull($source->storage_path);
    }

    public function test_resource_linked_only_to_another_task_is_not_eligible_for_current_task(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $otherTask = $this->task(
            $plan,
            '別範囲のTask',
            2,
        );
        $resource = $this->resource(
            $user,
            $plan,
            'https://drive.google.com/file/d/other/view',
        );
        $resource->tasks()->attach($otherTask->id);

        Http::fake();

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.resources.extract',
                [$plan, $task, $resource],
            ), [
                'source_text' => 'current task must not use this',
            ])
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('study_recall_sources', 0);

        $this->actingAs($user)
            ->get(route(
                'plans.tasks.study_recall.show',
                [$plan, $task],
            ))
            ->assertOk()
            ->assertDontSee(
                'data-recall-resource-row="'.$resource->id.'"',
                false,
            );
    }

    public function test_resource_from_another_plan_is_rejected(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $otherPlan = $this->plan(
            $user,
            '別Plan',
        );
        $resource = $this->resource(
            $user,
            $otherPlan,
            'https://github.com/example/other',
        );

        Http::fake();

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.resources.extract',
                [$plan, $task, $resource],
            ), [
                'source_text' => 'foreign resource',
            ])
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('study_recall_sources', 0);
    }

    public function test_provider_failure_keeps_resource_provenance_for_v587_retry(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $resource = $this->resource(
            $user,
            $plan,
            'https://drive.google.com/file/d/retry/view',
        );

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'error' => ['code' => 'server_error'],
            ], 500),
        ]);

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.resources.extract',
                [$plan, $task, $resource],
            ), [
                'source_file' => $this->pdfUpload('retry.pdf'),
            ])
            ->assertRedirect(
                route('plans.tasks.study_recall.show', [$plan, $task]),
            );

        $source = StudyRecallSource::query()->firstOrFail();

        $this->assertSame('failed', $source->status);
        $this->assertSame($resource->id, $source->plan_resource_id);
        $this->assertTrue($source->hasStoredMaterial());
        $this->assertNotNull($source->native_ai_run_id);
    }

    public function test_deleting_resource_nulls_provenance_without_deleting_recall_source(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $resource = $this->resource(
            $user,
            $plan,
            'https://github.com/example/source',
        );

        $source = StudyRecallSource::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'plan_resource_id' => $resource->id,
            'user_id' => $user->id,
            'source_type' => 'text',
            'original_name' => 'Source Resource',
            'source_text' => 'saved material',
            'status' => 'ready',
            'candidate_count' => 0,
        ]);

        $resource->delete();

        $source->refresh();

        $this->assertNull($source->plan_resource_id);
        $this->assertSame('saved material', $source->source_text);
        $this->assertDatabaseHas('study_recall_sources', [
            'id' => $source->id,
            'task_id' => $task->id,
        ]);
    }

    public function test_account_deletion_removes_resource_derived_recall_private_file(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $resource = $this->resource(
            $user,
            $plan,
            'https://drive.google.com/file/d/delete/view',
        );

        $path = 'study-recall-sources/'
            .$plan->id
            .'/'
            .$task->id
            .'/delete-me.pdf';

        Storage::disk('local')->put(
            $path,
            '%PDF-1.4 private',
        );

        $source = StudyRecallSource::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'plan_resource_id' => $resource->id,
            'user_id' => $user->id,
            'source_type' => 'pdf',
            'original_name' => 'delete-me.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => $path,
            'status' => 'ready',
            'candidate_count' => 0,
        ]);

        app(AccountDeletionService::class)->delete($user);

        $this->assertDatabaseMissing('study_recall_sources', [
            'id' => $source->id,
        ]);
        $this->assertDatabaseMissing('plan_resources', [
            'id' => $resource->id,
        ]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_source_ui_shows_plan_resource_provenance(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        $resource = $this->resource(
            $user,
            $plan,
            'https://drive.google.com/file/d/ui/view',
        );

        $source = StudyRecallSource::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'plan_resource_id' => $resource->id,
            'user_id' => $user->id,
            'source_type' => 'text',
            'original_name' => 'Resource Material',
            'source_text' => 'saved',
            'status' => 'ready',
            'candidate_count' => 2,
        ]);

        $this->actingAs($user)
            ->get(route(
                'plans.tasks.study_recall.show',
                [$plan, $task],
            ))
            ->assertOk()
            ->assertSee('登録済みResourceからRecall教材を作る')
            ->assertSee(
                'data-recall-resource-row="'.$resource->id.'"',
                false,
            )
            ->assertSee(
                'data-recall-source-resource="'.$resource->id.'"',
                false,
            )
            ->assertSee('Resource: '.$resource->title)
            ->assertSee($resource->url, false)
            ->assertSee(
                route(
                    'plans.tasks.study_recall.resources.extract',
                    [$plan, $task, $resource],
                ),
                false,
            );
    }

    public function test_free_user_cannot_trigger_resource_candidate_extraction(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $resource = $this->resource(
            $user,
            $plan,
            'https://github.com/example/free',
        );

        Http::fake();

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.resources.extract',
                [$plan, $task, $resource],
            ), [
                'source_text' => 'explicit material',
            ])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('study_recall_sources', 0);
    }

    private function grantPremium(User $user): UserProductGrant
    {
        return UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'expires_at' => null,
            'metadata' => ['test' => true],
        ]);
    }

    private function responseBody(): array
    {
        return [
            'id' => 'resp_resource_recall',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'candidates' => [[
                            'prompt' => 'abandon',
                            'answer' => '放棄する',
                            'note' => null,
                            'tags' => ['TOEIC'],
                            'source_excerpt' =>
                                'abandon: 放棄する',
                            'confidence' => 95,
                        ]],
                    ], JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'total_tokens' => 150,
            ],
        ];
    }

    private function pdfUpload(string $name): UploadedFile
    {
        $path = tempnam(
            sys_get_temp_dir(),
            'resource-recall-pdf-',
        );
        file_put_contents(
            $path,
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF",
        );

        return new UploadedFile(
            $path,
            $name,
            'application/pdf',
            null,
            true,
        );
    }

    private function resource(
        User $user,
        Plan $plan,
        string $url,
    ): PlanResource {
        return PlanResource::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => str_contains($url, 'github.com')
                ? 'github'
                : 'google_drive',
            'resource_type' => 'file',
            'title' => 'Study Resource '.$plan->id,
            'url' => $url,
        ]);
    }

    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = $this->plan(
            $user,
            'TOEIC語彙',
        );

        $task = $this->task(
            $plan,
            '英単語を暗記する',
            1,
        );

        return [$user, $plan, $task];
    }

    private function plan(
        User $user,
        string $title,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title.'の学習',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function task(
        Plan $plan,
        string $title,
        int $sortOrder,
    ): Task {
        return Task::query()->create([
            'plan_id' => $plan->id,
            'title' => $title,
            'description' => 'Recallで定着させる',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => $sortOrder,
        ]);
    }
}
