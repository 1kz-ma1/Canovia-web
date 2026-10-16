<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IosSoftLaunchWebReadinessV561Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config([
            'session.driver' => 'array',
            'canovia.support_email' => 'support@canovia.example',
            'canovia.operator_name' => 'Canovia',
        ]);
    }

    public function test_privacy_and_support_surfaces_are_public(): void
    {
        $this->get(route('legal.privacy'))
            ->assertOk()
            ->assertSee('プライバシーポリシー')
            ->assertSee('アカウント削除')
            ->assertSee('data-onboarding-auto="0"', false);

        $this->get(route('legal.support'))
            ->assertOk()
            ->assertSee('Canoviaサポート')
            ->assertSee('data-onboarding-auto="0"', false)
            ->assertSee('support@canovia.example')
            ->assertSee(route('legal.privacy'), false);
    }

    public function test_account_page_exposes_double_password_review_entry_not_direct_delete(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('auth.account'))
            ->assertOk()
            ->assertSee(route('legal.privacy'), false)
            ->assertSee(route('legal.support'), false)
            ->assertSee(route('auth.account.deletion.prepare'), false)
            ->assertSee('パスワードを再入力')
            ->assertSee('削除内容を確認する')
            ->assertDontSee('name="confirm_deletion"', false);
    }

    public function test_wrong_password_cannot_start_review(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('auth.account.deletion.prepare'), [
                'password' => 'not-the-password',
                'password_confirmation' => 'not-the-password',
            ])->assertSessionHasErrors('password');

        $this->assertNull(session('account_deletion_review'));
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_mismatched_password_confirmation_cannot_start_review(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('auth.account.deletion.prepare'), [
                'password' => 'password',
                'password_confirmation' => 'different',
            ])->assertSessionHasErrors('password');

        $this->assertNull(session('account_deletion_review'));
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_direct_delete_without_review_is_denied_even_with_correct_passwords(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete(route('auth.account.destroy'), [
            'password' => 'password',
            'password_confirmation' => 'password',
            'confirm_deletion' => 'yes',
        ])->assertSessionHasErrors('confirm_deletion');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_direct_review_page_without_password_check_is_not_accessible(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('auth.account.deletion.review'))
            ->assertRedirect(route('auth.account'));

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_password_verification_does_not_delete_and_review_is_required_before_final_post(): void
    {
        $user = User::factory()->create();
        $this->startReview($user);

        $state = session('account_deletion_review');
        $this->assertArrayNotHasKey('password', $state);
        $this->assertNull($state['reviewed_at']);
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        // Password was checked, but a direct DELETE without viewing review fails.
        $this->delete(route('auth.account.destroy'), ['confirm_deletion' => 'yes'])
            ->assertSessionHasErrors('confirm_deletion');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_review_page_shows_data_and_final_confirmation_required(): void
    {
        $user = User::factory()->create();
        $this->startReview($user);

        $this->get(route('auth.account.deletion.review'))
            ->assertOk()
            ->assertSee('削除される本人所有データ')
            ->assertSee('他のユーザーが所有する共同Planそのものは削除されません')
            ->assertSee(route('auth.account.destroy'), false)
            ->assertSee(route('auth.account.deletion.cancel'), false)
            ->assertSee('アカウントと本人所有データを完全に削除する');

        $this->delete(route('auth.account.destroy'), [])
            ->assertSessionHasErrors('confirm_deletion');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_cancel_revokes_pending_confirmation(): void
    {
        $user = User::factory()->create();
        $this->startReview($user);
        $this->get(route('auth.account.deletion.review'))->assertOk();

        $this->post(route('auth.account.deletion.cancel'))
            ->assertRedirect(route('auth.account'));

        $this->assertNull(session('account_deletion_review'));
        $this->delete(route('auth.account.destroy'), ['confirm_deletion' => 'yes'])
            ->assertSessionHasErrors('confirm_deletion');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_expired_review_cannot_delete_account(): void
    {
        $user = User::factory()->create();
        $this->startReview($user);
        $this->get(route('auth.account.deletion.review'))->assertOk();
        $this->travel(6)->minutes();

        $this->delete(route('auth.account.destroy'), ['confirm_deletion' => 'yes'])
            ->assertSessionHasErrors('confirm_deletion');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_password_change_invalidates_existing_review(): void
    {
        $user = User::factory()->create();
        $this->startReview($user);
        $this->get(route('auth.account.deletion.review'))->assertOk();
        $user->update(['password' => 'changed-password']);

        $this->delete(route('auth.account.destroy'), ['confirm_deletion' => 'yes'])
            ->assertSessionHasErrors('confirm_deletion');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_new_failed_password_attempt_revokes_old_valid_review(): void
    {
        $user = User::factory()->create();
        $this->startReview($user);
        $this->get(route('auth.account.deletion.review'))->assertOk();

        $this->post(route('auth.account.deletion.prepare'), [
            'password' => 'wrong',
            'password_confirmation' => 'wrong',
        ])->assertSessionHasErrors('password');

        $this->delete(route('auth.account.destroy'), ['confirm_deletion' => 'yes'])
            ->assertSessionHasErrors('confirm_deletion');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_other_authenticated_user_cannot_use_first_users_review(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->startReview($user);
        $this->get(route('auth.account.deletion.review'))->assertOk();

        $this->actingAs($other)->delete(route('auth.account.destroy'), [
            'confirm_deletion' => 'yes',
        ])->assertSessionHasErrors('confirm_deletion');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
    }

    public function test_account_deletion_removes_owned_plan_standalone_data_and_private_file_without_deleting_another_users_plan(): void
    {
        Storage::fake('local');

        $user = User::factory()->create(['email' => 'delete-me@example.com']);
        $other = User::factory()->create();
        $ownedPlan = $this->plan($user, 'Owned Plan');
        $otherPlan = $this->plan($other, 'Other Plan');
        $path = 'inbox/'.$user->id.'/private-note.pdf';
        Storage::disk('local')->put($path, 'private data');

        $inbox = InboxItem::query()->create([
            'user_id' => $user->id,
            'actor_token' => null,
            'plan_id' => null,
            'source_type' => 'pdf',
            'status' => 'new',
            'title' => 'Private note',
            'content' => null,
            'source_url' => null,
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'original_name' => 'private-note.pdf',
            'byte_size' => 12,
            'metadata' => null,
        ]);

        $this->startReview($user);
        $this->get(route('auth.account.deletion.review'))->assertOk();
        $this->delete(route('auth.account.destroy'), [
            'confirm_deletion' => 'yes',
        ])->assertRedirect(route('home'))
            ->assertSessionHas('status', 'Canoviaアカウントと本人所有データを削除しました。')
            ->assertSessionHas('account_deleted', true);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('plans', ['id' => $ownedPlan->id]);
        $this->assertDatabaseHas('plans', ['id' => $otherPlan->id]);
        $this->assertDatabaseMissing('inbox_items', ['id' => $inbox->id]);
        // Physical unlink/commit contract is covered by the PostgreSQL G3 suite.
    }

    private function startReview(User $user): void
    {
        $this->actingAs($user)->post(route('auth.account.deletion.prepare'), [
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('auth.account.deletion.review'));
    }

    private function plan(User $user, string $title): Plan
    {
        return Plan::query()->create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => $title,
            'description' => 'Soft launch account deletion test',
            'category' => 'その他',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
            'is_collaborative' => false,
        ]);
    }
}
