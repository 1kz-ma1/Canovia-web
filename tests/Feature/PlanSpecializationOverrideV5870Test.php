<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanSpecializationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlanSpecializationOverrideV5870Test extends TestCase
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

    public function test_owner_can_confirm_and_reset_a_specialization_without_changing_domain_or_team(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, 'HINANEXのシステム開発', 'ソフトウェア開発', true);
        $specializations = app(PlanSpecializationService::class);

        $inferred = $specializations->forPlan($plan);
        $this->assertSame('development', $inferred['domain']);
        $this->assertSame('software_development', $inferred['key']);
        $this->assertSame('inferred', $inferred['source']);
        $this->assertNull($plan->workspace_specialization_override);

        $this->actingAs($owner)
            ->get(route('plans.edit', $plan))
            ->assertOk()
            ->assertSee('data-plan-specialization', false)
            ->assertSee('推定候補')
            ->assertSee('Webサービス開発')
            ->assertSee('data-plan-specialization-save', false);

        $this->actingAs($owner)
            ->put(route('plans.specialization.update', $plan), [
                'workspace_specialization_override' => 'web_development',
            ])
            ->assertRedirect(route('plans.edit', $plan));

        $saved = $plan->fresh();
        $this->assertSame('web_development', $saved->workspace_specialization_override);
        $this->assertSame('development', app(PlanCategoryProfileService::class)->forPlan($saved)->key);
        $this->assertSame('ソフトウェア開発', $saved->category);
        $this->assertTrue($saved->is_collaborative);
        $this->assertSame('owner_confirmed', $specializations->forPlan($saved)['source']);

        $this->actingAs($owner)
            ->get(route('plans.edit', $saved))
            ->assertOk()
            ->assertSee('value="web_development" selected', false);

        $this->actingAs($owner)
            ->put(route('plans.specialization.update', $plan), [
                'workspace_specialization_override' => 'auto',
            ])
            ->assertRedirect(route('plans.edit', $plan));

        $this->assertNull($plan->fresh()->workspace_specialization_override);
        $this->assertSame('inferred', $specializations->forPlan($plan->fresh())['source']);
    }

    public function test_unrelated_domain_specializations_are_rejected_before_any_write(): void
    {
        $owner = User::factory()->create();
        $study = $this->plan($owner, '日商簿記3級復習', '資格学習');
        $other = $this->plan($owner, 'Canovia', '個人開発');

        $this->actingAs($owner)
            ->put(route('plans.specialization.update', $study), [
                'workspace_specialization_override' => 'software_development',
            ])
            ->assertSessionHasErrors('workspace_specialization_override');

        $this->assertNull($study->fresh()->workspace_specialization_override);
        $this->assertNull($other->fresh()->workspace_specialization_override);

        $this->actingAs($owner)
            ->put(route('plans.specialization.update', $study), [
                'workspace_specialization_override' => 'bookkeeping',
            ])
            ->assertRedirect();

        $this->assertSame('bookkeeping', $study->fresh()->workspace_specialization_override);
        $this->assertSame('study', app(PlanCategoryProfileService::class)->forPlan($study->fresh())->key);
    }

    public function test_owner_domain_change_clears_incompatible_specialization_but_preserves_legacy_category(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, 'チームでHINANEXを開発', '制作活動', true);
        $plan->forceFill([
            'workspace_domain_override' => 'development',
            'workspace_specialization_override' => 'software_development',
        ])->save();

        $this->actingAs($owner)
            ->put(route('plans.update', $plan), [
                'title' => $plan->title,
                'description' => $plan->description,
                'category' => $plan->category,
                'workspace_domain_override' => 'study',
            ])
            ->assertRedirect(route('plans.show', $plan));

        $saved = $plan->fresh();
        $this->assertSame('study', $saved->workspace_domain_override);
        $this->assertNull($saved->workspace_specialization_override);
        $this->assertSame('制作活動', $saved->category);
        $this->assertTrue($saved->is_collaborative);
        $this->assertSame('study', app(PlanCategoryProfileService::class)->forPlan($saved)->key);
    }

    public function test_old_form_submission_keeps_existing_specialization_when_domain_is_unchanged(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, '簿記復習', '資格学習');
        $plan->forceFill(['workspace_specialization_override' => 'bookkeeping'])->save();

        $this->actingAs($owner)
            ->put(route('plans.update', $plan), [
                'title' => '簿記復習を改善する',
                'category' => '資格学習',
            ])
            ->assertRedirect(route('plans.show', $plan));

        $this->assertSame('bookkeeping', $plan->fresh()->workspace_specialization_override);
        $this->assertSame('簿記復習を改善する', $plan->fresh()->title);
    }

    public function test_shared_editor_viewer_and_unrelated_user_cannot_confirm_a_specialization(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, '共同Webシステム開発', '個人開発', true);
        $editor = User::factory()->create();
        $viewer = User::factory()->create();
        foreach ([[$editor, PlanMember::ROLE_EDITOR], [$viewer, PlanMember::ROLE_VIEWER]] as [$member, $role]) {
            PlanMember::query()->create([
                'plan_id' => $plan->id,
                'user_id' => $member->id,
                'role' => $role,
                'invited_by_user_id' => $owner->id,
                'joined_at' => now(),
            ]);

            $this->actingAs($member)
                ->put(route('plans.specialization.update', $plan), [
                    'workspace_specialization_override' => 'software_development',
                ])
                ->assertForbidden();
        }

        $this->actingAs(User::factory()->create())
            ->put(route('plans.specialization.update', $plan), [
                'workspace_specialization_override' => 'software_development',
            ])
            ->assertForbidden();

        $this->assertNull($plan->fresh()->workspace_specialization_override);
        $this->assertDatabaseCount('plan_members', 2);
    }

    public function test_legacy_creative_plan_with_development_override_has_no_unverified_specialization(): void
    {
        $owner = User::factory()->create();
        $plan = $this->plan($owner, 'HINANEX', '制作活動', true);
        $plan->forceFill(['workspace_domain_override' => 'development'])->save();

        $result = app(PlanSpecializationService::class)->forPlan($plan->fresh());
        $this->assertSame('development', $result['domain']);
        $this->assertNull($result['key']);
        $this->assertSame('unconfirmed', $result['source']);

        $this->actingAs($owner)
            ->put(route('plans.specialization.update', $plan), [
                'workspace_specialization_override' => 'software_development',
            ])
            ->assertRedirect();
        $this->assertSame('software_development', $plan->fresh()->workspace_specialization_override);
    }

    private function plan(User $owner, string $title, string $category, bool $team = false): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(40),
            'is_public' => false,
            'is_collaborative' => $team,
        ]);
    }
}
