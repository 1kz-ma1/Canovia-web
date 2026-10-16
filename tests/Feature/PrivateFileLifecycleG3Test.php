<?php

namespace Tests\Feature;

use App\Models\CareerCapture;
use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\StudyRecallSource;
use App\Models\Task;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\PrivateFileLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class PrivateFileLifecycleG3Test extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake(config('filesystems.default'));
        config(['canovia.super_admin_user_id' => null, 'canovia.admin_email' => null]);
    }

    public function test_plan_deletion_cleans_cascaded_recall_and_career_but_preserves_independent_inbox_file(): void
    {
        $owner = User::factory()->create();
        [$plan, $task] = $this->planWithTask($owner);
        $inboxPath = 'inbox/'.$owner->id.'/stays.png';
        $recallPath = 'study-recall-sources/'.$plan->id.'/'.$task->id.'/gone.pdf';
        $careerPath = 'career-captures/'.$plan->id.'/gone.png';
        foreach ([$inboxPath, $recallPath, $careerPath] as $path) {
            Storage::put($path, 'private material');
        }

        $inbox = $this->inbox($owner, $plan, $inboxPath);
        $this->recall($owner, $plan, $task, $recallPath);
        $this->career($owner, $plan, $careerPath);

        $this->actingAs($owner)
            ->delete(route('plans.destroy', $plan))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
        $this->assertDatabaseCount('study_recall_sources', 0);
        $this->assertDatabaseCount('career_captures', 0);
        $this->assertDatabaseHas('inbox_items', [
            'id' => $inbox->id, 'plan_id' => null,
        ]);
        Storage::assertMissing($recallPath);
        Storage::assertMissing($careerPath);
        Storage::assertExists($inboxPath);
    }

    public function test_plan_deletion_does_not_unlink_recall_path_still_referenced_by_another_inbox(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        [$plan, $task] = $this->planWithTask($owner);
        $path = 'study-recall-sources/'.$plan->id.'/'.$task->id.'/shared.png';
        Storage::put($path, 'shared historical file');
        $this->recall($owner, $plan, $task, $path);
        $shared = $this->inbox($other, null, $path);

        $this->actingAs($owner)
            ->delete(route('plans.destroy', $plan))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('study_recall_sources', 0);
        $this->assertDatabaseHas('inbox_items', ['id' => $shared->id]);
        Storage::assertExists($path);
    }

    public function test_account_deletion_never_deletes_file_still_referenced_by_another_account(): void
    {
        $owner = User::factory()->create();
        $survivor = User::factory()->create();
        [$plan, $task] = $this->planWithTask($owner);
        $sharedPath = 'study-recall-sources/'.$plan->id.'/'.$task->id.'/shared.pdf';
        $privatePath = 'inbox/'.$owner->id.'/delete.pdf';
        Storage::put($sharedPath, 'shared file');
        Storage::put($privatePath, 'private file');
        $this->recall($owner, $plan, $task, $sharedPath);
        $this->inbox($survivor, null, $sharedPath);
        $this->inbox($owner, $plan, $privatePath);

        app(AccountDeletionService::class)->delete($owner);

        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('users', ['id' => $survivor->id]);
        Storage::assertExists($sharedPath);
        Storage::assertMissing($privatePath);
    }

    public function test_http_account_deletion_unlinks_owned_inbox_after_commit_without_touching_other_users_data(): void
    {
        $owner = User::factory()->create(['email' => 'erase-private@example.com']);
        $other = User::factory()->create();
        [$plan, $task] = $this->planWithTask($owner);
        [$otherPlan] = $this->planWithTask($other);

        $path = 'inbox/'.$owner->id.'/personal.pdf';
        $otherPath = 'inbox/'.$other->id.'/other.pdf';
        Storage::put($path, 'private bytes');
        Storage::put($otherPath, 'other bytes');
        $this->inbox($owner, $plan, $path);
        $otherInbox = $this->inbox($other, $otherPlan, $otherPath);

        $this->actingAs($owner)->post(route('auth.account.deletion.prepare'), [
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('auth.account.deletion.review'));

        $this->get(route('auth.account.deletion.review'))->assertOk();
        $this->delete(route('auth.account.destroy'), ['confirm_deletion' => 'yes'])
            ->assertRedirect(route('home'))
            ->assertSessionHasNoErrors();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('plans', ['id' => $otherPlan->id]);
        $this->assertDatabaseHas('inbox_items', ['id' => $otherInbox->id]);
        Storage::assertMissing($path);
        Storage::assertExists($otherPath);
    }

    public function test_nested_outer_rollback_preserves_db_rows_and_file_bytes(): void
    {
        $owner = User::factory()->create();
        [$plan, $task] = $this->planWithTask($owner);
        $path = 'study-recall-sources/'.$plan->id.'/'.$task->id.'/rollback.pdf';
        Storage::put($path, 'must survive');
        $source = $this->recall($owner, $plan, $task, $path);

        try {
            DB::transaction(function () use ($owner, $plan, $path): void {
                $this->actingAs($owner)
                    ->delete(route('plans.destroy', $plan))
                    ->assertRedirect();
                // Delete callbacks must not run before the outermost commit.
                Storage::assertExists($path);
                throw new RuntimeException('roll back enclosing unit of work');
            });
            $this->fail('Expected deliberate rollback');
        } catch (RuntimeException $e) {
            $this->assertSame('roll back enclosing unit of work', $e->getMessage());
        }

        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('study_recall_sources', ['id' => $source->id]);
        Storage::assertExists($path);
    }

    public function test_unavailable_storage_does_not_roll_back_plan_deletion_or_expose_private_path_in_log(): void
    {
        $owner = User::factory()->create();
        [$plan, $task] = $this->planWithTask($owner);
        $path = 'study-recall-sources/'.$plan->id.'/'.$task->id.'/failure.pdf';
        $this->recall($owner, $plan, $task, $path);

        Storage::shouldReceive('disk')->once()
            ->with((string) config('filesystems.default'))
            ->andThrow(new RuntimeException('disk temporarily unavailable'));
        Log::shouldReceive('warning')->once()->with(
            'Private file cleanup failed after DB commit',
            ['reason' => 'storage_unavailable'],
        );

        $this->actingAs($owner)
            ->delete(route('plans.destroy', $plan))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
        $this->assertDatabaseCount('study_recall_sources', 0);
        // Without an authentic byte hash the new durable outbox must refuse
        // destructive automatic retry, retaining a quarantine record.
        $this->assertDatabaseHas('private_file_cleanup_entries', [
            'status' => 'quarantined', 'last_reason' => 'source_unavailable',
        ]);
    }

    public function test_career_capture_deletion_defers_file_unlink_until_outer_commit(): void
    {
        $owner = User::factory()->create();
        [$plan] = $this->planWithTask($owner);
        $plan->update(['category' => '就活・キャリア']);
        $path = 'career-captures/'.$plan->id.'/old-screenshot.png';
        Storage::put($path, 'screenshot bytes');
        $capture = $this->career($owner, $plan, $path);

        DB::transaction(function () use ($owner, $plan, $capture, $path): void {
            $this->actingAs($owner)
                ->delete(route('plans.career.captures.destroy', [$plan, $capture]))
                ->assertRedirect()->assertSessionHasNoErrors();

            $this->assertDatabaseMissing('career_captures', ['id' => $capture->id]);
            Storage::assertExists($path);
        });

        Storage::assertMissing($path);
    }

    public function test_rolled_back_career_capture_deletion_preserves_file_and_record(): void
    {
        $owner = User::factory()->create();
        [$plan] = $this->planWithTask($owner);
        $plan->update(['category' => '就活・キャリア']);
        $path = 'career-captures/'.$plan->id.'/rollback.png';
        Storage::put($path, 'must survive');
        $capture = $this->career($owner, $plan, $path);

        try {
            DB::transaction(function () use ($owner, $plan, $capture, $path): void {
                $this->actingAs($owner)
                    ->delete(route('plans.career.captures.destroy', [$plan, $capture]))
                    ->assertRedirect()->assertSessionHasNoErrors();
                Storage::assertExists($path);
                throw new RuntimeException('rollback Career Capture deletion');
            });
            $this->fail('Expected enclosing rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback Career Capture deletion', $exception->getMessage());
        }

        $this->assertDatabaseHas('career_captures', ['id' => $capture->id]);
        Storage::assertExists($path);
    }

    public function test_career_capture_deletion_preserves_path_referenced_by_another_inbox(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        [$plan] = $this->planWithTask($owner);
        $plan->update(['category' => '就活・キャリア']);
        $path = 'career-captures/'.$plan->id.'/referenced.png';
        Storage::put($path, 'reference must survive');
        $capture = $this->career($owner, $plan, $path);
        $inbox = $this->inbox($other, null, $path);

        $this->actingAs($owner)
            ->delete(route('plans.career.captures.destroy', [$plan, $capture]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('career_captures', ['id' => $capture->id]);
        $this->assertDatabaseHas('inbox_items', ['id' => $inbox->id]);
        Storage::assertExists($path);
    }

    private function planWithTask(User $owner): array
    {
        $plan = Plan::query()->create([
            'user_id' => $owner->id, 'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'Private files lifecycle', 'category' => '資格学習',
            'start_date' => today(), 'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);
        $task = Task::query()->create([
            'plan_id' => $plan->id, 'title' => 'Review source',
            'status' => 'todo', 'estimated_minutes' => 30,
            'remaining_minutes' => 30,
        ]);
        return [$plan, $task];
    }

    private function inbox(User $owner, ?Plan $plan, string $path): InboxItem
    {
        return InboxItem::query()->create([
            'user_id' => $owner->id, 'plan_id' => $plan?->id,
            'source_type' => 'image', 'status' => 'new',
            'storage_path' => $path, 'mime_type' => 'image/png',
            'original_name' => basename($path),
        ]);
    }

    private function recall(User $owner, Plan $plan, Task $task, string $path): StudyRecallSource
    {
        return StudyRecallSource::query()->create([
            'plan_id' => $plan->id, 'task_id' => $task->id,
            'user_id' => $owner->id, 'source_type' => 'pdf',
            'original_name' => basename($path), 'mime_type' => 'application/pdf',
            'storage_path' => $path, 'status' => 'ready',
        ]);
    }

    private function career(User $owner, Plan $plan, string $path): CareerCapture
    {
        return CareerCapture::query()->create([
            'plan_id' => $plan->id, 'user_id' => $owner->id,
            'source_type' => 'screenshot', 'status' => 'pending',
            'screenshot_path' => $path, 'captured_at' => now(),
        ]);
    }
}
