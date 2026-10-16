<?php

namespace Tests\Feature;

use App\Models\RoadmapFeature;
use App\Models\RoadmapVote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanoviaFutureCarouselTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_copy_is_not_joined_to_support_by_title_and_only_real_votes_are_counted(): void
    {
        $feature = RoadmapFeature::where('feature_key', 'social.goal_peers')->firstOrFail();
        $feature->update(['title' => '活動の記録と現在地の見える化']);
        $this->post(route('feedback.future.support', $feature))->assertRedirect();
        $response = $this->get(route('feedback.index'))->assertOk();
        $response->assertSee('1 Support')->assertSee('投票対象ではありません')
            ->assertSee('shared-context.webp')->assertSee('data-carousel-viewport', false);
        preg_match('/<li[^>]+data-public-roadmap-key="progress-and-records".*?<\/li>/s', $response->getContent(), $match);
        $this->assertNotEmpty($match);
        $this->assertStringNotContainsString('/support', $match[0]);
        $this->assertDatabaseCount('roadmap_features', 6);
        $this->assertDatabaseCount('roadmap_votes', 1);
    }

    public function test_registered_voter_can_cancel_only_their_own_vote_and_hidden_or_paused_routes_deny(): void
    {
        $feature = RoadmapFeature::where('feature_key', 'social.goal_peers')->firstOrFail();
        $this->actingAs(User::factory()->create());
        $this->post(route('feedback.future.support', $feature))->assertRedirect();
        $this->post(route('feedback.future.support', $feature))->assertRedirect();
        $this->assertDatabaseCount('roadmap_votes', 1);
        $this->actingAs(User::factory()->create());
        $this->delete(route('feedback.future.unsupport', $feature))->assertRedirect();
        $this->assertDatabaseCount('roadmap_votes', 1);
        foreach ([['is_published' => false], ['is_published' => true, 'voting_enabled' => false]] as $state) {
            $feature->update($state);
            $this->post(route('feedback.future.support', $feature))->assertNotFound();
            $this->delete(route('feedback.future.unsupport', $feature))->assertNotFound();
        }
        $this->assertSame(1, RoadmapVote::count());
    }

    public function test_empty_collections_have_no_dead_carousel_controls_or_generated_features(): void
    {
        config(['canovia_public_roadmap.items' => []]);
        RoadmapFeature::query()->update(['is_published' => false]);
        $this->get(route('feedback.index'))->assertOk()
            ->assertSee('現在掲載している開発近況はありません。')
            ->assertSee('現在Supportを受け付けている候補はありません。')
            ->assertDontSee('data-future-carousel', false);
        $this->assertDatabaseCount('roadmap_features', 6);
        $this->assertDatabaseCount('roadmap_votes', 0);
    }
}
