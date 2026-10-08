<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use App\Services\PlanCategoryProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlanDomainOverrideV5868Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'session.driver' => 'array',
            'native_ai.driver' => 'disabled',
            'canovia.super_admin_user_id' => null,
            'canovia.admin_email' => null,
        ]);
    }

    public function test_owner_can_set_persistent_development_domain_without_changing_legacy_category_or_team(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, 'HINANEX', '制作活動', true);

        $this->assertSame('creative', app(PlanCategoryProfileService::class)->forPlan($plan)->key);

        $this->actingAs($owner)
            ->get(route('plans.edit', $plan))
            ->assertOk()
            ->assertSee('data-plan-domain-override', false)
            ->assertSee('開発（個人・チーム共通）');

        $this->actingAs($owner)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertNotFound();

        $this->actingAs($owner)
            ->put(route('plans.update', $plan), $this->payload($plan, 'development'))
            ->assertRedirect(route('plans.show', $plan));

        $plan = $plan->fresh();
        $this->assertSame('development', $plan->workspace_domain_override);
        $this->assertSame('制作活動', $plan->category);
        $this->assertTrue($plan->is_collaborative);
        $this->assertSame('development', app(PlanCategoryProfileService::class)->forPlan($plan)->key);
        $this->assertDatabaseCount('plan_members', 0);

        $this->actingAs($owner)
            ->get(route('workspace.development.top'))
            ->assertOk()
            ->assertSee($plan->title)
            ->assertDontSee('data-development-creative-candidate="'.$plan->id.'"', false);

        $this->actingAs($owner)
            ->get(route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'team',
            ]))
            ->assertOk()
            ->assertSee('data-development-surface="team"', false)
            ->assertDontSee('data-development-creative-active="'.$plan->id.'"', false);

        $this->actingAs($owner)
            ->get(route('plans.edit', $plan))
            ->assertOk()
            ->assertSee('value="development" selected', false);
    }

    public function test_reset_to_auto_restores_legacy_creative_profile_without_modifying_category(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $plan = $this->plan($owner, 'HINANEX', '制作活動', false);
        $plan->forceFill(['workspace_domain_override' => 'development'])->save();

        $this->actingAs($owner)
            ->put(route('plans.update', $plan), $this->payload($plan, 'auto'))
            ->assertRedirect(route('plans.show', $plan));

        $plan = $plan->fresh();
        $this->assertNull($plan->workspace_domain_override);
        $this->assertSame('制作活動', $plan->category);
        $this->assertSame('creative', app(PlanCategoryProfileService::class)->forPlan($plan)->key);

        $this->actingAs($owner)
            ->get(route('workspace.development.index', ['plan_id' => $plan->id]))
            ->assertNotFound();
        $this->actingAs($owner)
            ->get(route('workspace.development.top'))
            ->assertOk()
            ->assertSee('data-development-creative-candidate="'.$plan->id.'"', false);
    }

    public function test_editor_and_viewer_cannot_modify_plan_domain_or_shared_permissions(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $viewer = User::factory()->create();
        $plan = $this->plan($owner, '共同で制作する', '制作活動', true);

        foreach ([[$editor, PlanMember::ROLE_EDITOR], [$viewer, PlanMember::ROLE_VIEWER]] as [$member, $role]) {
            PlanMember::query()->create([
                'plan_id' => $plan->id,
                'user_id' => $member->id,
                'role' => $role,
                'invited_by_user_id' => $owner->id,
                'joined_at' => now(),
            ]);
            $this->actingAs($member)
                ->put(route('plans.update', $plan), $this->payload($plan, 'development'))
                ->assertForbidden();
        }

        $this->assertNull($plan->fresh()->workspace_domain_override);
        $this->assertSame('制作活動', $plan->fresh()->category);
        $this->assertSame(2, PlanMember::query()->count());
    }

    public function test_other_plans_and_unrecognized_domain_values_remain_unmodified(): void
    {
        $owner = User::factory()->create();
        $study = $this->plan($owner, 'AP試験', '資格学習');
        $creative = $this->plan($owner, '映像制作', '制作活動');
        $profiles = app(PlanCategoryProfileService::class);

        $this->assertSame('study', $profiles->forPlan($study)->key);
        $this->assertSame('creative', $profiles->forPlan($creative)->key);

        $this->actingAs($owner)
            ->put(route('plans.update', $study), $this->payload($study, 'untrusted'))
            ->assertSessionHasErrors('workspace_domain_override');

        $this->assertNull($study->fresh()->workspace_domain_override);
        $this->assertSame('study', $profiles->forPlan($study->fresh())->key);

        // Old update payloads without a domain field must not override it.
        $creative->forceFill(['workspace_domain_override' => 'development'])->save();
        $payload = $this->payload($creative, 'development');
        unset($payload['workspace_domain_override']);

        $this->actingAs($owner)
            ->put(route('plans.update', $creative), $payload)
            ->assertRedirect(route('plans.show', $creative));

        $this->assertSame('development', $creative->fresh()->workspace_domain_override);
        $this->assertSame('制作活動', $creative->fresh()->category);
    }

    private function plan(User $owner, string $title, string $category, bool $team = false): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'category' => $category,
            'priority' => 2,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(40),
            'is_public' => false,
            'is_collaborative' => $team,
        ]);
    }

    /** @return array<string,mixed> */
    private function payload(Plan $plan, string $domain): array
    {
        return [
            'title' => $plan->title,
            'description' => $plan->description,
            'category' => $plan->category,
            'workspace_domain_override' => $domain,
            'priority_mode' => $plan->priority_mode,
            'priority' => $plan->priority,
            'start_date' => $plan->start_date?->toDateString(),
            'deadline' => $plan->deadline?->toDateString(),
        ];
    }
}
