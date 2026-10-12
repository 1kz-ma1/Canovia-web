<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\CompanionMessage;
use App\Models\CompanionMutationCandidate;
use App\Models\CompanionThread;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\CompanionContextService;
use App\Services\CompanionContinuityService;
use App\Services\CompanionConversationService;
use App\Services\CompanionDerivedEvidenceSourceGuard;
use App\Services\CompanionMutationApplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CompanionDerivedEvidenceLineageG3Test extends TestCase
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
            'features.flags.'.FeatureKey::CanoviaCompanion->value => [
                'enabled' => true,
                'environment' => null,
                'platform' => 'all',
                'minimum_app_version' => null,
            ],
        ]);
    }

    public function test_revoked_or_updated_evidence_keeps_companion_candidate_history_but_blocks_mutation(): void
    {
        [$user, $plan, $task, $thread, $root, $evidence] = $this->scenario();
        $sources = app(CompanionDerivedEvidenceSourceGuard::class);
        $signed = $sources->capture(
            app(CompanionContextService::class)->snapshot($user, $plan, $task),
        );
        $this->assertCount(1, $signed['github_evidence']);
        $this->assertTrue($sources->isCurrent($plan, $signed));

        $candidate = $this->candidate($thread, $user, $plan, $task, $signed);
        $this->assertFalse(app(CompanionMutationApplyService::class)
            ->reviewPreview($candidate)['source_stale']);

        $metadata = $root->metadata;
        $metadata['github_app_connection']['status'] = 'revoked';
        $root->update(['metadata' => $metadata]);

        $this->assertFalse($sources->isCurrent($plan, $signed));
        $this->assertTrue(app(CompanionMutationApplyService::class)
            ->reviewPreview($candidate->fresh())['source_stale']);
        $signals = app(CompanionContinuityService::class)->signals(
            $thread, app(CompanionContextService::class)->snapshot($user, $plan, $task),
        );
        $this->assertNotContains('pending_candidate', collect($signals)->pluck('kind')->all());

        $this->actingAs($user)
            ->get(route('companion.show', $thread))
            ->assertOk()
            ->assertSee('data-companion-source-stale', false)
            ->assertDontSee('この内容を反映する');

        $this->assertApplyDenied($user, $candidate);
        $this->assertSame('pending', $candidate->fresh()->status);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('task_evidences', 1);

        $metadata['github_app_connection']['status'] = 'connected';
        $metadata['github_app_connection']['source_epoch'] = (string) Str::uuid();
        $root->update(['metadata' => $metadata]);
        $this->assertApplyDenied($user, $candidate);

        // Explicitly reverify in a new source epoch, without mutating or
        // erasing old proposed text or blindly accepting old suggestions.
        $em = $evidence->metadata;
        $em['github_source_epoch'] = $metadata['github_app_connection']['source_epoch'];
        $evidence->update(['metadata' => $em]);
        $this->assertFalse($sources->isCurrent($plan, $signed));
        $this->assertApplyDenied($user, $candidate);
    }

    public function test_same_epoch_content_edit_invalidates_existing_signed_candidate(): void
    {
        [$user, $plan, $task, $thread, , $evidence] = $this->scenario();
        $sources = app(CompanionDerivedEvidenceSourceGuard::class);
        $provenance = $sources->capture(
            app(CompanionContextService::class)->snapshot($user, $plan, $task),
        );
        $candidate = $this->candidate($thread, $user, $plan, $task, $provenance);

        $payload = (array) $evidence->metadata;
        $payload['issue_state'] = 'reopened';
        $evidence->update(['metadata' => $payload]);

        $this->assertFalse($sources->isCurrent($plan, $provenance));
        $this->assertApplyDenied($user, $candidate);
        $this->assertSame('doing', $task->fresh()->status);
        $this->assertSame('pending', $candidate->fresh()->status);
    }

    public function test_stale_assistant_history_is_excluded_from_new_ai_prompt_but_preserved_as_history(): void
    {
        [$user, $plan, $task, $thread, $root] = $this->scenario();
        $old = app(CompanionDerivedEvidenceSourceGuard::class)->capture(
            app(CompanionContextService::class)->snapshot($user, $plan, $task),
        );
        $assistant = $thread->messages()->create([
            'role' => 'assistant',
            'content' => '古いGitHub Issueが完了しているのでTaskも完了です',
            'metadata' => ['source_provenance' => $old],
        ]);
        $m = (array) $root->metadata;
        $m['github_app_connection']['status'] = 'revoked';
        $root->update(['metadata' => $m]);

        Http::fake([
            'https://api.openai.com/v1/responses' =>
                Http::response($this->response(['reply' => '最新の状態を再確認しましょう', 'memories' => [], 'candidates' => []]), 200),
        ]);

        $reply = app(CompanionConversationService::class)->send(
            $thread, $user, '現在の状態を整理して', (string) Str::uuid(), $plan, $task,
        );
        $this->assertSame('最新の状態を再確認しましょう', $reply['message']->content);
        Http::assertSent(function ($request) {
            $input = (string) ($request->data()['input'] ?? '');

            return str_contains($input, '過去の返答内容は参照しません')
                && ! str_contains($input, '古いGitHub Issueが完了しているのでTaskも完了です');
        });

        $this->assertSame(
            '古いGitHub Issueが完了しているのでTaskも完了です',
            $assistant->fresh()->content,
        );
        $this->assertDatabaseCount('companion_mutation_candidates', 0);
    }

    public function test_disconnect_during_provider_call_prevents_persisting_ai_reply_and_mutation(): void
    {
        [$user, $plan, $task, $thread, $root] = $this->scenario();
        Http::fake(function () use ($root) {
            $metadata = (array) $root->fresh()->metadata;
            $metadata['github_app_connection']['status'] = 'revoked';
            $root->update(['metadata' => $metadata]);

            return Http::response($this->response([
                'reply' => '古いEvidenceを根拠に変更しましょう',
                'memories' => [],
                'candidates' => [[
                    'type' => 'update_task',
                    'title' => 'Taskを変更',
                    'summary' => '古いGitHub根拠',
                    'payload_json' => '{"next_action_note":"古い案"}',
                ]],
            ]), 200);
        });

        try {
            app(CompanionConversationService::class)->send(
                $thread, $user, '変更したい', (string) Str::uuid(), $plan, $task,
            );
            $this->fail('Companion must reject an AI reply after source revocation');
        } catch (NativeAiExecutionException $exception) {
            $this->assertSame('native_ai_source_changed', $exception->errorCode);
        }

        $this->assertSame(0, CompanionMessage::query()->where('role', 'assistant')->count());
        $this->assertDatabaseCount('companion_mutation_candidates', 0);
        $this->assertDatabaseCount('future_memos', 0);
    }

    private function assertApplyDenied(User $user, CompanionMutationCandidate $candidate): void
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        try {
            app(CompanionMutationApplyService::class)->apply(
                $request, $candidate, (string) Str::uuid(),
            );
            $this->fail('Stale GitHub-derived candidate must not update a Task');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('candidate', $exception->errors());
        }
    }

    private function scenario(): array
    {
        $user = User::factory()->create();
        UserProductGrant::query()->create([
            'user_id' => $user->id, 'product_key' => ProductKey::AllAccess,
            'source' => 'manual', 'starts_at' => now()->subMinute(),
        ]);
        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Development', 'description' => 'Evidence',
            'category' => '個人開発', 'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'Review Issue',
            'description' => 'Review issue status', 'status' => 'doing',
            'progress_percent' => 10, 'next_action_note' => 'Review',
            'priority' => 1, 'activation_cost' => 2,
            'estimated_minutes' => 60, 'remaining_minutes' => 50,
            'sort_order' => 1,
        ]);
        $thread = CompanionThread::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id, 'task_id' => $task->id,
            'status' => CompanionThread::STATUS_ACTIVE,
            'context_scope' => ['scope' => 'task'],
        ]);
        $root = PlanArtifact::query()->create([
            'plan_id' => $plan->id, 'created_by_user_id' => $user->id,
            'provider' => 'github', 'artifact_type' => 'repository',
            'title' => 'Canovia-web',
            'url' => 'https://github.com/1kz-ma1/Canovia-web',
            'metadata' => ['github_app_connection' => [
                'status' => 'connected',
                'installation_id' => 777,
                'source_epoch' => 'b4eb4cb2-21eb-4cee-8968-cc15f7a1cda0',
            ]],
        ]);
        $evidence = TaskEvidence::query()->create([
            'plan_id' => $plan->id, 'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::GitHub->value,
            'type' => 'github_issue_observed',
            'external_key' => 'github:canovia:issue:91',
            'confidence' => 1, 'occurred_at' => now(),
            'metadata' => [
                'repo_full_name' => '1kz-ma1/Canovia-web',
                'issue_number' => 91, 'issue_state' => 'closed',
                'github_source_epoch' => 'b4eb4cb2-21eb-4cee-8968-cc15f7a1cda0',
                'github_repository_artifact_id' => $root->id,
            ],
        ]);

        return [$user, $plan, $task, $thread, $root, $evidence];
    }

    private function candidate(
        CompanionThread $thread,
        User $user,
        Plan $plan,
        Task $task,
        array $provenance,
    ): CompanionMutationCandidate {
        $assistant = $thread->messages()->create([
            'role' => 'assistant', 'content' => 'GitHub Issueは完了済み',
            'metadata' => ['source_provenance' => $provenance],
        ]);

        return CompanionMutationCandidate::query()->create([
            'companion_thread_id' => $thread->id,
            'companion_message_id' => $assistant->id,
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'task_id' => $task->id,
            'candidate_key' => (string) Str::uuid(),
            'type' => 'update_task', 'status' => 'pending',
            'title' => '次のActionを変更', 'summary' => '確認済みIssueから提案',
            'payload' => ['next_action_note' => '完了済みIssue対応'],
            'metadata' => [
                'source' => 'canovia_companion',
                'source_provenance' => $provenance,
            ],
        ]);
    }

    private function response(array $data): array
    {
        return [
            'id' => 'resp_'.Str::random(10), 'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 80, 'total_tokens' => 200],
        ];
    }
}
