<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DevelopmentCreativePlanRescueV5863Test extends TestCase
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

    public function test_legacy_hinanex_collaborative_creative_plan_is_discoverable_and_opt_in_is_reversible(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $hinanex = $this->plan($owner, 'HINANEX', '制作活動', true);
        $art = $this->plan($owner, '卒業制作アート', '制作活動', false);
        $normal = $this->plan($owner, 'Canovia', '個人開発');

        // Do not silently reclassify Creative Plans or make them accessible
        // from the explicit Development plan_id route before user selection.
        $this->actingAs($owner)
            ->get(route('workspace.development.top'))
            ->assertOk()
            ->assertSee('data-development-creative-rescue', false)
            ->assertSee('data-development-creative-candidate="'.$hinanex->id.'"', false)
            ->assertSee('data-development-creative-candidate="'.$art->id.'"', false);

        $this->actingAs($owner)
            ->get(route('workspace.development.index', ['plan_id' => $hinanex->id]))
            ->assertNotFound();

        $this->actingAs($owner)
            ->post(route('workspace.development.creative.store', $hinanex))
            ->assertRedirect(
                route('workspace.development.index', ['plan_id' => $hinanex->id]),
            );

        $this->actingAs($owner)
            ->get(route('workspace.development.index', ['plan_id' => $hinanex->id]))
            ->assertOk()
            ->assertSee('data-development-creative-active="'.$hinanex->id.'"', false)
            ->assertSee('data-development-creative-team-link', false)
            ->assertSee('data-development-home-next-action', false)
            ->assertDontSee('data-development-creative-candidate="'.$hinanex->id.'"', false)
            ->assertSee('data-development-creative-candidate="'.$art->id.'"', false);

        $this->actingAs($owner)
            ->get(route('workspace.development.index', [
                'plan_id' => $hinanex->id,
                'surface' => 'team',
            ]))
            ->assertOk()
            ->assertSee('data-development-surface="team"', false)
            ->assertSee('data-development-creative-active="'.$hinanex->id.'"', false);

        $this->actingAs($owner)
            ->get(route('workspace.development.top'))
            ->assertOk()
            ->assertSee($hinanex->title)
            ->assertSee('data-development-creative-candidate="'.$art->id.'"', false)
            ->assertDontSee('data-development-creative-candidate="'.$hinanex->id.'"', false);

        $this->assertSame('制作活動', $hinanex->fresh()->category);
        $this->assertTrue($hinanex->fresh()->is_collaborative);
        $this->assertSame('制作活動', $art->fresh()->category);
        $this->assertSame('個人開発', $normal->fresh()->category);

        $this->actingAs($owner)
            ->delete(route('workspace.development.creative.destroy', $hinanex))
            ->assertRedirect(route('workspace.development.top'));

        $this->actingAs($owner)
            ->get(route('workspace.development.index', ['plan_id' => $hinanex->id]))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('workspace.development.top'))
            ->assertOk()
            ->assertSee('data-development-creative-candidate="'.$hinanex->id.'"', false);
    }

    public function test_normal_development_plan_and_unrelated_study_are_not_reclassified(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $development = $this->plan($owner, 'Canovia', '個人開発');
        $study = $this->plan($owner, 'APの勉強', '資格学習');

        $this->actingAs($owner)
            ->get(route('workspace.development.index', ['plan_id' => $development->id]))
            ->assertOk()
            ->assertDontSee('data-development-creative-active=', false)
            ->assertDontSee('data-development-creative-rescue', false);

        $this->actingAs($owner)
            ->post(route('workspace.development.creative.store', $study))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('workspace.development.index', ['plan_id' => $study->id]))
            ->assertNotFound();

        $this->assertSame('資格学習', $study->fresh()->category);
    }

    public function test_other_persons_private_plan_never_appears_and_cannot_be_opted_in(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $private = $this->plan($owner, '非公開HINANEX', '制作活動', true);

        $this->actingAs($other)
            ->get(route('workspace.development.top'))
            ->assertOk()
            ->assertDontSee('data-development-creative-candidate="'.$private->id.'"', false)
            ->assertDontSee($private->title);

        $this->actingAs($other)
            ->post(route('workspace.development.creative.store', $private))
            ->assertForbidden();

        $this->actingAs($other)
            ->get(route('workspace.development.index', ['plan_id' => $private->id]))
            ->assertNotFound();

        $this->assertSame('制作活動', $private->fresh()->category);
    }

    public function test_shared_viewer_may_open_team_surface_without_becoming_editor_or_owner(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $viewer = User::factory()->create(['first_run_completed_at' => now()]);
        $hinanex = $this->plan($owner, '共同HINANEX', '制作活動', true);

        PlanMember::query()->create([
            'plan_id' => $hinanex->id,
            'user_id' => $viewer->id,
            'role' => PlanMember::ROLE_VIEWER,
            'invited_by_user_id' => $owner->id,
            'joined_at' => now(),
        ]);

        $this->actingAs($viewer)
            ->get(route('workspace.development.top'))
            ->assertOk()
            ->assertSee('data-development-creative-candidate="'.$hinanex->id.'"', false);

        $this->actingAs($viewer)
            ->post(route('workspace.development.creative.store', $hinanex))
            ->assertRedirect();

        $this->actingAs($viewer)
            ->get(route('workspace.development.index', [
                'plan_id' => $hinanex->id,
                'surface' => 'team',
            ]))
            ->assertOk()
            ->assertSee('data-development-creative-active="'.$hinanex->id.'"', false)
            ->assertSee('data-development-surface="team"', false);

        $this->assertSame(PlanMember::ROLE_VIEWER, $hinanex->memberships()->first()->role);
        $this->assertSame($owner->id, $hinanex->fresh()->user_id);
        $this->assertSame('制作活動', $hinanex->fresh()->category);
        $this->assertDatabaseCount('intelligence_action_projections', 0);
    }

    private function plan(
        User $owner,
        string $title,
        string $category,
        bool $collaborative = false,
    ): Plan {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => 2,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addWeeks(5),
            'is_public' => false,
            'is_collaborative' => $collaborative,
        ]);
    }
}
