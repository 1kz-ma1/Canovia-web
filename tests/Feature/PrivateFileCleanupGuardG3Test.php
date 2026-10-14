<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Models\StudyRecallSource;
use App\Services\PrivateFileCleanupGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PrivateFileCleanupGuardG3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_existing_inbox_reference_prevents_removal_of_shared_file(): void
    {
        $path = 'inbox/shared/source.png';
        Storage::disk('local')->put($path, 'private bytes');
        $item = InboxItem::query()->create([
            'source_type' => 'image',
            'status' => 'new',
            'storage_path' => $path,
        ]);
        $guard = app(PrivateFileCleanupGuard::class);

        $this->assertFalse($guard->deleteIfUnreferenced($path));
        Storage::disk('local')->assertExists($path);

        $item->delete();
        $this->assertTrue($guard->deleteIfUnreferenced($path));
        Storage::disk('local')->assertMissing($path);
    }

    public function test_recall_source_reference_prevents_account_cleanup_from_deleting_shared_path(): void
    {
        $path = 'study-recall-sources/shared/existing.pdf';
        Storage::disk('local')->put($path, 'private bytes');
        $source = StudyRecallSource::query()->create([
            'source_type' => 'pdf',
            'status' => 'pending',
            'storage_path' => $path,
        ]);
        $guard = app(PrivateFileCleanupGuard::class);

        $this->assertFalse($guard->deleteIfUnreferenced($path));
        Storage::disk('local')->assertExists($path);

        $source->delete();
        $this->assertTrue($guard->deleteIfUnreferenced($path));
        Storage::disk('local')->assertMissing($path);
    }

    public function test_missing_file_is_safe_and_empty_path_is_not_deleted(): void
    {
        $guard = app(PrivateFileCleanupGuard::class);
        $this->assertFalse($guard->deleteIfUnreferenced(''));
        $this->assertTrue($guard->deleteIfUnreferenced('inbox/nonexistent/file.png'));
    }
}
