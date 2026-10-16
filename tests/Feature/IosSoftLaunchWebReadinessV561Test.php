<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Notification;
use App\Notifications\AccountDeletionCodeNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class IosSoftLaunchWebReadinessV561Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();

        config([
            'session.driver' => 'array',
            'mail.default' => 'array',
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

    public function test_account_page_exposes_privacy_support_and_deletion(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('auth.account'))
            ->assertOk()
            ->assertSee(route('legal.privacy'), false)
            ->assertSee(route('legal.support'), false)
            ->assertSee(route('auth.account.deletion.code'), false)
            ->assertSee('削除確認コードをメールで受け取る')
            ->assertDontSee('name="confirmation_code"', false);
    }

    public function test_wrong_password_cannot_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('auth.account.deletion.code'), [
                'password' => 'not-the-password',
                'confirmation_email' => $user->email,
            ])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_confirmation_email_cannot_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('auth.account.deletion.code'), [
                'password' => 'password',
                'confirmation_email' => 'other@example.com',
            ])
            ->assertSessionHasErrors('confirmation_email');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_account_deletion_removes_owned_plan_standalone_data_and_private_file_without_deleting_another_users_plan(): void
    {
        Storage::fake('local');

        $user = User::factory()->create([
            'email' => 'delete-me@example.com',
        ]);
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

        $this->actingAs($user)
            ->post(route('auth.account.deletion.code'), [
                'password' => 'password',
                'confirmation_email' => ' DELETE-ME@example.com ',
            ])
            ->assertRedirect(route('auth.account'));
        $code = Notification::sent($user, AccountDeletionCodeNotification::class)->first()->code;

        $this->delete(route('auth.account.destroy'), ['confirmation_code' => $code])
            ->assertRedirect(route('home'))
            ->assertSessionHas(
                'status',
                'Canoviaアカウントと本人所有データを削除しました。',
            );

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('plans', ['id' => $ownedPlan->id]);
        $this->assertDatabaseHas('plans', ['id' => $otherPlan->id]);
        $this->assertDatabaseMissing('inbox_items', ['id' => $inbox->id]);
        // RefreshDatabase wraps the request in an outer never-committed test
        // transaction. DB::afterCommit correctly defers physical cleanup.
        // The actual post-commit unlink is exercised by the isolated PG16
        // PrivateFileLifecycleG3Test (without a wrapping transaction).
        Storage::disk('local')->assertExists($path);
    }

    public function test_direct_delete_without_email_challenge_is_denied(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->delete(route('auth.account.destroy'), [
                'password' => 'password',
                'confirmation_email' => $user->email,
                'confirmation_code' => '000000',
            ])
            ->assertSessionHasErrors('confirmation_code');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        Notification::assertNothingSent();
    }

    public function test_log_mailer_fails_closed_without_sending_or_deleting(): void
    {
        $user = User::factory()->create();
        config(['mail.default' => 'log']);

        $this->actingAs($user)
            ->post(route('auth.account.deletion.code'), [
                'password' => 'password',
                'confirmation_email' => $user->email,
            ])
            ->assertSessionHasErrors('confirmation_email');

        $this->assertNull(session('account_deletion_challenge'));
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        Notification::assertNothingSent();
    }

    public function test_wrong_code_is_rejected_and_max_five_attempts_invalidate_challenge(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('auth.account.deletion.code'), [
            'password' => 'password',
            'confirmation_email' => $user->email,
        ])->assertRedirect(route('auth.account'));

        $issued = Notification::sent($user, AccountDeletionCodeNotification::class)->first()->code;
        $incorrect = $issued === '000000' ? '999999' : '000000';
        $challenge = session('account_deletion_challenge');
        $this->assertArrayNotHasKey('code', $challenge);
        $this->assertArrayHasKey('code_hash', $challenge);

        for ($i = 0; $i < 5; $i++) {
            $this->delete(route('auth.account.destroy'), ['confirmation_code' => $incorrect])
                ->assertSessionHasErrors('confirmation_code');
        }
        $this->assertNull(session('account_deletion_challenge'));
        $this->delete(route('auth.account.destroy'), ['confirmation_code' => $issued])
            ->assertSessionHasErrors('confirmation_code');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_expired_code_cannot_delete_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('auth.account.deletion.code'), [
            'password' => 'password',
            'confirmation_email' => $user->email,
        ])->assertRedirect(route('auth.account'));
        $code = Notification::sent($user, AccountDeletionCodeNotification::class)->first()->code;

        $this->travel(11)->minutes();
        $this->delete(route('auth.account.destroy'), ['confirmation_code' => $code])
            ->assertSessionHasErrors('confirmation_code');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_challenge_is_bound_to_user_email_and_password(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('auth.account.deletion.code'), [
            'password' => 'password',
            'confirmation_email' => $user->email,
        ])->assertRedirect(route('auth.account'));
        $code = Notification::sent($user, AccountDeletionCodeNotification::class)->first()->code;

        $user->update(['email' => 'new-email@example.com']);
        $this->delete(route('auth.account.destroy'), ['confirmation_code' => $code])
            ->assertSessionHasErrors('confirmation_code');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_another_signed_in_user_cannot_reuse_first_users_code(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->post(route('auth.account.deletion.code'), [
            'password' => 'password',
            'confirmation_email' => $user->email,
        ])->assertRedirect(route('auth.account'));
        $code = Notification::sent($user, AccountDeletionCodeNotification::class)->first()->code;

        $this->actingAs($other)
            ->delete(route('auth.account.destroy'), ['confirmation_code' => $code])
            ->assertSessionHasErrors('confirmation_code');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
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
