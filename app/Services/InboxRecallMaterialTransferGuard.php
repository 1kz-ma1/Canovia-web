<?php

namespace App\Services;

use App\Models\InboxItem;
use App\Models\Plan;
use App\Models\StudyRecallSource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The Inbox file is copied to a new immutable Recall source. The copy and
 * final processed acknowledgement both check the original Inbox source,
 * Plan/Task binding and Editor membership inside a locked transaction.
 * The AI provider never runs while this transaction holds database locks.
 */
final class InboxRecallMaterialTransferGuard
{
    public function __construct(
        private readonly CompanionMembershipCommitGuard $membership,
        private readonly PrivateFilePathFenceService $pathFence,
        private readonly PrivateFileStorageBoundaryService $storageBoundary,
    ) {}

    /**
     * @return array{source:StudyRecallSource,signature:string,authorization:array<string,mixed>}
     */
    public function copy(
        InboxItem $item,
        Plan $plan,
        Task $task,
        User $actor,
        string $actorToken,
    ): array {
        $newPath = null;

        try {
            return DB::transaction(function () use (
                $item, $plan, $task, $actor, $actorToken, &$newPath,
            ): array {
                [$livePlan, $liveTask, $inbox, $authorization] =
                    $this->lockCurrent($plan, $task, $item, $actor, $actorToken);

                if (! in_array($inbox->source_type, ['text', 'image', 'pdf'], true)) {
                    $this->reject('URLだけではRecall教材として抽出しません。');
                }

                $originalSignature = $this->signature($inbox);
                if ($originalSignature === null) {
                    $this->reject('Inboxの教材を読み込めません。元資料を確認してください。');
                }

                $sourceText = null;
                if ($inbox->source_type === 'text') {
                    $sourceText = trim((string) $inbox->content);
                    if ($sourceText === '') {
                        $this->reject('教材本文が空です。');
                    }
                } else {
                    // The first signature hashes source bytes A. The exact
                    // copy bytes B must independently reproduce that SAME
                    // signature before and after the new file is written.
                    try {
                        $bytes = Storage::get((string) $inbox->storage_path);
                    } catch (Throwable) {
                        $this->reject('コピー元のInbox教材が変更または削除されました。');
                    }
                    $inputHash = hash('sha256', $bytes);
                    if (! hash_equals(
                        $originalSignature,
                        $this->signature($inbox, $inputHash) ?? '',
                    )) {
                        $this->reject('コピー中に元教材が変更されました。もう一度確認してください。');
                    }

                    // This legacy copy path has no durable prewrite intent.
                    $this->storageBoundary->requireLocalLegacyWriter();
                    $extension = pathinfo((string) $inbox->storage_path, PATHINFO_EXTENSION);
                    $newPath = 'study-recall-sources/'.$livePlan->id.'/'.$liveTask->id.'/'
                        .Str::uuid().($extension !== '' ? '.'.$extension : '');

                    // Pilot writer: take the same path fence as the cleanup
                    // worker while the DB transaction owns the Recall source
                    // insert and the physical copy. Do not claim other native
                    // upload routes participate yet.
                    if ($this->pathFence->enabled()) {
                        $this->pathFence->acquireInTransaction(
                            (string) config('filesystems.default', 'local'),
                            $newPath,
                        );
                    }

                    try {
                        if (! Storage::put($newPath, $bytes)
                            || ! hash_equals($inputHash, hash(
                                'sha256', Storage::get($newPath),
                            ))) {
                            $this->reject('Recall教材のコピーを検証できませんでした。');
                        }
                    } catch (Throwable $exception) {
                        if ($exception instanceof ValidationException) {
                            throw $exception;
                        }
                        $this->reject('Recall教材のコピーを検証できませんでした。');
                    }
                }

                // A source may change without touching its Inbox database
                // row. Re-hash the current original after the physical copy.
                if (! hash_equals($originalSignature, $this->signature($inbox) ?? '')) {
                    $this->reject('Inboxの元教材がコピー中に変更されました。');
                }

                $source = StudyRecallSource::query()->create([
                    'plan_id' => (int) $livePlan->id,
                    'task_id' => (int) $liveTask->id,
                    'user_id' => (int) $actor->id,
                    'actor_token' => null,
                    'source_type' => $inbox->source_type,
                    'original_name' => $inbox->original_name,
                    'mime_type' => $inbox->mime_type,
                    'storage_path' => $newPath,
                    'source_text' => $sourceText,
                    'status' => 'pending',
                ]);

                return [
                    'source' => $source,
                    'signature' => $originalSignature,
                    'authorization' => $authorization,
                ];
            }, 3);
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                try {
                    $this->storageBoundary->deleteRollbackForLocal($newPath);
                } catch (Throwable) {
                    // File cleanup is best-effort; do not mask the cause.
                }
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $originalAuthorization */
    public function confirmProcessed(
        InboxItem $item,
        Plan $plan,
        Task $task,
        StudyRecallSource $source,
        User $actor,
        string $actorToken,
        string $originalSignature,
        array $originalAuthorization,
    ): void {
        DB::transaction(function () use (
            $item, $plan, $task, $source, $actor, $actorToken,
            $originalSignature, $originalAuthorization,
        ): void {
            [$livePlan, $liveTask, $inbox, $authorization] =
                $this->lockCurrent($plan, $task, $item, $actor, $actorToken);

            if ($authorization !== $originalAuthorization) {
                $this->reject('共有権限が変更されました。現在の権限で整理し直してください。');
            }
            if (! hash_equals(
                $originalSignature, $this->signature($inbox) ?? '',
            )) {
                $this->reject('元のInbox教材が変更されました。整理済みには変更しません。');
            }

            $liveSource = StudyRecallSource::query()->whereKey($source->id)
                ->lockForUpdate()->first();
            if ($liveSource === null
                || (int) $liveSource->plan_id !== (int) $livePlan->id
                || (int) $liveSource->task_id !== (int) $liveTask->id
                || (int) $liveSource->user_id !== (int) $actor->id
                || $liveSource->status !== 'ready'
                || ! $liveSource->hasStoredMaterial()) {
                $this->reject('Recall候補の元資料が変更されました。整理状態を再確認してください。');
            }

            $metadata = is_array($inbox->metadata) ? $inbox->metadata : [];
            $metadata['routing_confirmed'] = [
                'destination' => 'recall_material',
                'plan_id' => (int) $livePlan->id,
                'task_id' => (int) $liveTask->id,
                'confirmed_at' => now()->toIso8601String(),
            ];
            $inbox->update([
                'plan_id' => (int) $livePlan->id,
                'status' => 'processed',
                'processed_at' => now(),
                'metadata' => $metadata,
            ]);
        }, 3);
    }

    /**
     * @return array{Plan,Task,InboxItem,array<string,mixed>}
     */
    private function lockCurrent(
        Plan $plan, Task $task, InboxItem $item, User $actor, string $actorToken,
    ): array {
        // Lock order Plan -> Task -> Inbox -> Member, consistent for both
        // copy and completion. The route-bound snapshots are not authority.
        $livePlan = Plan::query()->whereKey($plan->id)->lockForUpdate()->first();
        $liveTask = Task::query()->whereKey($task->id)->lockForUpdate()->first();
        $inbox = InboxItem::query()->whereKey($item->id)->lockForUpdate()->first();

        if ($livePlan === null || $liveTask === null || $inbox === null
            || (int) $liveTask->plan_id !== (int) $livePlan->id
            || $livePlan->category !== '資格学習') {
            $this->reject('対象Plan・Task・Inboxが変更されました。');
        }
        if ($inbox->user_id !== null
            ? (int) $inbox->user_id !== (int) $actor->id
            : ! is_string($inbox->actor_token)
                || ! hash_equals($inbox->actor_token, $actorToken)) {
            $this->reject('このInbox教材の利用権限がありません。');
        }
        if (! $this->membership->canAccess($actor, $livePlan, true)) {
            $this->reject('共有Planの編集権限が変更されました。');
        }
        $authorization = $this->membership->capture($actor, $livePlan);

        return [$livePlan, $liveTask, $inbox, $authorization];
    }

    public function signature(InboxItem $inbox, ?string $copiedBytesHash = null): ?string
    {
        try {
            $fileHash = null;
            if (in_array($inbox->source_type, ['image', 'pdf'], true)) {
                if (! is_string($inbox->storage_path)
                    || $inbox->storage_path === ''
                    || ! Storage::exists($inbox->storage_path)) {
                    return null;
                }
                $fileHash = $copiedBytesHash
                    ?? hash('sha256', Storage::get($inbox->storage_path));
            }

            return hash('sha256', json_encode([
                (int) $inbox->id,
                $inbox->user_id === null ? null : (int) $inbox->user_id,
                (string) ($inbox->actor_token ?? ''),
                $inbox->plan_id === null ? null : (int) $inbox->plan_id,
                (string) $inbox->source_type,
                (string) $inbox->status,
                (string) $inbox->title,
                (string) $inbox->content,
                (string) $inbox->source_url,
                (string) $inbox->storage_path,
                (string) $inbox->mime_type,
                (string) $inbox->original_name,
                $inbox->byte_size,
                $inbox->metadata ?? [],
                $fileHash,
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return null;
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['destination' => $message]);
    }
}
