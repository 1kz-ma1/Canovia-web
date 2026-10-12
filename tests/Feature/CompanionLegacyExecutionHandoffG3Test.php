<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Models\CompanionMessage;
use App\Models\CompanionMutationCandidate;
use App\Models\CompanionThread;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\CompanionDerivedEvidenceSourceGuard;
use App\Services\CompanionMutationApplyService;
use App\Services\ExecutionRequestHandoffService;
use App\Services\ExecutionDistributionService;
use App\Services\ExecutionGitHubHandoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CompanionLegacyExecutionHandoffG3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
            'features.flags.'.FeatureKey::CanoviaCompanion->value => [
                'enabled' => true, 'environment' => null,
                'platform' => 'all', 'minimum_app_version' => null,
            ],
        ]);
    }

    public function test_unsigned_legacy_ai_proposal_in_github_plan_is_history_only_even_when_connected(): void
    {
        [$user, $plan, $task, $thread, $root] = $this->scenario(true);
        $legacy = $this->candidate($user, $plan, $task, $thread, null);

        $this->actingAs($user)
            ->get(route('companion.show', $thread))
            ->assertOk()
            ->assertSee('data-companion-source-stale', false)
            ->assertDontSee(
                'action="'.route('companion.candidates.apply', [$thread, $legacy]).'"',
                false,
            )
            ->assertSee('出典は現在検証できません');

        $this->actingAs($user)
            ->post(route('companion.candidates.apply', [$thread, $legacy]), [
                'apply_request_id' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('candidate');

        $this->assertSame('pending', $legacy->fresh()->status);
        $this->assertSame('doing', $task->fresh()->status);
        $this->assertSame('Review Task', $task->fresh()->title);
        $this->assertDatabaseCount('companion_mutation_candidates', 1);

        $this->assertFalse(app(CompanionDerivedEvidenceSourceGuard::class)
            ->isCurrent($plan, null));
        $this->assertDatabaseHas('plan_artifacts', ['id' => $root->id]);
    }

    public function test_unsigned_legacy_candidate_outside_github_plan_keeps_existing_confirmation_path(): void
    {
        [$user, $plan, $task, $thread] = $this->scenario(false);
        $legacy = $this->candidate($user, $plan, $task, $thread, null);
        $this->assertTrue(app(CompanionDerivedEvidenceSourceGuard::class)
            ->isCurrent($plan, null));

        $this->actingAs($user)
            ->post(route('companion.candidates.apply', [$thread, $legacy]), [
                'apply_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('applied', $legacy->fresh()->status);
        $this->assertSame('確認済みの次Action', $task->fresh()->next_action_note);
    }

    public function test_historical_companion_execution_handoff_cannot_recreate_packet_after_reconnect(): void
    {
        [$user, $plan, $task, $thread, $root] = $this->scenario(true);
        $signed = $this->signedSource($plan, $task, $root);
        $candidate = $this->candidate($user, $plan, $task, $thread, $signed, true);

        $this->actingAs($user)
            ->post(route('companion.candidates.apply', [$thread, $candidate]), [
                'apply_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $key = ExecutionRequestHandoffService::sessionKey($plan, $task);
        $state = session($key);
        $this->assertSame('applied', $candidate->fresh()->status);
        $this->assertSame('companion_candidate', data_get($state, 'execution_request.source.type'));
        $handoff = app(ExecutionRequestHandoffService::class);
        $this->assertTrue($handoff->sourceIsCurrent(
            $state['execution_request'], $plan, $task,
        ));

        $meta = (array) $root->metadata;
        $meta['github_app_connection']['status'] = 'revoked';
        $root->update(['metadata' => $meta]);
        $this->assertFalse($handoff->sourceIsCurrent(
            $state['execution_request'], $plan, $task,
        ));

        // Direct stale POST cannot convert an already confirmed AI request
        // to a new external instruction even if its old session remains.
        $this->actingAs($user)
            ->post(route('plans.tasks.execution_orchestration.prepare', [$plan, $task]), [
                'generation_mode' => 'external', 'actor_type' => 'ai',
                'available_minutes' => 30,
            ])
            ->assertSessionHasErrors('candidate');
        $this->assertNull(data_get(session($key), 'handoff_prompt'));

        $this->actingAs($user)
            ->get(route('plans.tasks.execution_orchestration.show', [$plan, $task]))
            ->assertOk()
            ->assertSee('data-execution-handoff-source-stale', false)
            ->assertDontSee('CONFIRMED EXECUTION REQUEST');
        $this->assertNull(data_get(session($key), 'execution_request'));

        $meta['github_app_connection']['status'] = 'connected';
        $meta['github_app_connection']['source_epoch'] = (string) Str::uuid();
        $root->update(['metadata' => $meta]);
        $this->assertFalse($handoff->sourceIsCurrent(
            $state['execution_request'], $plan, $task,
        ));
        $this->assertDatabaseCount('companion_mutation_candidates', 1);
    }

    public function test_signed_handoff_rejects_mutated_candidate_instruction_and_cross_plan_selection(): void
    {
        [$user, $plan, $task, $thread, $root] = $this->scenario(true);
        $candidate = $this->candidate($user, $plan, $task, $thread,
            $this->signedSource($plan, $task, $root), true);
        $this->actingAs($user)
            ->post(route('companion.candidates.apply', [$thread, $candidate]), [
                'apply_request_id' => (string) Str::uuid(),
            ])->assertSessionHasNoErrors();

        $request = session(ExecutionRequestHandoffService::sessionKey($plan, $task))['execution_request'];
        $sources = app(ExecutionRequestHandoffService::class);
        $this->assertTrue($sources->sourceIsCurrent($request, $plan, $task));

        $altered = $request;
        $altered['instruction'] = '別の未承認の指示';
        $this->assertFalse($sources->sourceIsCurrent($altered, $plan, $task));

        $foreign = $request;
        $foreign['target_plan']['id'] = 999;
        $this->assertFalse($sources->sourceIsCurrent($foreign, $plan, $task));

        $candidate->update(['payload' => [
            'instruction' => '後から変更された指示', 'actor_type' => 'ai',
        ]]);
        $this->assertFalse($sources->sourceIsCurrent($request, $plan, $task));
    }

    public function test_batch_execution_rejects_stale_companion_handoff_and_github_change_preview_is_hidden(): void
    {
        [$user, $plan, $task, $thread, $root] = $this->scenario(true);
        $candidate = $this->candidate($user, $plan, $task, $thread,
            $this->signedSource($plan, $task, $root), true);
        $this->actingAs($user)
            ->post(route('companion.candidates.apply', [$thread, $candidate]), [
                'apply_request_id' => (string) Str::uuid(),
            ])->assertSessionHasNoErrors();

        $key = ExecutionRequestHandoffService::sessionKey($plan, $task);
        $meta = (array) $root->metadata;
        $meta['github_app_connection']['status'] = 'revoked';
        $root->update(['metadata' => $meta]);

        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session')->driver());

        try {
            app(ExecutionDistributionService::class)->prepare(
                $request, $plan, new Collection([$task]),
                [$task->id => ['actor_type' => 'ai']], 'external',
            );
            $this->fail('Invalid Companion handoff must block distribution');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('candidate', $exception->errors());
        }

        session()->put(ExecutionGitHubHandoffService::sessionKey($plan, $task), [
            'change' => ['content_encrypted' => Crypt::encryptString('old')],
        ]);
        $this->assertNull(app(ExecutionGitHubHandoffService::class)
            ->candidate($request, $plan, $task));
        $this->assertNull(session(ExecutionGitHubHandoffService::sessionKey($plan, $task)));
        $this->assertSame('companion_candidate', data_get(session($key), 'execution_request.source.type'));
    }

    private function scenario(bool $github): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        UserProductGrant::query()->create([
            'user_id' => $user->id, 'product_key' => ProductKey::AllAccess,
            'source' => 'manual', 'starts_at' => now()->subMinute(),
        ]);
        $plan = Plan::query()->create([
            'user_id' => $user->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(), 'title' => 'Development',
            'description' => 'Legacy Companion', 'category' => '個人開発',
            'priority' => 1, 'priority_mode' => 'manual',
            'start_date' => today(), 'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'Review Task',
            'description' => 'Review', 'status' => 'doing',
            'progress_percent' => 20, 'priority' => 1, 'activation_cost' => 2,
            'estimated_minutes' => 50, 'remaining_minutes' => 40, 'sort_order' => 1,
        ]);
        $thread = CompanionThread::query()->create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'task_id' => $task->id, 'status' => CompanionThread::STATUS_ACTIVE,
            'context_scope' => ['scope' => 'task'],
        ]);
        if (! $github) {
            return [$user, $plan, $task, $thread, null];
        }

        $root = PlanArtifact::query()->create([
            'plan_id' => $plan->id, 'created_by_user_id' => $user->id,
            'provider' => 'github', 'artifact_type' => 'repository',
            'title' => 'Canovia-web',
            'url' => 'https://github.com/1kz-ma1/Canovia-web',
            'metadata' => ['github_app_connection' => [
                'status' => 'connected', 'installation_id' => 777,
                'source_epoch' => '05ed9e30-7f57-4342-8b76-e222f1970110',
            ]],
        ]);

        return [$user, $plan, $task, $thread, $root];
    }

    private function candidate(
        User $user,
        Plan $plan,
        Task $task,
        CompanionThread $thread,
        ?array $provenance,
        bool $execution = false,
    ): CompanionMutationCandidate {
        $message = $thread->messages()->create([
            'role' => 'assistant', 'content' => '歴史的なAI候補です',
        ]);
        return CompanionMutationCandidate::query()->create([
            'companion_thread_id' => $thread->id,
            'companion_message_id' => $message->id,
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'task_id' => $task->id, 'candidate_key' => (string) Str::uuid(),
            'type' => $execution ? 'prepare_execution_request' : 'update_task',
            'status' => 'pending', 'title' => 'AI提案',
            'summary' => '旧コンテキスト',
            'payload' => $execution
                ? ['instruction' => '元のAIで作られた指示', 'actor_type' => 'ai', 'available_minutes' => 25]
                : ['next_action_note' => '確認済みの次Action'],
            'metadata' => $provenance === null
                ? ['source' => 'canovia_companion']
                : ['source' => 'canovia_companion', 'source_provenance' => $provenance],
        ]);
    }

    private function signedSource(Plan $plan, Task $task, PlanArtifact $root): array
    {
        $evidence = TaskEvidence::query()->create([
            'plan_id' => $plan->id, 'task_id' => $task->id,
            'user_id' => $plan->user_id, 'source' => EvidenceSource::GitHub->value,
            'type' => 'github_issue_observed', 'external_key' => 'github:canovia:issue:81',
            'occurred_at' => now(),
            'metadata' => [
                'repo_full_name' => '1kz-ma1/Canovia-web',
                'issue_state' => 'closed',
                'github_repository_artifact_id' => $root->id,
                'github_source_epoch' => data_get($root->metadata, 'github_app_connection.source_epoch'),
            ],
        ]);
        return ['version' => 1, 'github_evidence' => [[
            'id' => $evidence->id,
            'revision' => app(CompanionDerivedEvidenceSourceGuard::class)->revision($evidence),
        ]]];
    }
}
