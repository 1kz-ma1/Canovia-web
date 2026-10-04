<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
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
        $this->assertStringNotContainsString(
            (string) $task->id,
            $token,
        );

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
