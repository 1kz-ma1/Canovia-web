<?php

namespace Tests\Feature;

use App\Models\GitHubWebhookDelivery;
use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GitHubIntegrationDiagnosticsV4611Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'canovia.admin_email' => null,
            'services.github.api_url' => 'https://api.github.com',
            'services.github.app_id' => '12345',
            'services.github.app_private_key' => $this->privateKey(),
            'services.github.app_private_key_base64' => null,
            'services.github.app_webhook_secret' => 'diagnostics-super-secret',
            'queue.default' => 'database',
        ]);
    }

    public function test_admin_can_see_healthy_github_integration_without_secret_values(): void
    {
        [$admin, $plan] = $this->adminScenario();
        config(['canovia.super_admin_user_id' => $admin->id]);

        PlanArtifact::query()->create([
            'plan_id' => $plan->id,
            'created_by_user_id' => $admin->id,
            'provider' => 'github',
            'artifact_type' => 'repository',
            'title' => 'HINANEX',
            'url' => 'https://github.com/1kz-ma1/HINANEX',
            'metadata' => [
                'github_app_connection' => [
                    'status' => 'connected',
                    'installation_id' => 777,
                ],
            ],
        ]);

        GitHubWebhookDelivery::query()->create([
            'delivery_id' => 'diagnostics-processed-001',
            'event_name' => 'pull_request',
            'action' => 'closed',
            'repo_full_name' => '1kz-ma1/HINANEX',
            'installation_id' => 777,
            'pull_request_numbers' => [55],
            'status' => 'processed',
            'matched_artifacts' => 1,
            'synced_tasks' => 1,
            'attempts' => 1,
            'received_at' => now()->subMinutes(2),
            'processed_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.github.index'))
            ->assertOk()
            ->assertSee('GITHUB INTEGRATION DIAGNOSTICS')
            ->assertSee('動作確認済み')
            ->assertSee('GitHub App')
            ->assertSee('Secret設定済み')
            ->assertSee('DATABASE')
            ->assertSee('1 repositories')
            ->assertSee('最近処理を確認')
            ->assertSee('1kz-ma1/HINANEX')
            ->assertSee(route('api.github.webhook'), false)
            ->assertDontSee('diagnostics-super-secret')
            ->assertDontSee('BEGIN PRIVATE KEY')
            ->assertDontSee('BEGIN RSA PRIVATE KEY');
    }

    public function test_stuck_delivery_is_reported_as_attention_without_showing_raw_error(): void
    {
        [$admin] = $this->adminScenario();
        config(['canovia.super_admin_user_id' => $admin->id]);

        GitHubWebhookDelivery::query()->create([
            'delivery_id' => 'diagnostics-stuck-001',
            'event_name' => 'pull_request_review',
            'action' => 'submitted',
            'repo_full_name' => '1kz-ma1/HINANEX',
            'installation_id' => 777,
            'pull_request_numbers' => [55],
            'status' => 'accepted',
            'attempts' => 0,
            'last_error' => 'PRIVATE_INTERNAL_ERROR_SHOULD_NOT_RENDER',
            'received_at' => now()->subMinutes(12),
            'created_at' => now()->subMinutes(12),
            'updated_at' => now()->subMinutes(12),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.github.index'))
            ->assertOk()
            ->assertSee('要確認')
            ->assertSee('滞留あり')
            ->assertSee('5分以上 accepted / processing')
            ->assertSee('accepted')
            ->assertSee('error')
            ->assertDontSee('PRIVATE_INTERNAL_ERROR_SHOULD_NOT_RENDER');
    }

    public function test_non_admin_cannot_open_github_diagnostics(): void
    {
        $admin = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        config(['canovia.super_admin_user_id' => $admin->id]);

        $this->actingAs($other)
            ->get(route('admin.github.index'))
            ->assertForbidden();
    }

    private function adminScenario(): array
    {
        $admin = User::factory()->create(['first_run_completed_at' => now()]);

        $plan = Plan::query()->create([
            'user_id' => $admin->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'HINANEX',
            'description' => 'GitHub diagnostics',
            'category' => '個人開発',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        return [$admin, $plan];
    }

    private function privateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($key);

        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));
        $this->assertNotSame('', $pem);

        return $pem;
    }
}
