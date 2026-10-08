<?php

namespace Tests\Unit;

use App\Services\DevelopmentRoadmapRevisionDiffer;
use PHPUnit\Framework\TestCase;

final class DevelopmentRoadmapRevisionDifferTest extends TestCase
{
    public function test_add_change_remove_and_reorder_without_progress_inference(): void
    {
        $before = [
            'source' => ['sha' => str_repeat('a', 40)],
            'workstreams' => [
                ['title' => 'Learning', 'priority' => 'P1', 'evidence' => 'PR #1', 'next' => 'Device check'],
                ['title' => 'Legacy', 'priority' => 'P2', 'evidence' => '', 'next' => 'Review'],
            ],
        ];
        $after = [
            'source' => ['sha' => str_repeat('b', 40)],
            'workstreams' => [
                ['title' => 'New', 'priority' => 'P0', 'evidence' => '', 'next' => 'Implement'],
                ['title' => 'Learning', 'priority' => 'P1', 'evidence' => 'PR #1', 'next' => 'Deploy check'],
            ],
        ];
        $diff = (new DevelopmentRoadmapRevisionDiffer())->compare($before, $after);

        self::assertSame('canovia.development_roadmap_diff.v1', $diff['schema']);
        self::assertSame(['added', 'changed', 'removed'], array_column($diff['changes'], 'kind'));
        self::assertSame('unverified', $diff['completion']);
        self::assertSame('Device check', $diff['changes'][1]['before']['next']);
        self::assertSame('Deploy check', $diff['changes'][1]['after']['next']);
        self::assertSame([], (new DevelopmentRoadmapRevisionDiffer())->compare($before, $before)['changes']);
    }

    public function test_duplicate_titles_are_not_silently_dropped(): void
    {
        $before = ['workstreams' => [
            ['title' => 'Build', 'next' => 'A'],
            ['title' => 'Build', 'next' => 'B'],
        ]];
        $after = ['workstreams' => [
            ['title' => 'Build', 'next' => 'A'],
            ['title' => 'Build', 'next' => 'C'],
        ]];
        $changes = (new DevelopmentRoadmapRevisionDiffer())->compare($before, $after)['changes'];
        self::assertCount(1, $changes);
        self::assertSame('changed', $changes[0]['kind']);
    }
}
