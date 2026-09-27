<?php

namespace App\Support;

use App\Models\ReleaseNote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ReleaseNotes
{
    private const CACHE_KEY = 'canovia.release_notes.published.v1';

    public static function all(): Collection
    {
        $configured = collect(config('release_notes', []))
            ->values()
            ->map(function (array $note, int $index): array {
                $date = (string) ($note['date'] ?? now()->toDateString());

                return [
                    'key' => 'config-'.($note['version'] ?? 'release').'-'.$date.'-'.$index,
                    'version' => (string) ($note['version'] ?? ''),
                    'date' => $date,
                    'title' => (string) ($note['title'] ?? 'Canoviaの更新'),
                    'summary' => (string) ($note['summary'] ?? ''),
                    'user_voice' => filled($note['user_voice'] ?? null) ? (string) $note['user_voice'] : null,
                    'highlights' => array_values(array_filter((array) ($note['highlights'] ?? []))),
                    'tip' => filled($note['tip'] ?? null) ? (string) $note['tip'] : null,
                    'feedback_linked' => (bool) ($note['feedback_linked'] ?? false),
                    'sort_at' => $date.' 00:00:00',
                ];
            });

        try {
            $database = app()->environment('production')
                ? collect(Cache::store('file')->remember(
                    self::CACHE_KEY,
                    now()->addMinutes(10),
                    fn () => self::databaseNotes()->all(),
                ))
                : self::databaseNotes();

            return self::sorted($database->concat($configured));
        } catch (\Throwable) {
            // The update log must never make normal Canovia screens unavailable.
            return self::sorted($configured);
        }
    }

    public static function forgetCache(): void
    {
        try {
            Cache::store('file')->forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // Cache invalidation must never block publishing a release note.
        }
    }

    private static function databaseNotes(): Collection
    {
        return ReleaseNote::query()
            ->published()
            ->latest('published_at')
            ->latest('id')
            ->get()
            ->map(function (ReleaseNote $note): array {
                $publishedAt = $note->published_at ?? $note->created_at ?? now();

                return [
                    'key' => 'db-'.$note->id.'-'.$note->updated_at?->timestamp,
                    'version' => (string) $note->version,
                    'date' => $publishedAt->toDateString(),
                    'title' => (string) $note->title,
                    'summary' => (string) $note->summary,
                    'user_voice' => $note->user_voice,
                    'highlights' => array_values(array_filter((array) $note->highlights)),
                    'tip' => $note->tip,
                    'feedback_linked' => $note->feedback_id !== null,
                    'sort_at' => $publishedAt->format('Y-m-d H:i:s'),
                ];
            });
    }

    private static function sorted(Collection $notes): Collection
    {
        return $notes
            ->sortByDesc('sort_at')
            ->values();
    }
}
