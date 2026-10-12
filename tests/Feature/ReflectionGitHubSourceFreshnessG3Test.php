<?php

namespace Tests\Feature;

use App\Enums\EvidenceSource;
use App\Enums\ProductKey;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Models\WorkLog;
use App\Services\RecentReflectionShortcutCandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReflectionGitHubSourceFreshnessG3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_revoked_reconnected_and_unsigned_github_history_cannot_promote_recent_reflection_shortcut(): void
    {
        [$user, $plan, $task, $root] = $this->scenario();
        $rows = [];
        foreach ([1, 2, 3] as $number) {
            $rows[] = $this->githubEvidence($task, $root, $number);
        }

        $this->assertNotNull($this->candidate($plan));
        $this->assertSame('satellite:reflection:recent', $this->candidate($plan)['id']);

        $metadata = (array) $root->metadata;
        $metadata['github_app_connection']['status'] = 'revoked';
        $root->update(['metadata' => $metadata]);
        $this->assertNull($this->candidate($plan));

        $metadata['github_app_connection']['status'] = 'connected';
        $metadata['github_app_connection']['source_epoch'] = (string) Str::uuid();
        $root->update(['metadata' => $metadata]);
        $this->assertNull($this->candidate($plan));

        // A legacy unsigned event remains history even under the fresh App connection.
        $unsigned = $this->githubEvidence($task, $root, 99, false);
        $this->assertNull($this->candidate($plan));

        $freshMeta = (array) $rows[0]->metadata;
        $freshMeta['github_source_epoch'] = $metadata['github_app_connection']['source_epoch'];
        $rows[0]->update(['metadata' => $freshMeta]);
        $this->assertNull($this->candidate($plan));

        $secondFreshMeta = (array) $rows[1]->metadata;
        $secondFreshMeta['github_source_epoch'] = $metadata['github_app_connection']['source_epoch'];
        $rows[1]->update(['metadata' => $secondFreshMeta]);
        $this->assertNotNull($this->candidate($plan));

        $this->assertDatabaseHas('task_evidences', ['id' => $rows[2]->id]);
        $this->assertDatabaseHas('task_evidences', ['id' => $unsigned->id]);
    }

    public function test_reflection_lens_retains_revoked_github_history_with_explicit_unverified_marker(): void
    {
        [$user, $plan, $task, $root] = $this->scenario();
        $github = $this->githubEvidence($task, $root, 10);
        $native = TaskEvidence::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source' => EvidenceSource::Native->value,
            'type' => 'guided_execution_reflected',
            'external_key' => 'native:'.Str::uuid(),
            'occurred_at' => now(),
            'metadata' => ['intent' => '自分で確認した振り返り'],
        ]);

        $before = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l2', 'intent' => 'reflection', 'reflection_context' => 'evidence',
        ]))->assertOk()->viewData('graph');

        $currentItem = $before['nodes']->firstWhere('id', 'reflection:item:evidence-'.$github->id);
        $this->assertNotNull($currentItem);
        $this->assertSame('EVIDENCE', $currentItem['eyebrow']);

        $metadata = (array) $root->metadata;
        $metadata['github_app_connection']['status'] = 'revoked';
        $root->update(['metadata' => $metadata]);

        $response = $this->actingAs($user)->get(route('map.index', [
            'level' => 'l2', 'intent' => 'reflection', 'reflection_context' => 'evidence',
        ]))->assertOk();
        $graph = $response->viewData('graph');
        $history = $graph['nodes']->firstWhere('id', 'reflection:item:evidence-'.$github->id);
        $nativeHistory = $graph['nodes']->firstWhere('id', 'reflection:item:evidence-'.$native->id);

        $this->assertNotNull($history);
        $this->assertSame('PAST · UNVERIFIED', $history['eyebrow']);
        $this->assertStringContainsString('過去の記録・現在は未検証', $history['subtitle']);
        $this->assertContains('現在は未検証', data_get($history, 'classic_surface.meta', []));
        $this->assertSame('EVIDENCE', $nativeHistory['eyebrow']);
        $this->assertStringNotContainsString('現在は未検証', $nativeHistory['subtitle']);
        $this->assertDatabaseHas('task_evidences', ['id' => $github->id]);
        $this->assertDatabaseHas('task_evidences', ['id' => $native->id]);

        // Human-owned work history remains a valid shortcut signal even after
        // GitHub source revocation; the revoked item itself is not counted.
        WorkLog::query()->create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'task_title_snapshot' => $task->title,
            'worked_on' => today(),
            'actual_minutes' => 20,
            'memo' => '本人の記録',
            'outcome' => '振り返りを確認',
        ]);
        $this->assertNotNull($this->candidate($plan));
    }

    private function candidate(Plan $plan): ?array
    {
        return app(RecentReflectionShortcutCandidateService::class)->candidate(
            collect([$plan->fresh()->load(['workLogs', 'taskEvidences'])])
        );
    }

    private function scenario(): array
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        UserProductGrant::query()->create([
            'user_id' => $user->id,
            'product_key' => ProductKey::AllAccess,
            'source' => 'manual',
            'starts_at' => now()->subMinute(),
        ]);
        $plan = Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Reflection historical sources',
            'description' => 'current and history boundaries',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today()->subWeek(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id,
            'title' => 'Inspect GitHub status',
            'description' => 'history must remain',
            'status' => 'doing',
            'progress_percent' => 20,
            'priority' => 1,
            'activation_cost' => 1,
            'estimated_minutes' => 60,
            'remaining_minutes' => 40,
            'sort_order' => 1,
        ]);
        $root = PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $user->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'Canovia-web',
            'url' => 'https://github.com/1kz-ma1/Canovia-web',
            'metadata' => ['github_app_connection' => [
                'status' => 'connected',
                'installation_id' => 777,
                'source_epoch' => '278734cc-02a1-4c4d-889d-0b58afe0e4cd',
            ]],
        ]);

        return [$user, $plan, $task, $root];
    }

    private function githubEvidence(Task $task, PlanArtifact $root, int $number, bool $signed = true): TaskEvidence
    {
        return TaskEvidence::query()->create([
            'plan_id' => $task->plan_id,
            'task_id' => $task->id,
            'user_id' => $task->plan->user_id,
            'source' => EvidenceSource::GitHub->value,
            'type' => 'github_issue_observed',
            'external_key' => 'github:'.Str::uuid(),
            'occurred_at' => now()->subMinutes($number),
            'confidence' => 1,
            'metadata' => array_filter([
                'repo_full_name' => '1kz-ma1/Canovia-web',
                'github_repository_artifact_id' => (int) $root->id,
                'github_source_epoch' => $signed
                    ? data_get($root->metadata, 'github_app_connection.source_epoch')
                    : null,
                'issue_number' => $number,
            ], fn ($value) => $value !== null),
        ]);
    }
}
