<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Opt-in, cooperating private upload writers. Keep the path lock until
 * both file bytes and their DB reference are committed, or until created
 * bytes have been best-effort cleaned before transaction rollback.
 *
 * Never call an AI provider from within this transaction.
 */
final class PrivateFileUploadService
{
    public function __construct(
        private readonly PrivateFilePathFenceService $fence,
        private readonly PrivateFileUploadIntentService $intents,
        private readonly PrivateFileStorageBoundaryService $storageBoundary,
    ) {}

    public function enabled(): bool
    {
        return $this->fence->enabled();
    }

    /**
     * @param list<array{file:UploadedFile,path:string}> $uploads
     * @param callable(list<string>):mixed $persist Database owner creation only.
     */
    public function storeAndPersist(array $uploads, callable $persist): mixed
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Private file upload fence is disabled.');
        }

        if ($uploads === []) {
            throw new RuntimeException('Private upload list is empty.');
        }

        // An external store may retain bytes if owner persistence fails.
        // Do not begin external writes without the durable PRE-write journal.
        if (! $this->storageBoundary->isLocalDefaultDisk() && ! $this->intents->enabled()) {
            throw new RuntimeException('Private external upload requires durable journal.');
        }

        $disk = (string) config('filesystems.default', 'local');
        $keys = [];
        foreach ($uploads as $upload) {
            if (! ($upload['file'] ?? null) instanceof UploadedFile
                || ! is_string($upload['path'] ?? null)) {
                throw new RuntimeException('Invalid private upload.');
            }
            $path = $upload['path'];
            $fingerprint = $this->fence->fingerprint($disk, $path);
            if (isset($keys[$fingerprint])) {
                throw new RuntimeException('Duplicate private upload key.');
            }
            $keys[$fingerprint] = $path;
        }
        // Identical deterministic lock order avoids inversions in a batch.
        ksort($keys, SORT_STRING);

        // Only the optional journal mode performs a durable, PRE-write
        // commit. If the caller already owns a DB transaction, abort before
        // touching bytes instead of writing an uncommitted recovery record.
        $intentIds = $this->intents->enabled()
            ? $this->intents->prepare($disk, $keys)
            : [];

        try {
            $result = DB::transaction(function () use ($disk, $uploads, $persist, $keys): mixed {
            foreach ($keys as $path) {
                $this->fence->acquireInTransaction($disk, $path);
            }

            $storage = Storage::disk($disk);
            // Fail before *any* upload when an existing object might be
            // overwritten. UUID alone is not a non-clobber guarantee.
            foreach ($keys as $path) {
                if ($storage->exists($path)) {
                    throw new RuntimeException('Private upload key already exists.');
                }
            }

            $claimed = [];
            try {
                foreach ($uploads as $upload) {
                    $path = $upload['path'];
                    // A provider may write partial bytes and return false;
                    // claim the key BEFORE issuing the I/O, while locked.
                    $claimed[] = $path;
                    $written = $upload['file']->storeAs(
                        dirname($path), basename($path), $disk,
                    );
                    if (! is_string($written) || $written !== $path) {
                        throw new RuntimeException('Private upload failed.');
                    }
                }

                return $persist(array_column($uploads, 'path'));
            } catch (Throwable $exception) {
                // Cleanup stays inside the transaction while our path locks
                // are held. Never delete keys that existed before our writes.
                if ($claimed !== [] && ! $this->storageBoundary->isLocalDefaultDisk()) {
                    // In a versioned provider, a same-body ABA or another
                    // writer could now occupy this path. Preserve the object;
                    // the journal is settled to needs_review outside the rollback.
                    Log::warning('Private upload rollback cleanup held', [
                        'reason' => 'provider_generation_delete_unverified',
                    ]);
                }
                foreach ($this->storageBoundary->isLocalDefaultDisk() ? $claimed : [] as $path) {
                    try {
                        if (! $storage->delete($path)) {
                            Log::warning('Private upload rollback cleanup incomplete', [
                                'reason' => 'storage_delete_false',
                            ]);
                        }
                    } catch (Throwable) {
                        Log::warning('Private upload rollback cleanup incomplete', [
                            'reason' => 'storage_unavailable',
                        ]);
                    }
                }
                throw $exception;
            }
            }, 1);
        } catch (Throwable $exception) {
            if ($intentIds !== []) {
                $this->settleIntentSafely($intentIds, 'needs_review');
            }
            throw $exception;
        }

        if ($intentIds !== []) {
            $this->settleIntentSafely($intentIds, 'committed');
        }

        return $result;
    }

    /** @param list<int> $ids */
    private function settleIntentSafely(array $ids, string $status): void
    {
        try {
            $this->intents->settle($ids, $status);
        } catch (Throwable) {
            // Committed bytes and DB owners must NOT be rolled back based on
            // a later journal status-write error. Leave 'prepared' visible
            // for operator review. No user paths, exception messages or IDs.
            Log::warning('Private upload journal settle unavailable', [
                'reason' => 'journal_settle_unavailable',
            ]);
        }
    }
}
