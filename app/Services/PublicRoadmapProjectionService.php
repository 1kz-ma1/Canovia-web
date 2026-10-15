<?php

namespace App\Services;

use App\Enums\RoadmapFeatureStatus;

/**
 * Read-only projection of owner-edited public roadmap copy.
 *
 * No GitHub/AI/queue/DB synchronization and no release state inference.
 * The config file is versioned and reviewed like any other source change.
 */
final class PublicRoadmapProjectionService
{
    private const GROUPS = [
        'development' => [
            'title' => '現在開発中',
            'description' => 'より使いやすくするために取り組んでいる内容です。まだ利用可能とは限りません。',
        ],
        'verification' => [
            'title' => '検証中',
            'description' => '実装や操作感を確かめている段階です。一般公開を意味しません。',
        ],
        'released' => [
            'title' => '公開済み',
            'description' => '公開対象と利用範囲を確認したものだけ掲載します。',
        ],
        'upcoming' => [
            'title' => 'これから',
            'description' => '今後検討している方向性です。開発や公開を約束するものではありません。',
        ],
    ];

    /** @return array<int, array{key: string, title: string, description: string, items: array<int, array<string, string>>}> */
    public function build(): array
    {
        $sections = [];
        foreach (self::GROUPS as $key => $group) {
            $sections[$key] = [
                'key' => $key,
                'title' => $group['title'],
                'description' => $group['description'],
                'items' => [],
            ];
        }

        $items = config('canovia_public_roadmap.items', []);
        if (! is_array($items)) {
            return array_values($sections);
        }

        $used = [];
        foreach (array_slice($items, 0, 24) as $item) {
            if (! is_array($item) || ($item['is_published'] ?? false) !== true) {
                continue;
            }

            $key = $item['key'] ?? null;
            $title = $item['title'] ?? null;
            $description = $item['description'] ?? null;
            $area = $item['area'] ?? null;
            $updated = $item['updated_on'] ?? null;
            $status = $item['status'] ?? null;
            if (! is_string($key) || ! preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/D', $key)
                || isset($used[$key]) || ! $this->safeCopy($title, 180)
                || ! $this->safeCopy($description, 900) || ! $this->safeCopy($area, 64)
                || ! $this->validDate($updated) || ! is_string($status)) {
                continue;
            }

            $stage = match (RoadmapFeatureStatus::tryFrom($status)) {
                RoadmapFeatureStatus::InDevelopment => 'development',
                RoadmapFeatureStatus::Ready, RoadmapFeatureStatus::RollingOut => 'verification',
                RoadmapFeatureStatus::Released => 'released',
                RoadmapFeatureStatus::Planned => 'upcoming',
                default => null,
            };
            if ($stage === null) {
                continue; // Voting/considering belong to the existing Support section.
            }

            if (in_array($status, ['rolling_out', 'released'], true)
                && (($item['owner_release_verified'] ?? false) !== true
                    || ! $this->validDate($item['release_verified_on'] ?? null)
                    || ! in_array($item['access_scope'] ?? null, ['public', 'limited'], true))) {
                continue; // Never equate a merged PR, passing CI or status text with release.
            }

            $used[$key] = true;
            $entry = [
                'key' => $key,
                'title' => trim($title),
                'description' => trim($description),
                'area' => trim($area),
                'updated_on' => $updated,
                'label' => match ($status) {
                    'ready' => '検証中',
                    'rolling_out' => '段階公開中',
                    'released' => ($item['access_scope'] === 'limited' ? '対象者限定で公開済み' : '公開済み'),
                    'planned' => '今後の予定',
                    default => '開発中',
                },
            ];

            if (in_array($status, ['rolling_out', 'released'], true)) {
                $entry['release_verified_on'] = $item['release_verified_on'];
            }
            $sections[$stage]['items'][] = $entry;
        }

        return array_values($sections);
    }

    private function safeCopy(mixed $copy, int $maxBytes): bool
    {
        return is_string($copy) && trim($copy) !== '' && strlen($copy) <= $maxBytes;
    }

    private function validDate(mixed $date): bool
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year);
    }
}
