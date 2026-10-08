<?php

namespace Tests\Unit;

use App\Services\DevelopmentRoadmapMarkdownParser;
use PHPUnit\Framework\TestCase;

final class DevelopmentRoadmapTableProjectionTest extends TestCase
{
    public function test_canonical_roadmap_table_is_read_as_unverified_workstreams(): void
    {
        $markdown = <<<'MARKDOWN'
# Roadmap

## Verification semantics
| Priority | Workstream | Existing evidence | Remaining acceptance |
| --- | --- | --- | --- |
| P0 | Adaptive Learning | PR #375 merged | Verify iPhone E2E |
| P1 | <script>alert(1)</script>GitHub Roadmap | Spec only | CI pending |

## Next slices
- Verify deployment
MARKDOWN;

        $result = (new DevelopmentRoadmapMarkdownParser)->parse(
            $markdown, 'example/repo', 'docs/development/ROADMAP.md', str_repeat('a', 40),
        );
        $this->assertCount(2, $result['workstreams']);
        $this->assertSame('P0', $result['workstreams'][0]['priority']);
        $this->assertSame('Adaptive Learning', $result['workstreams'][0]['title']);
        $this->assertSame('unverified', $result['workstreams'][0]['status']);
        $this->assertSame('Verify iPhone E2E', $result['workstreams'][0]['next']);
        $this->assertSame('alert(1)GitHub Roadmap', $result['workstreams'][1]['title']);
        $this->assertSame('Verify deployment', $result['sections'][1]['entries'][0]['text']);
        $this->assertArrayNotHasKey('task_id', $result['workstreams'][0]);
    }
}
