<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OverviewRelevanceV5865Test extends TestCase
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

    public function test_no_plan_shows_onboarding_instead_of_empty_mode_and_global_cards(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk()
            ->assertSee('data-overview-first-use-workspaces', false)
            ->assertDontSee('data-overview-primary-action', false)
            ->assertDontSee('data-overview-mode-summaries', false)
            ->assertDontSee('data-overview-inbox', false)
            ->assertDontSee('data-overview-important-changes', false)
            ->assertDontSee('href="#overview-inbox"', false)
            ->assertDontSee('href="#overview-changes"', false)
            ->assertDontSee('まだ優先Actionはありません。');
    }

    public function test_single_study_plan_shows_only_related_mode_and_no_empty_inbox_or_changes(): void
    {
        $owner = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);
        $study = $this->plan($owner, '応用情報技術者試験 合格', '資格学習');
        $private = $this->plan($other, '他人の開発Plan', '個人開発');

        $response = $this->actingAs($owner)
            ->get(route('workspace.overview.index'));

        $response
            ->assertOk()
            ->assertSee('data-overview-mode="study"', false)
            ->assertSee($study->title)
            ->assertDontSee('data-overview-mode="development"', false)
            ->assertDontSee('data-overview-mode="career"', false)
            ->assertDontSee('data-overview-inbox', false)
            ->assertDontSee('data-overview-important-changes', false)
            ->assertDontSee($private->title)
            ->assertDontSee('CareerPlanはまだありません。')
            ->assertDontSee('Release判断に使えるDevelopment Evidenceがまだありません。')
            ->assertDontSee('data-overview-official-study-action', false)
            ->assertDontSee('BIGGEST GAP')
            ->assertSee('理解度の確認待ち');
    }

    public function test_two_active_modes_are_shown_without_a_fake_release_readiness(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $study = $this->plan($user, '応用情報技術者試験 合格', '資格学習');
        $development = $this->plan($user, 'Canovia開発', '個人開発');

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk()
            ->assertSee('data-overview-mode="study"', false)
            ->assertSee('data-overview-mode="development"', false)
            ->assertDontSee('data-overview-mode="career"', false)
            ->assertSee($study->title)
            ->assertSee($development->title)
            ->assertSee('開発の作業状況を確認中')
            ->assertDontSee('Release判断に使えるDevelopment Evidenceがまだありません。')
            ->assertDontSee('セットアップ中');
    }

    public function test_nonempty_inbox_remains_visible_but_other_user_items_do_not_leak(): void
    {
        $user = User::factory()->create(['first_run_completed_at' => now()]);
        $other = User::factory()->create(['first_run_completed_at' => now()]);

        InboxItem::query()->create([
            'user_id' => $user->id,
            'status' => 'new',
            'source_type' => 'text',
            'title' => '自分のメモ',
            'content' => '後で整理する',
        ]);
        InboxItem::query()->create([
            'user_id' => $other->id,
            'status' => 'new',
            'source_type' => 'text',
            'title' => '他人の非公開メモ',
            'content' => '表示しない',
        ]);

        $this->actingAs($user)
            ->get(route('workspace.overview.index'))
            ->assertOk()
            ->assertSee('data-overview-inbox', false)
            ->assertSee('href="#overview-inbox"', false)
            ->assertSee('自分のメモ')
            ->assertDontSee('他人の非公開メモ')
            ->assertDontSee('data-overview-important-changes', false);
    }

    private function plan(User $owner, string $title, string $category): Plan
    {
        return Plan::query()->create([
            'user_id' => $owner->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => $title,
            'category' => $category,
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addDays(45),
            'is_public' => false,
        ]);
    }
}
