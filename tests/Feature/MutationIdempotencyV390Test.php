<?php

namespace Tests\Feature;

use App\Enums\BehaviorEventType;
use App\Models\BehaviorEvent;
use App\Models\Plan;
use App\Models\PlanAdjustment;
use App\Models\PwaHandoff;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MutationIdempotencyV390Test extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_plan_create_request_converges_on_one_plan(): void
    {
        $user = User::factory()->create();
        $requestId = (string) Str::uuid();
        $payload = [
            'title' => '重複しない計画',
            'category' => '個人開発',
            'start_date' => today()->toDateString(),
            'deadline' => today()->addMonth()->toDateString(),
            'create_request_id' => $requestId,
        ];

        $first = $this->actingAs($user)->post(route('plans.store'), $payload);
        $plan = Plan::firstOrFail();
        $first->assertRedirect(route('workspace.development.index', ['plan_id' => $plan->id]));

        $second = $this->actingAs($user)->post(route('plans.store'), $payload);
        $second->assertRedirect(route('workspace.development.index', ['plan_id' => $plan->id]));

        $this->assertDatabaseCount('plans', 1);
        $this->assertSame($requestId, $plan->fresh()->creation_request_id);
    }

    public function test_repeated_initial_plan_json_import_does_not_duplicate_tasks(): void
    {
        $user = User::factory()->create();
        $plan = $this->planFor($user, '初期計画');
        $payload = [
            'schema_version' => '2.0',
            'flow' => 'plan_generation',
            'target_plan' => ['id' => $plan->id, 'title' => $plan->title, 'category' => '個人開発'],
            'summary' => '一度だけ反映',
            'operations' => [
                [
                    'type' => 'add_task',
                    'client_ref' => 'task_1',
                    'title' => '重複してはいけないTask',
                    'description' => '1件だけ存在する',
                    'estimated_minutes' => 60,
                    'remaining_minutes' => 60,
                    'progress_percent' => 0,
                    'progress_reason' => '未着手',
                    'status' => 'todo',
                    'priority' => 1,
                    'activation_cost' => 1,
                ],
                [
                    'type' => 'reorder_tasks',
                    'items' => [['task_ref' => 'task_1']],
                    'reason' => '最初に実施',
                ],
            ],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $this->actingAs($user)
            ->post(route('plans.ai_task_assistant.import', $plan), ['tasks_json' => $json])
            ->assertRedirect(route('plans.show', $plan));

        $this->actingAs($user)
            ->post(route('plans.ai_task_assistant.import', $plan), ['tasks_json' => $json])
            ->assertRedirect(route('plans.show', $plan));

        $this->assertSame(1, Task::where('plan_id', $plan->id)->count());
        $this->assertSame(1, PlanAdjustment::where('plan_id', $plan->id)->where('flow', 'plan_generation')->count());
        $this->assertNotNull(PlanAdjustment::where('plan_id', $plan->id)->firstOrFail()->request_hash);
    }

    public function test_repeated_plan_update_apply_survives_session_cleanup_without_reapplying(): void
    {
        $user = User::factory()->create();
        $plan = $this->planFor($user, '更新前');
        $token = 'retry-safe-proposal-token';
        $draft = [
            'flow' => 'plan_update',
            'prompt' => 'test prompt',
        ];
        $proposal = [
            'token' => $token,
            'can_apply' => true,
            'action' => 'update_progress',
            'flow' => 'plan_update',
            'summary' => 'タイトル変更',
            'raw_json' => '{"flow":"plan_update"}',
            'operations' => [
                [
                    'type' => 'update_plan',
                    'title' => '更新後',
                ],
            ],
        ];
        $session = [
            'plan_review_drafts.'.$plan->id => $draft,
            'plan_review_proposals.'.$plan->id => $proposal,
        ];
        $request = [
            'proposal_token' => $token,
            'selected_operations' => [0],
            'return_to' => 'plan',
        ];

        $this->actingAs($user)
            ->withSession($session)
            ->post(route('plans.review_assistant.apply', $plan), $request)
            ->assertRedirect(route('plans.show', $plan));

        // The first success removes draft/proposal from the session. A browser
        // retry must still resolve as the same successful mutation.
        $this->actingAs($user)
            ->post(route('plans.review_assistant.apply', $plan), $request)
            ->assertRedirect(route('plans.show', $plan));

        $this->assertSame('更新後', $plan->fresh()->title);
        $this->assertSame(1, PlanAdjustment::where('plan_id', $plan->id)->count());
    }

    public function test_work_start_request_and_existing_active_session_both_converge_on_one_timer(): void
    {
        $user = User::factory()->create();
        $plan = $this->planFor($user, 'タイマー');
        $task = $this->taskFor($plan);
        $actorToken = Str::random(64);
        $requestId = (string) Str::uuid();
        $payload = [
            'task_id' => $task->id,
            'intended_minutes' => 25,
            'source' => 'plan',
            'start_request_id' => $requestId,
        ];

        $first = $this->actingAs($user)
            ->withSession(['pace_keeper.actor_token' => $actorToken])
            ->post(route('work_sessions.start'), $payload);
        $session = WorkSession::firstOrFail();
        $first->assertRedirect(route('work_sessions.active', $session));

        $this->actingAs($user)
            ->withSession(['pace_keeper.actor_token' => $actorToken])
            ->post(route('work_sessions.start'), $payload)
            ->assertRedirect(route('work_sessions.active', $session));

        $differentClick = array_merge($payload, ['start_request_id' => (string) Str::uuid()]);
        $this->actingAs($user)
            ->withSession(['pace_keeper.actor_token' => $actorToken])
            ->post(route('work_sessions.start'), $differentClick)
            ->assertRedirect(route('work_sessions.active', $session));

        $this->assertDatabaseCount('work_sessions', 1);
        $this->assertSame($requestId, $session->fresh()->start_request_id);
        $this->assertSame(
            1,
            BehaviorEvent::where('event_type', BehaviorEventType::WorkStarted->value)->count()
        );
    }

    public function test_normal_manifest_is_stable_and_does_not_allocate_handoff_tokens(): void
    {
        $this->getJson(route('pwa.manifest'))
            ->assertOk()
            ->assertJsonPath('start_url', '/');

        $this->getJson(route('pwa.manifest'))
            ->assertOk()
            ->assertJsonPath('start_url', '/');

        $this->assertDatabaseCount('pwa_handoffs', 0);
    }

    public function test_install_manifest_uses_only_the_explicit_bootstrap_token(): void
    {
        $user = User::factory()->create();

        $prepare = $this->actingAs($user)->get(route('pwa.install.prepare'));
        $location = (string) $prepare->headers->get('Location');
        $path = (string) parse_url($location, PHP_URL_PATH);
        $token = basename($path);

        $this->assertDatabaseCount('pwa_handoffs', 1);

        $this->actingAs($user)
            ->getJson(route('pwa.manifest', ['handoff' => $token]))
            ->assertOk()
            ->assertJsonPath(
                'start_url',
                route('pwa.handoff', ['token' => $token, 'launch' => 1], false)
            );

        // Fetching the install-scoped manifest must reuse the prepared token,
        // not allocate a fresh PwaHandoff row behind the scenes.
        $this->assertDatabaseCount('pwa_handoffs', 1);
    }

    public function test_head_probe_does_not_consume_pwa_handoff_and_relaunch_is_quiet(): void
    {
        $user = User::factory()->create();

        $prepare = $this->actingAs($user)->get(route('pwa.install.prepare'));
        $location = (string) $prepare->headers->get('Location');
        $path = (string) parse_url($location, PHP_URL_PATH);
        $token = basename($path);

        $this->assertNotSame('', $token);
        $this->assertDatabaseCount('pwa_handoffs', 1);
        $handoff = PwaHandoff::firstOrFail();

        $this->actingAs($user)
            ->head(route('pwa.handoff', ['token' => $token, 'launch' => 1]))
            ->assertNoContent();

        $this->assertNull($handoff->fresh()->used_at);

        $this->actingAs($user)
            ->get(route('pwa.handoff', ['token' => $token, 'launch' => 1]))
            ->assertRedirect(route('home'));

        $this->assertNotNull($handoff->fresh()->used_at);

        // An installed app may retain the bootstrap URL for a while. Once used,
        // the same launch URL should quietly return Home instead of an expiry error.
        $this->actingAs($user)
            ->get(route('pwa.handoff', ['token' => $token, 'launch' => 1]))
            ->assertRedirect(route('home'));
    }

    private function planFor(User $user, string $title): Plan
    {
        return Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'category' => '個人開発',
            'start_date' => today()->toDateString(),
            'deadline' => today()->addMonth()->toDateString(),
            'is_public' => false,
        ]);
    }

    private function taskFor(Plan $plan): Task
    {
        return Task::create([
            'plan_id' => $plan->id,
            'title' => '作業する',
            'description' => 'タイマーを開始する',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 0,
            'status' => 'todo',
            'priority' => 1,
            'activation_cost' => 1,
            'sort_order' => 1,
        ]);
    }
}
