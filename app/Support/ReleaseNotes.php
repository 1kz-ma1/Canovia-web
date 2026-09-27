<?php

namespace App\Support;

use App\Models\ReleaseNote;
use Illuminate\Support\Collection;

class ReleaseNotes
{
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
            $database = ReleaseNote::query()
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

            return self::sorted($database->concat($configured));
        } catch (\Throwable) {
            // The update log must never make normal Canovia screens unavailable.
            return self::sorted($configured);
        }
    }

    private static function sorted(Collection $notes): Collection
    {
        return $notes
            ->sortByDesc('sort_at')
            ->values();
    }
}
