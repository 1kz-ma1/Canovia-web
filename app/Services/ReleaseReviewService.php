<?php

namespace App\Services;

use App\Enums\ReleaseLevel;
use App\Models\ReleaseReviewCheck;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ReleaseReviewService
{
    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function items(ReleaseLevel $target): Collection
    {
        $saved = ReleaseReviewCheck::query()
            ->with('reviewedBy:id,name')
            ->where('release_level', '<=', $target->value)
            ->get()
            ->keyBy(
                fn (ReleaseReviewCheck $check) =>
                    $check->release_level.':'.$check->check_key,
            );

        $items = collect();

        foreach (ReleaseLevel::cases() as $sourceLevel) {
            if ($sourceLevel->value > $target->value) {
                continue;
            }

            foreach ($this->definitions($sourceLevel) as $checkKey => $label) {
                $stored = $saved->get(
                    $sourceLevel->value.':'.$checkKey,
                );

                $items->push([
                    'source_level' => $sourceLevel,
                    'check_key' => $checkKey,
                    'label' => $label,
                    'status' => $stored?->status ?? 'pending',
                    'note' => $stored?->note,
                    'reviewed_at' => $stored?->reviewed_at,
                    'reviewed_by_id' => $stored?->reviewed_by_user_id,
                    'reviewed_by_name' => $stored?->reviewedBy?->name,
                ]);
            }
        }

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(ReleaseLevel $target): array
    {
        $items = $this->items($target);
        $total = $items->count();
        $passed = $items
            ->where('status', ReleaseReviewCheck::STATUS_PASSED)
            ->count();
        $failed = $items
            ->where('status', ReleaseReviewCheck::STATUS_FAILED)
            ->count();
        $pending = max(0, $total - $passed - $failed);

        return [
            'items' => $items,
            'total' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'pending' => $pending,
            'complete' => $total > 0
                && $passed === $total
                && $failed === 0,
            'status' => $failed > 0
                ? 'failed'
                : ($pending > 0 ? 'pending' : 'passed'),
        ];
    }

    public function save(
        ReleaseLevel $sourceLevel,
        string $checkKey,
        string $status,
        ?string $note,
        User $reviewer,
    ): ReleaseReviewCheck {
        $definitions = $this->definitions($sourceLevel);

        if (! array_key_exists($checkKey, $definitions)) {
            throw ValidationException::withMessages([
                'check_key' => 'Release Reviewの対象項目が見つかりません。',
            ]);
        }

        if (! in_array($status, [
            ReleaseReviewCheck::STATUS_PASSED,
            ReleaseReviewCheck::STATUS_FAILED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Release Reviewの状態が不正です。',
            ]);
        }

        return ReleaseReviewCheck::query()->updateOrCreate(
            [
                'release_level' => $sourceLevel->value,
                'check_key' => $checkKey,
            ],
            [
                'status' => $status,
                'note' => $this->nullableTrim($note),
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
            ],
        );
    }

    public function reset(
        ReleaseLevel $sourceLevel,
        string $checkKey,
    ): void {
        if (! array_key_exists($checkKey, $this->definitions($sourceLevel))) {
            throw ValidationException::withMessages([
                'check_key' => 'Release Reviewの対象項目が見つかりません。',
            ]);
        }

        ReleaseReviewCheck::query()
            ->where('release_level', $sourceLevel->value)
            ->where('check_key', $checkKey)
            ->delete();
    }

    /**
     * @return array<string,string>
     */
    public function definitions(ReleaseLevel $level): array
    {
        $definitions = (array) config(
            'release_levels.gate.levels.'.$level->value.'.manual_checks',
            [],
        );

        $normalized = [];

        foreach ($definitions as $key => $label) {
            $normalized[
                is_string($key) ? $key : 'manual_'.((int) $key + 1)
            ] = (string) $label;
        }

        return $normalized;
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
