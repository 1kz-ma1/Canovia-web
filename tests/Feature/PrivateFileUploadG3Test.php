<?php

namespace Tests\Feature;

use App\Models\InboxItem;
use App\Services\PrivateFileUploadService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class PrivateFileUploadG3Test extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        config(['canovia.private_file_path_fence_enabled' => true]);
    }

    public function test_upload_and_inbox_owner_commit_under_the_same_path_fence(): void
    {
        $path = 'inbox/synthetic/commit.png';
        $item = app(PrivateFileUploadService::class)->storeAndPersist([
            ['file' => UploadedFile::fake()->image('a.png'), 'path' => $path],
        ], fn (array $paths) => InboxItem::query()->create([
            'source_type' => 'image', 'status' => 'new',
            'storage_path' => $paths[0],
        ]));

        $this->assertSame($path, $item->storage_path);
        Storage::assertExists($path);
        $this->assertDatabaseCount('private_file_path_fences', 1);
    }

    public function test_existing_storage_key_is_never_overwritten_or_deleted(): void
    {
        $path = 'inbox/synthetic/existing.pdf';
        Storage::put($path, 'original owner bytes');
        try {
            app(PrivateFileUploadService::class)->storeAndPersist([
                ['file' => UploadedFile::fake()->create('new.pdf', 2, 'application/pdf'),
                    'path' => $path],
            ], fn (array $paths) => InboxItem::query()->create([
                'source_type' => 'pdf', 'status' => 'new',
                'storage_path' => $paths[0],
            ]));
            $this->fail('Existing bytes must not be replaced.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Private upload key already exists.', $exception->getMessage());
        }

        $this->assertSame('original owner bytes', Storage::get($path));
        $this->assertDatabaseCount('inbox_items', 0);
    }

    public function test_failed_owner_insert_cleans_new_file_before_rolling_back_fence(): void
    {
        $path = 'inbox/synthetic/rollback.png';
        try {
            app(PrivateFileUploadService::class)->storeAndPersist([
                ['file' => UploadedFile::fake()->image('new.png'), 'path' => $path],
            ], function () {
                throw new RuntimeException('synthetic owner failure');
            });
            $this->fail('Persist callback should reject.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic owner failure', $exception->getMessage());
        }

        Storage::assertMissing($path);
        $this->assertDatabaseCount('private_file_path_fences', 0);
        $this->assertDatabaseCount('inbox_items', 0);
    }

    public function test_failed_batch_owner_insert_cleans_every_claimed_path_atomically(): void
    {
        $paths = [
            'study-recall-sources/synthetic/page1.png',
            'study-recall-sources/synthetic/page2.png',
        ];
        try {
            app(PrivateFileUploadService::class)->storeAndPersist([
                ['file' => UploadedFile::fake()->image('page1.png'),
                    'path' => $paths[0]],
                ['file' => UploadedFile::fake()->image('page2.png'),
                    'path' => $paths[1]],
            ], function () {
                InboxItem::query()->create([
                    'source_type' => 'image',
                    'status' => 'new',
                    'storage_path' => 'study-recall-sources/synthetic/page1.png',
                ]);
                throw new RuntimeException('synthetic second-owner failure');
            });
            $this->fail('A partial batch cannot be committed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic second-owner failure', $exception->getMessage());
        }

        foreach ($paths as $path) {
            Storage::assertMissing($path);
        }
        $this->assertDatabaseCount('inbox_items', 0);
        $this->assertDatabaseCount('private_file_path_fences', 0);
    }

    public function test_batch_with_existing_second_key_never_writes_first_key(): void
    {
        $path1 = 'study-recall-sources/synthetic/fresh.png';
        $path2 = 'study-recall-sources/synthetic/existing.png';
        Storage::put($path2, 'immutable');
        try {
            app(PrivateFileUploadService::class)->storeAndPersist([
                ['file' => UploadedFile::fake()->image('fresh.png'), 'path' => $path1],
                ['file' => UploadedFile::fake()->image('later.png'), 'path' => $path2],
            ], fn () => null);
            $this->fail('Batch must preflight all paths.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Private upload key already exists.', $exception->getMessage());
        }
        Storage::assertMissing($path1);
        $this->assertSame('immutable', Storage::get($path2));
    }
}
