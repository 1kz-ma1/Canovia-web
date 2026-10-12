<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\NativeAiRun;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\NativeAiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NativeAiPurposeGrantFenceV05Test extends TestCase
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
            'native_ai.providers.openai.model' => 'test-model',
        ]);
    }

    public function test_feature_revoked_before_gateway_call_blocks_provider_and_run_creation(): void
    {
        [$actor, $plan, $task, $grant] = $this->scenario();
        $grant->update(['expires_at' => now()->subMinute()]);
        Http::fake();

        $this->expectDeny('native_ai_purpose_not_entitled', fn () =>
            $this->runNative($actor, $plan, $task, FeatureKey::CanoviaCompanion),
        );

        Http::assertNothingSent();
        $this->assertDatabaseCount('native_ai_runs', 0);
    }

    public function test_revoked_purpose_during_provider_response_blocks_output_and_audits_failed_run(): void
    {
        [$actor, $plan, $task, $grant] = $this->scenario();

        Http::fake(function () use ($grant) {
            $grant->update(['expires_at' => now()->subMinute()]);
            return Http::response($this->responseBody(), 200);
        });

        $this->expectDeny('native_ai_purpose_not_entitled', fn () =>
            $this->runNative($actor, $plan, $task, FeatureKey::CanoviaCompanion),
        );

        $run = NativeAiRun::query()->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame('native_ai_purpose_not_entitled', $run->error_code);
        $this->assertSame(FeatureKey::CanoviaCompanion->value, $run->feature_key);
        Http::assertSentCount(1);
    }

    public function test_ownership_transfer_during_native_call_cannot_return_old_plan_output(): void
    {
        [$actor, $plan, $task] = $this->scenario();
        $newOwner = User::factory()->create();

        Http::fake(function () use ($plan, $newOwner) {
            $plan->update(['user_id' => $newOwner->id]);
            return Http::response($this->responseBody(), 200);
        });

        $this->expectDeny('native_ai_target_changed', fn () =>
            $this->runNative($actor, $plan, $task, FeatureKey::CanoviaCompanion),
        );

        $this->assertDatabaseHas('native_ai_runs', [
            'status' => 'failed',
            'error_code' => 'native_ai_target_changed',
        ]);
        Http::assertSentCount(1);
    }

    public function test_task_move_between_plans_blocks_inflight_ai_result(): void
    {
        [$actor, $plan, $task] = $this->scenario();
        $other = $this->plan($actor);

        Http::fake(function () use ($task, $other) {
            $task->update(['plan_id' => $other->id]);
            return Http::response($this->responseBody(), 200);
        });

        $this->expectDeny('native_ai_target_changed', fn () =>
            $this->runNative($actor, $plan, $task, FeatureKey::CanoviaCompanion),
        );

        $this->assertDatabaseHas('native_ai_runs', [
            'status' => 'failed',
            'error_code' => 'native_ai_target_changed',
        ]);
    }

    public function test_collaborative_member_can_use_allowed_context_but_removal_during_ai_call_denies(): void
    {
        [$owner, $plan, $task] = $this->scenario();
        $member = User::factory()->create();
        $plan->update(['is_collaborative' => true]);
        $membership = PlanMember::query()->create([
            'plan_id' => $plan->id,
            'user_id' => $member->id,
            'role' => PlanMember::ROLE_VIEWER,
            'invited_by_user_id' => $owner->id,
            'joined_at' => now(),
        ]);
        $this->grant($member);

        Http::fake(['https://api.openai.com/v1/responses' => Http::response(
            $this->responseBody(), 200,
        )]);
        $allowed = $this->runNative($member, $plan, $task, FeatureKey::CanoviaCompanion);
        $this->assertSame('safe', $allowed['data']['reply']);

        Http::fake(function () use ($membership) {
            $membership->delete();
            return Http::response($this->responseBody(), 200);
        });

        $this->expectDeny('native_ai_target_changed', fn () =>
            $this->runNative($member, $plan, $task, FeatureKey::CanoviaCompanion),
        );
        $this->assertSame(1, NativeAiRun::query()->where('status', 'succeeded')->count());
    }

    private function runNative(User $user, Plan $plan, Task $task, FeatureKey $feature): array
    {
        return app(NativeAiGateway::class)->generateStructured(
            purpose: 'purpose_fence_test',
            prompt: 'Never return secret content on revoked context',
            schema: [
                'type' => 'object',
                'properties' => ['reply' => ['type' => 'string']],
                'required' => ['reply'],
                'additionalProperties' => false,
            ],
            schemaName: 'purpose_fence_test',
            plan: $plan,
            task: $task,
            userId: $user->id,
            featureKey: $feature,
        );
    }

    private function expectDeny(string $code, callable $run): void
    {
        try {
            $run();
            $this->fail('Unauthorized Native AI result must not be returned.');
        } catch (NativeAiExecutionException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }

    private function responseBody(): array
    {
        return [
            'id' => 'resp-purpose-fence',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(['reply' => 'safe']),
                ]],
            ]],
            'usage' => [
                'input_tokens' => 12,
                'output_tokens' => 4,
                'total_tokens' => 16,
            ],
        ];
    }

    private function scenario(): array
    {
        $actor = User::factory()->create();
        $plan = $this->plan($actor);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Purpose fence',
            'status' => 'todo',
            'priority' => 1,
            'estimated_minutes' => 20,
            'remaining_minutes' => 20,
            'progress_percent' => 0,
        ]);

        return [$actor, $plan, $task, $this->grant($actor)];
    }

    private function plan(User $actor): Plan
    {
        return Plan::query()->create([
            'user_id' => $actor->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'G3 Native AI',
            'category' => '個人開発',
            'priority' => 1,
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
    }

    private function grant(User $actor): UserProductGrant
    {
        return UserProductGrant::query()->create([
            'user_id' => $actor->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
            'expires_at' => null,
            'metadata' => ['test' => true],
        ]);
    }
}
