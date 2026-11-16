<?php

namespace Tests\Feature;

use App\Models\RoadmapFeature;
use App\Models\RoadmapVote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FutureNativeSupportTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'https://localhost/api/v1/future';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('future_support.enabled', true);
        config()->set('release_levels.preparation_only', false);
        Cache::flush();
        (require base_path('experiments/FutureSupport/database/credentials.php'))->up();
    }

    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        // Laravel defaults to [] for an empty payload; the wire contract is {}.
        return parent::json($method, $uri, $data, $headers, $options | JSON_FORCE_OBJECT);
    }

    private function actor(): string
    {
        return $this->postJson($this->base.'/actors')->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('credential');
    }

    private function client(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_two_clients_and_web_share_only_one_ledger_and_stable_order(): void
    {
        $a = $this->actor(); $b = $this->actor();
        $key = 'social.goal_peers';
        $this->client($a)->postJson($this->base.'/features/'.$key.'/support')->assertOk()->assertJsonPath('feature.support_count', 1)->assertJsonPath('feature.supported', true);
        $this->client($a)->postJson($this->base.'/features/'.$key.'/support')->assertOk()->assertJsonPath('feature.support_count', 1);
        $bList = $this->client($b)->getJson($this->base.'/features')->assertOk()->json('features');
        $row = collect($bList)->firstWhere('feature_key', $key);
        $this->assertSame(1, $row['support_count']); $this->assertFalse($row['supported']);
        $this->assertSame(RoadmapFeature::published()->pluck('feature_key')->all(), array_column($bList, 'feature_key'));
        $this->client($b)->deleteJson($this->base.'/features/'.$key.'/support')->assertOk()->assertJsonPath('feature.support_count', 1);
        $this->flushHeaders();
        $feature = RoadmapFeature::where('feature_key', $key)->firstOrFail();
        $this->post(route('feedback.future.support', $feature))->assertRedirect();
        $this->client($a)->postJson($this->base.'/features/'.$key.'/support')->assertOk()->assertJsonPath('feature.support_count', 2);
        $this->client($a)->deleteJson($this->base.'/features/'.$key.'/support')->assertOk()->assertJsonPath('feature.support_count', 1)->assertJsonPath('feature.supported', false);
        $this->client($a)->deleteJson($this->base.'/features/'.$key.'/support')->assertOk()->assertJsonPath('feature.support_count', 1);
        $this->assertSame(1, RoadmapVote::count());
    }

    public function test_credentials_are_random_hashed_and_client_ids_cannot_impersonate(): void
    {
        $a = $this->actor();
        $record = DB::table('future_native_credentials')->first();
        $this->assertSame(hash('sha256', $a), $record->token_hash);
        $this->assertStringNotContainsString($a, json_encode($record));
        $this->client($a)->postJson($this->base.'/features/social.cheer/support', ['user_id' => 1, 'voter_key' => 'user:1'])->assertUnprocessable()->assertJsonPath('code', 'FUTURE_INVALID_REQUEST');
        $this->getJson($this->base.'/features?actor_token=spoof')->assertUnprocessable();
        $this->assertSame(0, RoadmapVote::count());
        $this->withHeader('Authorization', 'Bearer fs1.00000000-0000-0000-0000-000000000000.'.str_repeat('a', 64))
            ->postJson($this->base.'/features/social.cheer/support')->assertUnauthorized();
        $this->flushHeaders();
        $this->withCookie('pace_keeper_actor_token', str_repeat('a', 64))->postJson($this->base.'/features/social.cheer/support')->assertUnauthorized();
    }

    public function test_hidden_stopped_unknown_status_and_unrecognized_keys_do_not_leak_or_mutate(): void
    {
        $a = $this->actor();
        $hidden = RoadmapFeature::where('feature_key', 'social.cheer')->firstOrFail();
        $hidden->update(['is_published' => false]);
        $stopped = RoadmapFeature::where('feature_key', 'social.goal_peers')->firstOrFail();
        $stopped->update(['voting_enabled' => false]);
        DB::table('roadmap_features')->where('feature_key', 'economy.gift')->update(['status' => 'INTERNAL']);
        $rows = $this->client($a)->getJson($this->base.'/features')->assertOk()->json('features');
        $this->assertNotContains('social.cheer', array_column($rows, 'feature_key'));
        $this->assertNotContains('economy.gift', array_column($rows, 'feature_key'));
        $this->assertFalse(collect($rows)->firstWhere('feature_key', 'social.goal_peers')['voting_enabled']);
        foreach (['social.cheer', 'social.goal_peers', 'economy.gift', 'missing.key'] as $key) {
            $this->client($a)->postJson($this->base.'/features/'.$key.'/support')->assertNotFound();
            $this->client($a)->deleteJson($this->base.'/features/'.$key.'/support')->assertNotFound();
        }
        $this->assertSame(0, RoadmapVote::count());
    }

    public function test_disabled_preparation_and_hosted_production_deny_before_storage(): void
    {
        Schema::drop('future_native_credentials');
        config()->set('future_support.enabled', false);
        $this->postJson($this->base.'/actors')->assertNotFound();
        config()->set('future_support.enabled', 'true');
        $this->postJson($this->base.'/actors')->assertNotFound();
        config()->set('future_support.enabled', true);
        config()->set('release_levels.preparation_only', true);
        $this->getJson($this->base.'/features')->assertNotFound()->assertJsonPath('code', 'PREPARATION_SURFACE_CLOSED');
        config()->set('release_levels.preparation_only', false);
        $this->app->instance('env', 'production');
        $this->postJson($this->base.'/actors')->assertNotFound()->assertJsonPath('code', 'FUTURE_UNAVAILABLE');
    }

    public function test_https_and_redirect_free_cookie_free_private_responses(): void
    {
        $this->postJson('http://localhost/api/v1/future/actors')->assertNotFound();
        config()->set('future_support.allow_loopback_http', true);
        $this->postJson('http://untrusted.example/api/v1/future/actors')->assertNotFound();
        $response = $this->getJson($this->base.'/features')->assertOk()->assertHeader('Vary', 'Authorization');
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
        $this->assertStringNotContainsString('voter_key', $response->getContent());
        $this->assertStringNotContainsString('actor_token', $response->getContent());
    }

    public function test_expiry_and_revocation_only_remove_own_votes_even_when_stopped(): void
    {
        $a = $this->actor(); $b = $this->actor();
        foreach ([$a, $b] as $token) { $this->client($token)->postJson($this->base.'/features/social.cheer/support')->assertOk(); }
        RoadmapFeature::where('feature_key', 'social.cheer')->update(['voting_enabled' => false]);
        $this->client($a)->deleteJson($this->base.'/actors/current')->assertOk()->assertJsonPath('revoked', true);
        $this->client($a)->getJson($this->base.'/features')->assertUnauthorized();
        $this->assertSame(1, RoadmapVote::count());
        DB::table('future_native_credentials')->where('token_hash', hash('sha256', $b))->update(['expires_at' => now()->subSecond()]);
        $this->client($b)->getJson($this->base.'/features')->assertUnauthorized();
        $this->assertSame(1, RoadmapVote::count());
    }

    public function test_issuance_and_actor_limits_are_independent_and_do_not_duplicate_votes(): void
    {
        $token = $this->actor(); $this->actor(); $this->actor();
        $this->postJson($this->base.'/actors')->assertStatus(429)->assertHeader('Retry-After');
        for ($i = 0; $i < 30; $i++) { $this->client($token)->postJson($this->base.'/features/social.cheer/support')->assertOk(); }
        $this->client($token)->postJson($this->base.'/features/social.cheer/support')->assertStatus(429);
        $this->assertSame(1, RoadmapVote::count());
    }

    public function test_storage_failure_is_sanitized_and_does_not_masquerade_as_zero_counts(): void
    {
        Schema::drop('future_native_credentials');
        $this->postJson($this->base.'/actors')->assertStatus(503)->assertExactJson(['code' => 'FUTURE_STORAGE_UNAVAILABLE']);
    }

    public function test_manual_roadmap_and_threshold_never_generate_candidates_or_release(): void
    {
        $a = $this->actor();
        $count = RoadmapFeature::count();
        RoadmapFeature::where('feature_key', 'social.cheer')->update(['threshold' => 1]);
        config()->set('canovia_public_roadmap.items', [['key' => 'not-a-vote', 'is_published' => true]]);
        $this->client($a)->postJson($this->base.'/features/social.cheer/support')->assertOk()->assertJsonPath('feature.status', 'voting');
        $this->assertSame($count, RoadmapFeature::count());
        $this->assertSame(1, RoadmapVote::count());
    }
}
