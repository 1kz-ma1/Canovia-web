<?php

namespace Tests\Feature;

use App\Intelligence\Data\ActionProposal;
use App\Intelligence\Data\Confidence;
use App\Models\Plan;
use App\Models\Task;
use App\Models\User;
use App\Services\DevelopmentImplementationBriefService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeveloperImplementationBriefV576Test extends TestCase
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
        ]);
    }

    public function test_service_builds_context_aware_implementation_brief_without_unbounded_provider_content(): void
    {
        [, , $task] = $this->scenario();

        $context = $this->context($task, [
            'ci' => ['state' => 'success'],
            'review' => ['state' => 'APPROVED'],
            'source_code' => 'SECRET SOURCE CODE SHOULD NEVER LEAK',
            'diff' => 'SECRET DIFF SHOULD NEVER LEAK',
            'handoff' => [
                'title' => '実装Evidenceを作る',
                'intent' => '対象Taskを実装する。',
                'done_when' => ['Commit or Pull Request Evidence exists'],
            ],
        ]);

        $action = $this->action(
            'development_implement',
            '実装Evidenceを作る',
            '対象Taskの変更をcommit/PRとして観測できる状態にします。',
            ['Commit or Pull Request Evidence exists'],
        );

        $brief = app(DevelopmentImplementationBriefService::class)
            ->build($context, $action);

        $this->assertNotNull($brief);
        $this->assertSame('implementation', $brief['mode']);
        $this->assertSame(
            '1kz-ma1/Canovia-web',
            data_get($brief, 'target.repository'),
        );
        $this->assertSame(
            'feature/v57-6-context-aware-implementation-brief',
            data_get($brief, 'target.branch'),
        );
        $this->assertSame(
            264,
            data_get($brief, 'target.pull_request_number'),
        );
        $this->assertStringContainsString(
            '既存Branch「feature/v57-6-context-aware-implementation-brief」',
            implode("\n", $brief['steps']),
        );
        $this->assertStringContainsString(
            'Commit or Pull Request Evidence exists',
            implode("\n", $brief['validation']),
        );

        $copy = (string) $brief['copy_text'];

        $this->assertStringContainsString(
            '# Canovia IMPLEMENTATION BRIEF',
            $copy,
        );
        $this->assertStringContainsString($task->title, $copy);
        $this->assertStringContainsString('1kz-ma1/Canovia-web', $copy);
        $this->assertStringNotContainsString(
            'SECRET SOURCE CODE SHOULD NEVER LEAK',
            $copy,
        );
        $this->assertStringNotContainsString(
            'SECRET DIFF SHOULD NEVER LEAK',
            $copy,
        );
    }

    public function test_ci_failure_becomes_task_scoped_ci_triage_brief(): void
    {
        [, , $task] = $this->scenario();

        $context = $this->context($task, [
            'ci' => [
                'state' => 'failure',
                'head_sha' => str_repeat('a', 40),
            ],
            'review' => null,
            'handoff' => [
                'title' => '失敗しているCIを直す',
                'intent' => 'CI failureを解消する。',
                'done_when' => ['CI state = success'],
            ],
        ]);

        $action = $this->action(
            'development_fix_ci',
            '失敗しているCIを直す',
            '自動テストの失敗原因を解消します。',
            ['CI state = success'],
        );

        $brief = app(DevelopmentImplementationBriefService::class)
            ->build($context, $action);

        $this->assertNotNull($brief);
        $this->assertSame('ci_triage', $brief['mode']);
        $this->assertSame('CI TRIAGE BRIEF', $brief['eyebrow']);
        $this->assertStringContainsString(
            '失敗CI',
            implode("\n", $brief['steps']),
        );
        $this->assertStringContainsString(
            '同じTaskにCI success Evidence',
            implode("\n", $brief['steps']),
        );
        $this->assertStringContainsString(
            'CI: failure',
            implode("\n", $brief['known_facts']),
        );
    }

    public function test_review_changes_requested_becomes_review_brief_without_storing_review_body(): void
    {
        [, , $task] = $this->scenario();

        $context = $this->context($task, [
            'ci' => ['state' => 'success'],
            'review' => [
                'state' => 'CHANGES_REQUESTED',
                'reviewer' => 'reviewer-a',
                'body' => 'PRIVATE REVIEW BODY',
            ],
            'handoff' => [
                'title' => 'レビュー指摘を解消する',
                'intent' => '指摘を反映する。',
                'done_when' => ['Review state = approved'],
            ],
        ]);

        $brief = app(DevelopmentImplementationBriefService::class)
            ->build(
                $context,
                $this->action(
                    'development_review_fix',
                    'レビュー指摘を解消する',
                    'Review指摘を反映して再確認できる状態へ戻します。',
                    ['Review state = approved'],
                ),
            );

        $this->assertNotNull($brief);
        $this->assertSame('review', $brief['mode']);
        $this->assertSame('REVIEW BRIEF', $brief['eyebrow']);
        $this->assertStringContainsString(
            'CanoviaはReview本文を保持しない',
            implode("\n", $brief['steps']),
        );
        $this->assertStringNotContainsString(
            'PRIVATE REVIEW BODY',
            (string) $brief['copy_text'],
        );
    }

    public function test_developer_home_renders_copy_ready_brief_without_remote_call_or_task_mutation(): void
    {
        [$user, $plan, $task] = $this->scenario();

        Http::fake();

        $this->actingAs($user)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
            ]))
            ->assertOk()
            ->assertSee(
                'data-development-implementation-brief',
                false,
            )
            ->assertSee('EVIDENCE BRIEF')
            ->assertSee('HOW TO PROCEED')
            ->assertSee('KNOWN STATE')
            ->assertSee('DONE WHEN')
            ->assertSee('このBriefを実装ツールへ渡す')
            ->assertSee('Briefをコピー')
            ->assertSee($task->title);

        Http::assertNothingSent();

        $task->refresh();

        $this->assertSame(35, $task->progress_percent);
        $this->assertSame('doing', $task->status);
        $this->assertSame(80, $task->remaining_minutes);
    }

    public function test_no_development_plan_does_not_render_implementation_brief(): void
    {
        $user = User::factory()->create([
            'first_run_completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('workspace.development.index'))
            ->assertOk()
            ->assertDontSee(
                'data-development-implementation-brief',
                false,
            );
    }

    private function action(
        string $kind,
        string $title,
        string $intent,
        array $successSignals,
    ): ActionProposal {
        return new ActionProposal(
            kind: $kind,
            title: $title,
            intent: $intent,
            confidence: Confidence::deterministic(),
            successSignals: $successSignals,
            metadata: [
                'target_task_id' => 1,
                'route_kind' => 'task_execution',
            ],
        );
    }

    private function context(Task $task, array $overrides = []): array
    {
        return array_replace_recursive([
            'task' => $task,
            'repository' => '1kz-ma1/Canovia-web',
            'branch' => [
                'name' => 'feature/v57-6-context-aware-implementation-brief',
                'head_sha' => str_repeat('a', 40),
                'protected' => false,
            ],
            'commit' => [
                'sha' => str_repeat('a', 40),
                'verified' => true,
            ],
            'pull_request' => [
                'number' => 264,
                'state' => 'open',
                'draft' => false,
                'url' => 'https://github.com/1kz-ma1/Canovia-web/pull/264',
                'head_ref' => 'feature/v57-6-context-aware-implementation-brief',
                'base_ref' => 'main',
                'head_sha' => str_repeat('a', 40),
            ],
            'ci' => ['state' => 'unknown'],
            'review' => ['state' => 'UNKNOWN'],
            'issue' => null,
            'deployment' => null,
            'linked_artifacts' => [],
            'recent_evidence' => [],
            'handoff' => [
                'title' => '次の開発Action',
                'intent' => '現在Stateから次へ進む。',
                'done_when' => [],
            ],
            'has_github_evidence' => true,
        ], $overrides);
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
            'title' => 'Canovia Development',
            'description' => 'Developer Implementation Brief',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'V57.6 Context-aware Implementation Brief',
            'description' => null,
            'estimated_minutes' => 120,
            'remaining_minutes' => 80,
            'progress_percent' => 35,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
