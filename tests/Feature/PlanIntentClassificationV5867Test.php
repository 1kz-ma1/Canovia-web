<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanIntentClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlanIntentClassificationV5867Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
            'native_ai.driver' => 'disabled',
        ]);
    }

    public function test_domain_specialization_and_team_hint_are_independent_facts(): void
    {
        $service = app(PlanIntentClassificationService::class);
        $hinanex = $service->suggest('HINANEXの防災支援システムをチームで開発する');
        $this->assertSame('development', $hinanex['domain']);
        $this->assertSame('ソフトウェア開発', $hinanex['category']);
        $this->assertSame('software_development', $hinanex['specialization']);
        $this->assertTrue($hinanex['team_hint']);

        $solo = $service->suggest('Canovia Webアプリを公開する');
        $this->assertSame('development', $solo['domain']);
        $this->assertFalse($solo['team_hint']);

        $this->assertSame('study', $service->suggest('応用情報技術者試験に合格したい')['domain']);
        $this->assertSame('bookkeeping', $service->suggest('簿記3級を復習する')['specialization']);
        $this->assertSame('career', $service->suggest('就活で自分に合う職種を探す')['domain']);
        $this->assertSame('creative', $service->suggest('ポスター制作をチームで進める')['domain']);
        $this->assertNull($service->suggest('卒業制作の計画を立てる')['category']);
        $this->assertNull($service->suggest('チームで何か作る')['category']);
    }

    public function test_simple_first_use_classifies_goals_without_category_form_input(): void
    {
        $owner = User::factory()->create();
        $cases = [
            ['日商簿記3級と2級の勉強を進める', '資格学習', 'workspace.study.index'],
            ['HINANEX防災支援システムをチームで開発する', 'ソフトウェア開発', 'workspace.development.index'],
            ['就活を始めて希望職種を探す', '就活・キャリア', 'workspace.career.index'],
        ];

        foreach ($cases as [$title, $category, $route]) {
            $id = (string) Str::uuid();
            $response = $this->actingAs($owner)->post(route('plans.store'), [
                'title' => $title,
                'create_request_id' => $id,
            ]);
            $plan = Plan::query()->where('creation_request_id', $id)->sole();

            $this->assertSame($category, $plan->category);
            $this->assertFalse($plan->is_collaborative, 'Title must never silently enable team sharing.');
            $response->assertRedirect(route($route, ['plan_id' => $plan->id]));

            $this->actingAs($owner)->post(route('plans.store'), [
                'title' => $title,
                'create_request_id' => $id,
            ])->assertRedirect(route($route, ['plan_id' => $plan->id]));
        }

        $this->assertDatabaseCount('plans', 3);
    }

    public function test_explicit_user_category_and_workspace_hint_take_precedence_over_heuristic(): void
    {
        $owner = User::factory()->create();

        $explicit = (string) Str::uuid();
        $this->actingAs($owner)->post(route('plans.store'), [
            'title' => 'HINANEXの防災支援システムを開発',
            'category' => '制作活動',
            'create_request_id' => $explicit,
        ])->assertRedirect();
        $plan = Plan::query()->where('creation_request_id', $explicit)->sole();
        $this->assertSame('制作活動', $plan->category);
        $this->assertSame('creative', app(PlanCategoryProfileService::class)->forPlan($plan)->key);

        $hint = (string) Str::uuid();
        $this->actingAs($owner)->post(route('plans.store'), [
            'title' => '目標を達成する',
            'workspace_mode' => 'study',
            'create_request_id' => $hint,
        ])->assertRedirect();
        $plan = Plan::query()->where('creation_request_id', $hint)->sole();
        $this->assertSame('資格学習', $plan->category);

        $unknown = (string) Str::uuid();
        $this->actingAs($owner)->post(route('plans.store'), [
            'title' => '自分のやりたいことを考える',
            'create_request_id' => $unknown,
        ])->assertRedirect();
        $plan = Plan::query()->where('creation_request_id', $unknown)->sole();
        $this->assertNull($plan->category);
    }

    public function test_team_plan_requires_separate_explicit_checkbox_and_preserves_permissions(): void
    {
        $owner = User::factory()->create();
        $id = (string) Str::uuid();

        $this->actingAs($owner)->post(route('plans.store'), [
            'title' => '学校のチームでソフトウェア開発を進める',
            'create_request_id' => $id,
            'is_collaborative' => '1',
        ])->assertRedirect();

        $plan = Plan::query()->where('creation_request_id', $id)->sole();
        $this->assertTrue($plan->is_collaborative);
        $this->assertSame($owner->id, $plan->user_id);
        $this->assertSame('ソフトウェア開発', $plan->category);
        $this->assertNotEmpty($plan->collaboration_join_code);
        $this->assertNotEmpty($plan->collaboration_share_token);

        $other = User::factory()->create();
        $this->actingAs($other)
            ->get(route('plans.edit', $plan))
            ->assertForbidden();
    }

    public function test_simple_plan_form_does_not_require_category_and_exposes_team_choice_once(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)
            ->get(route('plans.create.manual'))
            ->assertOk()
            ->assertSee('data-plan-create-collaboration', false)
            ->assertSee('Canoviaに任せる（自動）')
            ->assertSee('学習・開発・就活を判断します');

        $body = $response->getContent();
        $this->assertSame(1, substr_count($body, 'name="is_collaborative"'));
    }
}
