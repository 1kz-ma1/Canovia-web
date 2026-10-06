<?php

namespace Tests\Feature;

use App\Enums\ProductKey;
use App\Models\NativeAiRun;
use App\Models\Plan;
use App\Models\StudyRecallCandidate;
use App\Models\StudyRecallSource;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudyRecallBatchIngestV588Test extends TestCase
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

    public function test_two_images_use_one_native_ai_run_and_map_candidates_to_sources(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    $this->candidate(0, 'abandon', '放棄する'),
                    $this->candidate(1, 'maintain', '維持する'),
                ]),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.candidates.extract_batch',
                [$plan, $task],
            ), [
                'source_files' => [
                    $this->pngUpload('page-1.png'),
                    $this->pngUpload('page-2.png'),
                ],
            ])
            ->assertRedirect(
                route('plans.tasks.study_recall.show', [$plan, $task]),
            )
            ->assertSessionHasNoErrors();

        $sources = StudyRecallSource::query()
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $sources);
        $this->assertTrue($sources->every(
            fn (StudyRecallSource $source) =>
                $source->status === 'ready',
        ));
        $this->assertSame(
            $sources[0]->native_ai_run_id,
            $sources[1]->native_ai_run_id,
        );
        $this->assertSame(1, $sources[0]->candidate_count);
        $this->assertSame(1, $sources[1]->candidate_count);

        $this->assertDatabaseHas('study_recall_candidates', [
            'study_recall_source_id' => $sources[0]->id,
            'prompt' => 'abandon',
            'answer' => '放棄する',
        ]);
        $this->assertDatabaseHas('study_recall_candidates', [
            'study_recall_source_id' => $sources[1]->id,
            'prompt' => 'maintain',
            'answer' => '維持する',
        ]);

        $this->assertDatabaseCount('native_ai_runs', 1);
        $run = NativeAiRun::query()->firstOrFail();
        $this->assertSame(
            'study_recall_candidate_batch_extraction',
            $run->purpose,
        );
        $this->assertSame('succeeded', $run->status);

        Http::assertSentCount(1);
    }

    public function test_mixed_image_pdf_preserves_source_marker_order_in_one_request(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    $this->candidate(0, 'image-term', '画像側'),
                    $this->candidate(1, 'pdf-term', 'PDF側'),
                ]),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.candidates.extract_batch',
                [$plan, $task],
            ), [
                'source_files' => [
                    $this->pngUpload('first.png'),
                    $this->pdfUpload('second.pdf'),
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Http::assertSent(function ($request) {
            $content = data_get(
                $request->data(),
                'input.0.content',
                [],
            );

            $types = collect($content)
                ->pluck('type')
                ->values()
                ->all();

            return $types === [
                'input_text',
                'input_image',
                'input_text',
                'input_file',
                'input_text',
            ]
                && data_get(
                    $content,
                    '0.text',
                ) === 'SOURCE_INDEX 0 / first.png'
                && data_get(
                    $content,
                    '2.text',
                ) === 'SOURCE_INDEX 1 / second.pdf'
                && data_get(
                    $content,
                    '3.filename',
                ) === 'second.pdf';
        });
    }

    public function test_duplicate_candidate_content_across_sources_is_created_once(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(
                $this->responseBody([
                    $this->candidate(0, 'abandon', '放棄する'),
                    $this->candidate(1, 'abandon', '放棄する'),
                ]),
                200,
            ),
        ]);

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.candidates.extract_batch',
                [$plan, $task],
            ), [
                'source_files' => [
                    $this->pngUpload('page-a.png'),
                    $this->pngUpload('page-b.png'),
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $sources = StudyRecallSource::query()
            ->orderBy('id')
            ->get();

        $this->assertDatabaseCount('study_recall_candidates', 1);
        $this->assertSame(1, $sources[0]->candidate_count);
        $this->assertSame(0, $sources[1]->candidate_count);
        $this->assertSame(
            $sources[0]->id,
            StudyRecallCandidate::query()->firstOrFail()
                ->study_recall_source_id,
        );
    }

    public function test_provider_failure_keeps_all_sources_failed_for_v587_retry(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'error' => ['code' => 'server_error'],
            ], 500),
        ]);

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.candidates.extract_batch',
                [$plan, $task],
            ), [
                'source_files' => [
                    $this->pngUpload('page-1.png'),
                    $this->pngUpload('page-2.png'),
                ],
            ])
            ->assertRedirect(
                route('plans.tasks.study_recall.show', [$plan, $task]),
            )
            ->assertSessionHas(
                'status',
                'Native AI providerが一時的に利用できません。 教材はすべて保存済みなので、失敗したSourceから個別に再抽出できます。',
            );

        $sources = StudyRecallSource::query()
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $sources);
        $this->assertTrue($sources->every(
            fn (StudyRecallSource $source) =>
                $source->status === 'failed',
        ));
        $this->assertNotNull($sources[0]->native_ai_run_id);
        $this->assertSame(
            $sources[0]->native_ai_run_id,
            $sources[1]->native_ai_run_id,
        );
        $this->assertDatabaseCount('study_recall_candidates', 0);
        $this->assertDatabaseHas('native_ai_runs', [
            'id' => $sources[0]->native_ai_run_id,
            'status' => 'failed',
        ]);
    }

    public function test_batch_requires_two_to_five_files(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        Http::fake();

        $this->actingAs($user)
            ->from(route(
                'plans.tasks.study_recall.show',
                [$plan, $task],
            ))
            ->post(route(
                'plans.tasks.study_recall.candidates.extract_batch',
                [$plan, $task],
            ), [
                'source_files' => [
                    $this->pngUpload('only.png'),
                ],
            ])
            ->assertSessionHasErrors('source_files');

        $this->actingAs($user)
            ->from(route(
                'plans.tasks.study_recall.show',
                [$plan, $task],
            ))
            ->post(route(
                'plans.tasks.study_recall.candidates.extract_batch',
                [$plan, $task],
            ), [
                'source_files' => [
                    $this->pngUpload('1.png'),
                    $this->pngUpload('2.png'),
                    $this->pngUpload('3.png'),
                    $this->pngUpload('4.png'),
                    $this->pngUpload('5.png'),
                    $this->pngUpload('6.png'),
                ],
            ])
            ->assertSessionHasErrors('source_files');

        Http::assertNothingSent();
        $this->assertDatabaseCount('study_recall_sources', 0);
    }

    public function test_total_batch_size_above_twenty_megabytes_is_rejected_before_storage_and_provider(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $this->grantPremium($user);

        Http::fake();

        $files = [];
        for ($index = 0; $index < 5; $index++) {
            $files[] = UploadedFile::fake()->create(
                'large-'.$index.'.pdf',
                5120,
                'application/pdf',
            );
        }

        $this->actingAs($user)
            ->from(route(
                'plans.tasks.study_recall.show',
                [$plan, $task],
            ))
            ->post(route(
                'plans.tasks.study_recall.candidates.extract_batch',
                [$plan, $task],
            ), [
                'source_files' => $files,
            ])
            ->assertSessionHasErrors('source_files');

        Http::assertNothingSent();
        $this->assertDatabaseCount('study_recall_sources', 0);
        Storage::disk('local')->assertDirectoryEmpty(
            'study-recall-sources',
        );
    }

    public function test_free_user_cannot_use_batch_extraction(): void
    {
        [$user, $plan, $task] = $this->scenario();

        Http::fake();

        $this->actingAs($user)
            ->post(route(
                'plans.tasks.study_recall.candidates.extract_batch',
                [$plan, $task],
            ), [
                'source_files' => [
                    $this->pngUpload('page-1.png'),
                    $this->pngUpload('page-2.png'),
                ],
            ])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('study_recall_sources', 0);
    }

    public function test_batch_ui_is_only_exposed_when_automatic_ai_is_available(): void
    {
        [$user, $plan, $task] = $this->scenario();

        $this->actingAs($user)
            ->get(route(
                'plans.tasks.study_recall.show',
                [$plan, $task],
            ))
            ->assertOk()
            ->assertDontSee('data-recall-batch-ingest', false);

        $this->grantPremium($user);

        $this->actingAs($user)
            ->get(route(
                'plans.tasks.study_recall.show',
                [$plan, $task],
            ))
            ->assertOk()
            ->assertSee('data-recall-batch-ingest', false)
            ->assertSee('複数ページをまとめて取り込む')
            ->assertSee(
                route(
                    'plans.tasks.study_recall.candidates.extract_batch',
                    [$plan, $task],
                ),
                false,
            );
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

    private function candidate(
        int $sourceIndex,
        string $prompt,
        string $answer,
    ): array {
        return [
            'source_index' => $sourceIndex,
            'prompt' => $prompt,
            'answer' => $answer,
            'note' => null,
            'tags' => ['TOEIC'],
            'source_excerpt' => $prompt.': '.$answer,
            'confidence' => 95,
        ];
    }

    private function responseBody(array $candidates): array
    {
        return [
            'id' => 'resp_recall_batch',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(
                        ['candidates' => $candidates],
                        JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES,
                    ),
                ]],
            ]],
            'usage' => [
                'input_tokens' => 300,
                'output_tokens' => 120,
                'total_tokens' => 420,
            ],
        ];
    }

    private function pngUpload(string $name): UploadedFile
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=',
            true,
        );
        $path = tempnam(sys_get_temp_dir(), 'recall-batch-image-');
        file_put_contents($path, $bytes);

        return new UploadedFile(
            $path,
            $name,
            'image/png',
            null,
            true,
        );
    }

    private function pdfUpload(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'recall-batch-pdf-');
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

    private function scenario(): array
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'TOEIC語彙',
            'description' => '複数ページの語彙をRecallで定着させる',
            'category' => '資格学習',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => '英単語を暗記する',
            'description' => '頻出語彙を複数ページから覚える',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
