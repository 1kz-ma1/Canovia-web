<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\Plan;
use App\Models\User;
use App\Services\FirstRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductPolishV14Test extends TestCase
{
    use RefreshDatabase;

    public function test_feedback_model_uses_feedbacks_table(): void
    {
        $this->assertSame('feedbacks', (new Feedback())->getTable());
    }

    public function test_rating_only_feedback_can_be_saved(): void
    {
        $user = User::factory()->create();
        $expectedVersion = (string) config('canovia.version');

        $this->actingAs($user)->post(route('feedback.store'), [
            'type' => 'positive',
            'rating' => 5,
            'message' => '',
            'page' => '/',
        ])->assertRedirect();

        $feedback = Feedback::firstOrFail();
        $this->assertSame(5, $feedback->rating);
        $this->assertSame('', $feedback->message);
        $this->assertSame($expectedVersion, $feedback->app_version);
    }

    public function test_home_surfaces_new_plan_action_without_public_plan_discovery_link(): void
    {
        $response = $this->withCookie(FirstRunService::COOKIE, '1')->get(route('home'));

        $response->assertOk();
        $response->assertSee('新しい計画');
        $response->assertDontSee('公開計画');
    }

    public function test_timeline_can_recommend_a_similar_public_plan_without_public_plan_index_navigation(): void
    {
        $user = User::factory()->create();
        $peer = User::factory()->create(['name' => 'Peer User']);

        $this->createPlan($user, '自分の資格学習', '資格学習', false);
        $publicPlan = $this->createPlan($peer, '同じ資格を進める計画', '資格学習', true);

        $response = $this->actingAs($user)->get(route('timeline.index'));

        $response->assertOk();
        $response->assertSee('似た目標を進めている人');
        $response->assertSee($publicPlan->title);
        $response->assertSee(route('public_plans.show', $publicPlan->public_slug), false);
        $response->assertDontSee(route('public_plans.index'), false);
    }

    private function createPlan(User $user, string $title, string $category, bool $public): Plan
    {
        return Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => Str::uuid()->toString(),
            'title' => $title,
            'category' => $category,
            'start_date' => today()->toDateString(),
            'deadline' => today()->addMonth()->toDateString(),
            'is_public' => $public,
        ]);
    }
}
