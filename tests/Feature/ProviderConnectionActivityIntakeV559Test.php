<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Exceptions\ProviderConnectionRevokedException;
use App\Services\ProviderActivityIntakeService;
use App\Services\ProviderExecutionContextService;
use App\Services\ProviderConnectionService;
use App\Execution\ExecutionCapability;
use App\Intelligence\Study\StudyPlanIntelligenceService;
use App\Models\ExecutionActivity;
use App\Models\Plan;
use App\Models\PlanExecutionPreference;
use App\Models\ProviderConnection;
use App\Models\StudyScopeCapture;
use App\Models\StudyScopeItem;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Services\ExecutionLaunchResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderConnectionActivityIntakeV559Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'session.driver' => 'array',
            'canovia.execution_setup_validation_enabled' => true,
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_signed_provider_activity_flows_through_opaque_context_into_existing_intelligence(): void
    {
        [$user, $plan, $task] = $this->studyContext(progress: 21);
        [$connectionId, $secret] = $this->createConnection($user);
        $executionContext = $this->issueContext(
            $user,
            $plan,
            $task,
            $connectionId,
        );

        $payload = [
            'schema_version' => '1.0',
            'execution_context' => $executionContext,
            // These caller-supplied ownership/routing fields must be ignored.
            'provider_key' => 'forged-provider',
            'user_id' => 999999,
            'task_id' => 999999,
            'activity' => [
                'external_key' => 'provider-session-001',
                'type' => 'study_practice_completed',
                'title' => 'External AP practice',
                'status' => 'completed',
                'duration_seconds' => 1800,
                'metrics' => [
                    'score_percent' => 86,
                    'strengths' => ['TCP/IP', 'CIDR'],
                    'weaknesses' => ['DNS'],
                    'weakness_topics' => ['DNS'],
                    'raw_private_trace' => 'must-not-cross',
                ],
                'provider_key' => 'forged-provider',
                'task_id' => 999999,
            ],
        ];

        $response = $this->signedActivityRequest(
            $connectionId,
            $secret,
            $payload,
        );

        $response
            ->assertStatus(202)
            ->assertJson(['status' => 'accepted']);

        $activity = ExecutionActivity::query()->firstOrFail();

        $this->assertSame(
            ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
            $activity->provider_key,
        );
        $this->assertSame(
            ExecutionCapability::STUDY_PRACTICE,
            $activity->capability,
        );
        $this->assertSame($user->id, (int) $activity->user_id);
        $this->assertSame($plan->id, (int) $activity->plan_id);
        $this->assertSame($task->id, (int) $activity->task_id);
        $this->assertSame(86, data_get($activity->metrics, 'score_percent'));
        $this->assertArrayNotHasKey(
            'raw_private_trace',
            (array) $activity->metrics,
        );
        $this->assertSame('1.0', data_get(
            $activity->metadata,
            'intake_schema_version',
        ));
        $this->assertArrayNotHasKey(
            'execution_context',
            (array) $activity->metadata,
        );

        $evidence = TaskEvidence::query()->findOrFail(
            $activity->task_evidence_id,
        );

        $this->assertSame(EvidenceSource::External, $evidence->source);
        $this->assertSame('study_practice_assessed', $evidence->type);
        $this->assertSame(86, data_get($evidence->metadata, 'score_percent'));
        $this->assertSame(
            ['TCP/IP', 'CIDR'],
            data_get($evidence->metadata, 'strengths'),
        );
        $this->assertSame(
            ['DNS'],
            data_get($evidence->metadata, 'weaknesses'),
        );
        $this->assertArrayNotHasKey(
            'raw_private_trace',
            (array) $evidence->metadata,
        );

        $this->assertSame(21, (int) $task->fresh()->progress_percent);
        $this->assertSame('todo', $task->fresh()->status);

        $intelligence = app(StudyPlanIntelligenceService::class)
            ->evaluate($plan);

        $this->assertSame(
            86,
            data_get(
                $intelligence->state->metrics,
                'latest_score_percent',
            ),
        );
        $this->assertContains(
            'DNS',
            (array) data_get(
                $intelligence->state->facts,
                'observed_weaknesses',
                [],
            ),
        );

        $connection = ProviderConnection::query()
            ->where('public_id', $connectionId)
            ->firstOrFail();

        $this->assertNotNull($connection->last_used_at);
        $this->assertNotSame(
            $secret,
            (string) $connection->secret_ciphertext,
        );
    }

    public function test_redelivery_is_idempotent_through_provider_external_key(): void
    {
        [$user, $plan, $task] = $this->studyContext();
        [$connectionId, $secret] = $this->createConnection($user);
        $context = $this->issueContext(
            $user,
            $plan,
            $task,
            $connectionId,
        );

        $payload = $this->practicePayload(
            $context,
            'provider-session-repeat',
            77,
        );

        $this->signedActivityRequest(
            $connectionId,
            $secret,
            $payload,
        )->assertStatus(202);

        $this->signedActivityRequest(
            $connectionId,
            $secret,
            $payload,
        )->assertStatus(202);

        $this->assertDatabaseCount('execution_activities', 1);
        $this->assertDatabaseCount('task_evidences', 1);

        $activity = ExecutionActivity::query()->firstOrFail();
        $evidence = TaskEvidence::query()->firstOrFail();

        $this->assertSame(
            $activity->task_evidence_id,
            $evidence->id,
        );
        $this->assertSame(
            'execution-activity:'.$activity->id,
            $evidence->external_key,
        );
    }

    public function test_different_actors_can_ingest_same_provider_local_key_without_overwriting(): void
    {
        [$firstUser, $firstPlan, $firstTask] = $this->studyContext();
        [$secondUser, $secondPlan, $secondTask] = $this->studyContext();
        [$firstConnection, $firstSecret] = $this->createConnection($firstUser);
        [$secondConnection, $secondSecret] = $this->createConnection($secondUser);

        $firstContext = $this->issueContext(
            $firstUser, $firstPlan, $firstTask, $firstConnection,
        );
        $secondContext = $this->issueContext(
            $secondUser, $secondPlan, $secondTask, $secondConnection,
        );
        $sharedKey = 'provider-session-shared-across-users';

        $firstResponse = $this->signedActivityRequest(
            $firstConnection,
            $firstSecret,
            $this->practicePayload($firstContext, $sharedKey, 72),
        )->assertStatus(202);
        $firstId = (int) $firstResponse->json('activity_id');

        $secondResponse = $this->signedActivityRequest(
            $secondConnection,
            $secondSecret,
            $this->practicePayload($secondContext, $sharedKey, 91),
        )->assertStatus(202);
        $secondId = (int) $secondResponse->json('activity_id');

        $this->assertNotSame($firstId, $secondId);
        $this->assertDatabaseCount('execution_activities', 2);
        $this->assertDatabaseCount('task_evidences', 2);

        $first = ExecutionActivity::query()->findOrFail($firstId);
        $second = ExecutionActivity::query()->findOrFail($secondId);
        $this->assertSame((int) $firstUser->id, (int) $first->user_id);
        $this->assertSame((int) $firstPlan->id, (int) $first->plan_id);
        $this->assertSame((int) $firstTask->id, (int) $first->task_id);
        $this->assertSame(72, data_get($first->metrics, 'score_percent'));
        $this->assertSame((int) $secondUser->id, (int) $second->user_id);
        $this->assertSame((int) $secondPlan->id, (int) $second->plan_id);
        $this->assertSame((int) $secondTask->id, (int) $second->task_id);
        $this->assertSame(91, data_get($second->metrics, 'score_percent'));
        $this->assertNotSame($first->external_key, $second->external_key);

        // Replaying the first actor's provider-local key is still idempotent.
        $replay = $this->signedActivityRequest(
            $firstConnection,
            $firstSecret,
            $this->practicePayload($firstContext, $sharedKey, 74),
        )->assertStatus(202);
        $this->assertSame($firstId, (int) $replay->json('activity_id'));
        $this->assertDatabaseCount('execution_activities', 2);
        $this->assertDatabaseCount('task_evidences', 2);
        $this->assertSame(
            74,
            data_get(ExecutionActivity::query()->findOrFail($firstId)->metrics, 'score_percent'),
        );
        $this->assertSame(
            91,
            data_get(ExecutionActivity::query()->findOrFail($secondId)->metrics, 'score_percent'),
        );
    }

    public function test_same_actor_different_connections_have_separate_event_identities(): void
    {
        [$user, $plan, $task] = $this->studyContext();
        [$firstConnection, $firstSecret] = $this->createConnection($user, 'Primary');
        [$secondConnection, $secondSecret] = $this->createConnection($user, 'Secondary');
        $firstContext = $this->issueContext($user, $plan, $task, $firstConnection);
        $secondContext = $this->issueContext($user, $plan, $task, $secondConnection);

        $first = $this->signedActivityRequest(
            $firstConnection,
            $firstSecret,
            $this->practicePayload($firstContext, 'same-local-session', 60),
        )->assertStatus(202);
        $second = $this->signedActivityRequest(
            $secondConnection,
            $secondSecret,
            $this->practicePayload($secondContext, 'same-local-session', 80),
        )->assertStatus(202);

        $this->assertNotSame(
            (int) $first->json('activity_id'),
            (int) $second->json('activity_id'),
        );
        $this->assertDatabaseCount('execution_activities', 2);
        $this->assertDatabaseCount('task_evidences', 2);
    }

    public function test_matching_legacy_activity_rekeys_without_losing_id_or_evidence(): void
    {
        [$firstUser, $firstPlan, $firstTask] = $this->studyContext();
        [$secondUser, $secondPlan, $secondTask] = $this->studyContext();
        [$firstConnectionId, $firstSecret] = $this->createConnection($firstUser);
        [$secondConnectionId, $secondSecret] = $this->createConnection($secondUser);
        $firstConnection = ProviderConnection::query()
            ->where('public_id', $firstConnectionId)
            ->firstOrFail();

        $original = ExecutionActivity::query()->create([
            'provider_key' => ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
            'external_key' => 'legacy-shared-activity-key',
            'user_id' => (int) $firstUser->id,
            'capability' => ExecutionCapability::STUDY_PRACTICE,
            'type' => 'study_practice_completed',
            'title' => 'Historical external practice',
            'status' => 'completed',
            'metrics' => ['score_percent' => 65],
            'metadata' => [
                'intake_schema_version' => '1.0',
                'provider_connection_id' => (int) $firstConnection->id,
            ],
        ]);
        $original = app(\App\Services\ExecutionActivityService::class)
            ->linkToTask($original, $firstTask);
        $originalEvidenceId = (int) $original->task_evidence_id;

        $secondContext = $this->issueContext(
            $secondUser, $secondPlan, $secondTask, $secondConnectionId,
        );
        $secondResponse = $this->signedActivityRequest(
            $secondConnectionId,
            $secondSecret,
            $this->practicePayload($secondContext, 'legacy-shared-activity-key', 88),
        )->assertStatus(202);

        // A colliding second actor cannot take over the historical row.
        $this->assertNotSame((int) $original->id, (int) $secondResponse->json('activity_id'));
        $this->assertSame(
            (int) $firstUser->id,
            (int) ExecutionActivity::query()->findOrFail($original->id)->user_id,
        );
        $this->assertSame(
            'legacy-shared-activity-key',
            ExecutionActivity::query()->findOrFail($original->id)->external_key,
        );

        $firstContext = $this->issueContext(
            $firstUser, $firstPlan, $firstTask, $firstConnectionId,
        );
        $firstResponse = $this->signedActivityRequest(
            $firstConnectionId,
            $firstSecret,
            $this->practicePayload($firstContext, 'legacy-shared-activity-key', 90),
        )->assertStatus(202);
        $this->assertSame((int) $original->id, (int) $firstResponse->json('activity_id'));
        $updated = ExecutionActivity::query()->findOrFail($original->id);
        $this->assertSame($originalEvidenceId, (int) $updated->task_evidence_id);
        $this->assertStringStartsWith('actor:v2:', (string) $updated->external_key);
        $this->assertSame(90, data_get($updated->metrics, 'score_percent'));
        $this->assertDatabaseCount('execution_activities', 2);
        $this->assertDatabaseCount('task_evidences', 2);
    }

    public function test_rejected_task_reassociation_rolls_back_activity_update(): void
    {
        [$user, $plan, $task] = $this->studyContext();
        [$connectionId, $secret] = $this->createConnection($user);
        $originalContext = $this->issueContext($user, $plan, $task, $connectionId);

        $first = $this->signedActivityRequest(
            $connectionId,
            $secret,
            $this->practicePayload($originalContext, 'reassociation-rejected-key', 65),
        )->assertStatus(202);
        $firstId = (int) $first->json('activity_id');
        $firstEvidenceId = (int) $first->json('evidence_id');

        // Preserve the original practice classification so Provider selection
        // is valid; only the Task identity differs for this guard test.
        $differentTask = $task->replicate();
        $differentTask->save();
        $differentContext = $this->issueContext(
            $user, $plan, $differentTask, $connectionId,
        );

        $this->signedActivityRequest(
            $connectionId,
            $secret,
            $this->practicePayload($differentContext, 'reassociation-rejected-key', 99),
        )
            ->assertStatus(422)
            ->assertJson(['status' => 'invalid_activity']);

        $activity = ExecutionActivity::query()->findOrFail($firstId);
        $this->assertSame((int) $task->id, (int) $activity->task_id);
        $this->assertSame($firstEvidenceId, (int) $activity->task_evidence_id);
        $this->assertSame(65, data_get($activity->metrics, 'score_percent'));
        $this->assertSame(
            65,
            data_get(TaskEvidence::query()->findOrFail($firstEvidenceId)->metadata, 'score_percent'),
        );
        $this->assertDatabaseCount('execution_activities', 1);
        $this->assertDatabaseCount('task_evidences', 1);
    }

    public function test_invalid_signature_and_stale_timestamp_are_rejected_before_activity_persistence(): void
    {
        [$user, $plan, $task] = $this->studyContext();
        [$connectionId, $secret] = $this->createConnection($user);
        $context = $this->issueContext(
            $user,
            $plan,
            $task,
            $connectionId,
        );
        $payload = $this->practicePayload(
            $context,
            'provider-session-auth',
            80,
        );
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->call(
            'POST',
            '/api/execution/activities',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CANOVIA_CONNECTION' => $connectionId,
                'HTTP_X_CANOVIA_TIMESTAMP' => (string) now()->timestamp,
                'HTTP_X_CANOVIA_SIGNATURE' => 'sha256='.str_repeat('0', 64),
            ],
            $raw,
        )
            ->assertUnauthorized()
            ->assertJson(['status' => 'invalid_signature']);

        $staleTimestamp = (string) now()->subMinutes(10)->timestamp;
        $staleSignature = 'sha256='.hash_hmac(
            'sha256',
            $staleTimestamp.'.'.$raw,
            $secret,
        );

        $this->call(
            'POST',
            '/api/execution/activities',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CANOVIA_CONNECTION' => $connectionId,
                'HTTP_X_CANOVIA_TIMESTAMP' => $staleTimestamp,
                'HTTP_X_CANOVIA_SIGNATURE' => $staleSignature,
            ],
            $raw,
        )
            ->assertUnauthorized()
            ->assertJson(['status' => 'invalid_signature']);

        $this->assertDatabaseCount('execution_activities', 0);
        $this->assertDatabaseCount('task_evidences', 0);
    }

    public function test_execution_context_cannot_be_reused_by_another_connection(): void
    {
        [$user, $plan, $task] = $this->studyContext();

        [$firstId] = $this->createConnection($user, 'Primary');
        [$secondId, $secondSecret] = $this->createConnection(
            $user,
            'Secondary',
        );

        $context = $this->issueContext(
            $user,
            $plan,
            $task,
            $firstId,
        );

        $this->signedActivityRequest(
            $secondId,
            $secondSecret,
            $this->practicePayload(
                $context,
                'provider-session-cross-connection',
                90,
            ),
        )
            ->assertForbidden()
            ->assertJson([
                'status' => 'invalid_execution_context',
            ]);

        $this->assertDatabaseCount('execution_activities', 0);
    }

    public function test_revoked_connection_cannot_authenticate(): void
    {
        [$user, $plan, $task] = $this->studyContext();
        [$connectionId, $secret] = $this->createConnection($user);
        $context = $this->issueContext(
            $user,
            $plan,
            $task,
            $connectionId,
        );

        $connection = ProviderConnection::query()
            ->where('public_id', $connectionId)
            ->firstOrFail();

        $this->actingAs($user)
            ->delete(
                route(
                    'execution.provider_connections.destroy',
                    $connection,
                ),
            )
            ->assertOk()
            ->assertJson(['status' => 'revoked']);

        $this->signedActivityRequest(
            $connectionId,
            $secret,
            $this->practicePayload(
                $context,
                'provider-session-revoked',
                90,
            ),
        )
            ->assertUnauthorized()
            ->assertJson(['status' => 'invalid_signature']);

        $this->assertDatabaseCount('execution_activities', 0);
    }

    public function test_native_provider_cannot_be_created_as_external_connection(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(
                route(
                    'execution.provider_connections.store',
                    ['providerKey' => 'canovia.study.practice'],
                ),
                [],
            )
            ->assertStatus(422)
            ->assertJson(['status' => 'invalid_provider']);

        $this->assertDatabaseCount('provider_connections', 0);
    }

    public function test_execution_context_requires_the_selected_provider(): void
    {
        [$user, $plan, $task] = $this->studyContext();
        [$connectionId] = $this->createConnection($user);
        $connection = ProviderConnection::query()
            ->where('public_id', $connectionId)
            ->firstOrFail();

        PlanExecutionPreference::query()
            ->where('plan_id', $plan->id)
            ->where('capability', ExecutionCapability::STUDY_PRACTICE)
            ->update([
                'provider_key' => 'canovia.study.practice',
            ]);

        $this->actingAs($user)
            ->postJson(
                route(
                    'execution.provider_contexts.store',
                    [$plan, $task, $connection],
                ),
            )
            ->assertStatus(409);

        $this->assertDatabaseCount('execution_activities', 0);
    }

    public function test_revoked_connection_cannot_commit_using_previously_valid_context(): void
    {
        [$user, $plan, $task] = $this->studyContext();
        [$publicId] = $this->createConnection($user);
        $issued = $this->issueContext($user, $plan, $task, $publicId);
        $connection = ProviderConnection::where('public_id', $publicId)->firstOrFail();
        $resolved = app(ProviderExecutionContextService::class)->resolve($issued, $connection);

        app(ProviderConnectionService::class)->revoke($connection);
        $this->assertTrue($connection->isActive());

        try {
            app(ProviderActivityIntakeService::class)->accept(
                $connection, $resolved,
                $this->practicePayload($issued, 'revoked-preflight', 75)['activity'],
            );
            $this->fail('Revoked connection accepted a stale context.');
        } catch (ProviderConnectionRevokedException) {
            $this->assertDatabaseCount('execution_activities', 0);
            $this->assertDatabaseCount('task_evidences', 0);
        }
    }

    public function test_plan_transfer_before_provider_commit_denies_stale_context(): void
    {
        [$user, $plan, $task] = $this->studyContext();
        [$publicId] = $this->createConnection($user);
        $issued = $this->issueContext($user, $plan, $task, $publicId);
        $connection = ProviderConnection::where('public_id', $publicId)->firstOrFail();
        $resolved = app(ProviderExecutionContextService::class)->resolve($issued, $connection);

        $newOwner = User::factory()->create();
        $plan->update(['user_id' => $newOwner->id]);

        try {
            app(ProviderActivityIntakeService::class)->accept(
                $connection, $resolved,
                $this->practicePayload($issued, 'transferred-preflight', 81)['activity'],
            );
            $this->fail('Transferred Plan accepted a stale context.');
        } catch (ProviderConnectionRevokedException) {
            $this->assertDatabaseCount('execution_activities', 0);
            $this->assertDatabaseCount('task_evidences', 0);
        }
    }

    private function createConnection(
        User $user,
        string $label = 'Validation connection',
    ): array {
        $response = $this->actingAs($user)
            ->postJson(
                route(
                    'execution.provider_connections.store',
                    [
                        'providerKey' =>
                            ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
                    ],
                ),
                ['label' => $label],
            )
            ->assertCreated()
            ->assertJson([
                'status' => 'created',
                'connection' => [
                    'provider_key' =>
                        ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
                    'status' => 'active',
                ],
                'signing' => [
                    'algorithm' => 'HMAC-SHA256',
                ],
            ]);

        return [
            (string) $response->json('connection.public_id'),
            (string) $response->json('secret'),
        ];
    }

    private function issueContext(
        User $user,
        Plan $plan,
        Task $task,
        string $connectionId,
    ): string {
        $connection = ProviderConnection::query()
            ->where('public_id', $connectionId)
            ->firstOrFail();

        $response = $this->actingAs($user)
            ->postJson(
                route(
                    'execution.provider_contexts.store',
                    [$plan, $task, $connection],
                ),
            )
            ->assertOk()
            ->assertJson([
                'status' => 'issued',
                'provider_key' =>
                    ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
                'connection_public_id' => $connectionId,
            ]);

        $token = (string) $response->json('execution_context');
        $this->assertNotSame('', $token);
        $this->assertNull($response->json('plan_id'));
        $this->assertNull($response->json('task_id'));
        $this->assertNull($response->json('user_id'));

        return $token;
    }

    private function signedActivityRequest(
        string $connectionId,
        string $secret,
        array $payload,
    ) {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $signature = 'sha256='.hash_hmac(
            'sha256',
            $timestamp.'.'.$raw,
            $secret,
        );

        return $this->call(
            'POST',
            '/api/execution/activities',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CANOVIA_CONNECTION' => $connectionId,
                'HTTP_X_CANOVIA_TIMESTAMP' => $timestamp,
                'HTTP_X_CANOVIA_SIGNATURE' => $signature,
            ],
            $raw,
        );
    }

    private function practicePayload(
        string $executionContext,
        string $externalKey,
        int $score,
    ): array {
        return [
            'schema_version' => '1.0',
            'execution_context' => $executionContext,
            'activity' => [
                'external_key' => $externalKey,
                'type' => 'study_practice_completed',
                'title' => 'External Practice',
                'status' => 'completed',
                'completed_at' => now()->toIso8601String(),
                'metrics' => [
                    'score_percent' => $score,
                    'strengths' => ['TCP/IP'],
                    'weaknesses' => ['DNS'],
                ],
            ],
        ];
    }

    private function studyContext(
        int $progress = 0,
    ): array {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => '応用情報技術者試験',
            'description' => 'TCP/IPを重点学習',
            'category' => '資格学習',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);

        $capture = StudyScopeCapture::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
            'exam_title' => '応用情報',
            'exam_date' => today()->addMonth(),
            'confidence' => 1,
            'extraction_version' => 'study_scope_v1',
            'confirmed_at' => now(),
        ]);

        StudyScopeItem::query()->create([
            'study_scope_capture_id' => $capture->id,
            'plan_id' => $plan->id,
            'subject' => 'ネットワーク',
            'unit' => 'TCP/IP',
            'range_text' => 'TCP/IP',
            'confidence' => 1,
            'sort_order' => 0,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'TCP/IPの過去問を解く',
            'description' => 'TCP/IP Practice',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => $progress,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);

        PlanExecutionPreference::query()->create([
            'plan_id' => $plan->id,
            'capability' => ExecutionCapability::STUDY_PRACTICE,
            'provider_key' =>
                ExecutionLaunchResolver::VALIDATION_STUDY_PRACTICE_PROVIDER,
            'user_selected' => true,
        ]);

        return [$user, $plan, $task];
    }
}
