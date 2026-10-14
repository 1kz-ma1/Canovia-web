<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\Task;
use App\Services\DevelopmentPrelaunchPreparationService;
use PHPUnit\Framework\TestCase;

class DevelopmentPrelaunchPreparationServiceTest extends TestCase
{
    private DevelopmentPrelaunchPreparationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DevelopmentPrelaunchPreparationService();
    }

    public function test_prototype_without_launch_task_does_not_receive_an_automatic_prompt(): void
    {
        $plan = $this->plan('アプリの試作', [
            new Task(['title' => 'ログイン画面を作る', 'status' => 'todo']),
        ]);

        $this->assertNull($this->service->suggest($plan));
    }

    public function test_only_unfinished_launch_work_triggers_contextual_suggestion(): void
    {
        $plan = $this->plan('アプリ開発', [
            new Task(['title' => '初回公開の準備', 'status' => 'todo']),
        ]);

        $proposal = $this->service->suggest($plan);

        $this->assertSame('identify_production_and_data', $proposal['key']);
        $this->assertSame('unknown', $proposal['status']);
        $this->assertStringContainsString('未完了Task', $proposal['basis']);
    }

    public function test_completed_or_cancelled_launch_tasks_do_not_trigger(): void
    {
        $plan = $this->plan('ゲームを作る', [
            new Task(['title' => '本番デプロイ', 'status' => 'done']),
            new Task(['title' => '公開準備', 'status' => 'cancelled']),
        ]);

        $this->assertNull($this->service->suggest($plan));
    }

    public function test_explicit_check_works_without_launch_evidence_but_remains_unknown(): void
    {
        $plan = $this->plan('個人開発', []);

        $proposal = $this->service->suggest($plan, true);

        $this->assertSame('unknown', $proposal['status']);
        $this->assertSame('公開準備の確認を選択', $proposal['basis']);
        $this->assertStringContainsString('DBを使わない場合', $proposal['detail']);
    }

    public function test_unloaded_tasks_do_not_trigger_queries_or_proposals(): void
    {
        $plan = new Plan(['title' => '初回公開']);

        $this->assertNull($this->service->suggest($plan));
        $this->assertNull($this->service->suggest($plan, true));
    }

    public function test_plan_sharing_is_not_mistaken_for_public_app_launch(): void
    {
        $plan = $this->plan('開発', []);
        $plan->is_public = true;

        $this->assertNull($this->service->suggest($plan));
    }

    private function plan(string $title, array $tasks): Plan
    {
        $plan = new Plan(['title' => $title]);
        $plan->setRelation('tasks', collect($tasks));

        return $plan;
    }
}
