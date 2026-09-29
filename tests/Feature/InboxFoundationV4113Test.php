<?php

namespace Tests\Feature;

use App\Models\CareerCapture;
use App\Models\Plan;
use App\Models\StudyRecallCandidate;
use App\Models\StudyRecallSource;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class InboxFoundationV4113Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['filesystems.default' => 'local']);
        Storage::fake('local');
    }

    public function test_user_can_capture_text_without_choosing_a_destination(): void
    {
        [$user] = $this->scenario();

        $this->actingAs($user)
            ->post(route('inbox.store'), [
                'content' => 'あとで検討したい新機能のアイデア',
            ])
            ->assertRedirect(route('inbox.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inbox_items', [
            'user_id' => $user->id,
            'plan_id' => null,
            'source_type' => 'text',
            'status' => 'new',
            'content' => 'あとで検討したい新機能のアイデア',
        ]);

        $this->actingAs($user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertSee('何をしたいですか？')
            ->assertSee('整理しなくて大丈夫です。思いついたまま話してください。')
            ->assertSee('name="intake_mode" value="chat"', false)
            ->assertSee('あとで検討したい新機能のアイデア')
            ->assertSee('このまま相談する');
    }

    public function test_chat_first_capture_extracts_a_pasted_url_without_a_separate_url_field(): void
    {
        [$user] = $this->scenario();

        $this->actingAs($user)
            ->post(route('inbox.store'), [
                'intake_mode' => 'chat',
                'content' => 'この仕様をあとで確認したい https://example.com/spec',
            ])
            ->assertRedirect(route('inbox.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inbox_focus_id');

        $this->assertDatabaseHas('inbox_items', [
            'user_id' => $user->id,
            'source_type' => 'url',
            'source_url' => 'https://example.com/spec',
            'content' => 'この仕様をあとで確認したい https://example.com/spec',
        ]);

        $item = \App\Models\InboxItem::firstOrFail();
        $this->assertSame('chat', data_get($item->metadata, 'intake_mode'));
        $this->assertSame('inbox', data_get($item->metadata, 'capture_surface'));

        $this->actingAs($user)
            ->withSession(['inbox_focus_id' => $item->id])
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertSee('CONVERSATION')
            ->assertSee('受け取りました。今はInboxに置いてあります。')
            ->assertSee('保存内容を見る');
    }

    public function test_chat_first_capture_keeps_overlong_url_like_text_instead_of_promoting_it_to_source_url(): void
    {
        [$user] = $this->scenario();
        $overlongUrl = 'https://example.com/'.str_repeat('a', 2050);

        $this->actingAs($user)
            ->post(route('inbox.store'), [
                'intake_mode' => 'chat',
                'content' => '長すぎるURLは本文として保持する '.$overlongUrl,
            ])
            ->assertRedirect(route('inbox.index'))
            ->assertSessionHasNoErrors();

        $item = \App\Models\InboxItem::firstOrFail();
        $this->assertSame('text', $item->source_type);
        $this->assertNull($item->source_url);
        $this->assertStringContainsString($overlongUrl, (string) $item->content);
    }

    public function test_image_or_pdf_is_stored_privately_and_other_user_cannot_open_it(): void
    {
        [$owner] = $this->scenario();

        $this->actingAs($owner)
            ->post(route('inbox.store'), [
                'source_file' => $this->pdfUpload('reference.pdf'),
                'content' => 'あとで整理する参考資料',
            ])
            ->assertRedirect(route('inbox.index'))
            ->assertSessionHasNoErrors();

        $item = \App\Models\InboxItem::firstOrFail();
        $this->assertSame('pdf', $item->source_type);
        $this->assertNotNull($item->storage_path);
        Storage::disk('local')->assertExists($item->storage_path);

        $other = User::factory()->create();

        $this->actingAs($other)
            ->get(route('inbox.file', $item))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('inbox.file', $item))
            ->assertOk();
    }

    public function test_inbox_aggregates_existing_pending_states_without_copying_them(): void
    {
        [$user, $plan, $task] = $this->scenario();
        $actorToken = str_repeat('a', 64);

        $source = StudyRecallSource::create([
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'user_id' => $user->id,
            'source_type' => 'text',
            'source_text' => 'maintain = 維持する',
            'status' => 'ready',
            'candidate_count' => 1,
        ]);

        StudyRecallCandidate::create([
            'study_recall_source_id' => $source->id,
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'prompt' => 'maintain',
            'answer' => '維持する',
            'tags' => ['TOEIC'],
            'source_excerpt' => 'maintain = 維持する',
            'confidence' => 93,
            'status' => 'pending',
            'fingerprint' => hash('sha256', 'maintain|維持する'),
        ]);

        CareerCapture::create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'source_type' => 'url',
            'status' => 'pending',
            'source_url' => 'https://example.com/jobs/1',
            'captured_at' => now(),
        ]);

        WorkSession::create([
            'actor_token' => $actorToken,
            'browser_session_id' => 'inbox-foundation-test',
            'plan_id' => $plan->id,
            'task_id' => $task->id,
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'ended_at' => now()->subMinutes(30),
            'actual_seconds' => 1800,
            'paused_seconds' => 0,
            'source' => 'dashboard',
            'needs_plan_update' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['pace_keeper.actor_token' => $actorToken])
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertSee('Recall Candidate')
            ->assertSee('maintain')
            ->assertSee('Career Capture')
            ->assertSee('Planへ未反映')
            ->assertSee($task->title);

        $this->assertDatabaseCount('inbox_items', 0);
        $this->assertDatabaseCount('study_recall_candidates', 1);
        $this->assertDatabaseCount('career_captures', 1);
        $this->assertDatabaseCount('work_sessions', 1);
    }

    public function test_item_can_be_marked_processed_and_leaves_unsorted_queue(): void
    {
        [$user] = $this->scenario();

        $this->actingAs($user)->post(route('inbox.store'), [
            'content' => '整理するメモ',
        ]);

        $item = \App\Models\InboxItem::firstOrFail();

        $this->actingAs($user)
            ->patch(route('inbox.status', $item), ['status' => 'processed'])
            ->assertRedirect(route('inbox.index'));

        $this->assertSame('processed', $item->fresh()->status);
        $this->assertNotNull($item->fresh()->processed_at);

        $this->actingAs($user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertSee('未整理 0件')
            ->assertSee('最近整理したInbox Item');
    }

    public function test_main_navigation_uses_inbox_while_legacy_today_route_remains_available(): void
    {
        [$user] = $this->scenario();

        $mobile = file_get_contents(resource_path('views/layouts/partials/mobile-nav.blade.php'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $offline = file_get_contents(public_path('offline.html'));

        $this->assertStringContainsString("route('inbox.index')", $mobile);
        $this->assertStringContainsString('<span>Inbox</span>', $mobile);
        $this->assertStringNotContainsString("route('navigation.index')", $mobile);
        $this->assertStringContainsString("route('inbox.index')", $layout);
        $this->assertStringContainsString('<span>Inbox</span>', $layout);
        $this->assertStringContainsString('data-shell-view="inbox">Inbox</button>', $offline);
        $this->assertStringContainsString("if(view==='inbox')desiredPath='/inbox'", $offline);
        $this->assertStringNotContainsString('data-shell-view="today">今日</button>', $offline);
        $this->assertStringNotContainsString('data-shell-panel="today"', $offline);

        $this->actingAs($user)
            ->get(route('navigation.index'))
            ->assertOk();
    }

    public function test_old_onboarding_copy_no_longer_tells_users_to_go_to_today(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $intro = file_get_contents(resource_path('views/layouts/partials/onboarding.blade.php'));

        $this->assertStringContainsString('新しい情報はInboxへ', $script);
        $this->assertStringContainsString('分類はあとで大丈夫', $script);
        $this->assertStringNotContainsString('迷ったら「今日」へ', $script);
        $this->assertStringContainsString('新しい情報はInboxへ', $intro);
    }

    private function pdfUpload(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'inbox-pdf-');
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function scenario(): array
    {
        $user = User::factory()->create();

        $plan = Plan::create([
            'user_id' => $user->id,
            'owner_token' => Str::random(64),
            'public_slug' => (string) Str::uuid(),
            'title' => 'InboxテストPlan',
            'description' => 'Inboxの確認',
            'category' => '就活・キャリア',
            'priority' => 1,
            'priority_mode' => 'manual',
            'start_date' => today(),
            'deadline' => today()->addMonth(),
            'is_public' => false,
        ]);

        $task = Task::create([
            'plan_id' => $plan->id,
            'title' => '確認Task',
            'description' => 'Inbox連携を確認する',
            'estimated_minutes' => 60,
            'remaining_minutes' => 60,
            'progress_percent' => 20,
            'status' => 'doing',
            'priority' => 1,
            'activation_cost' => 2,
            'sort_order' => 1,
        ]);

        return [$user, $plan, $task];
    }
}
