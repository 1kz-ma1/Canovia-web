<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\User;
use App\Services\PrivateFilePathFenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class InboxUploadPathFenceG3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        config(['canovia.private_file_path_fence_enabled' => true]);
        $this->withoutVite();
    }

    public function test_regular_inbox_upload_commits_file_reference_and_matching_fence(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('inbox.store'), [
            'source_file' => UploadedFile::fake()->image('inbox.png'),
            'intake_mode' => 'capture',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item = InboxItem::query()->firstOrFail();
        $this->assertSame('image', $item->source_type);
        Storage::assertExists($item->storage_path);
        $this->assertDatabaseHas('private_file_path_fences', [
            'path_digest' => app(PrivateFilePathFenceService::class)->fingerprint(
                (string) config('filesystems.default'), $item->storage_path,
            ),
        ]);
    }
}
