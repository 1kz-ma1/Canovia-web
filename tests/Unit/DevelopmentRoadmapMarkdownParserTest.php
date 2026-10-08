<?php

namespace Tests\Unit;

use App\Services\DevelopmentRoadmapMarkdownParser;
use PHPUnit\Framework\TestCase;

final class DevelopmentRoadmapMarkdownParserTest extends TestCase
{
    public function test_it_projects_bullets_without_inventing_task_ids_or_completion(): void
    {
        $parser = new DevelopmentRoadmapMarkdownParser;
        $result = $parser->parse("## Next slices\n- Merge PR #374\n- Deploy to production\n", 'owner/repo', 'docs/development/ROADMAP.md', str_repeat('a', 40));

        $this->assertSame('unverified', $result['sections'][0]['entries'][0]['status']);
        $this->assertSame('Merge PR #374', $result['sections'][0]['entries'][0]['text']);
        $this->assertArrayNotHasKey('task_id', $result['sections'][0]['entries'][0]);
        $this->assertSame(str_repeat('a', 40), $result['source']['sha']);
    }

    public function test_it_ignores_code_fences(): void
    {
        $result = (new DevelopmentRoadmapMarkdownParser)->parse("## Work\n\x60\x60\x60\n- fake task\n\x60\x60\x60\n- real task", 'owner/repo', 'docs/development/ROADMAP.md', str_repeat('b', 40));

        $this->assertCount(1, $result['sections'][0]['entries']);
        $this->assertSame('real task', $result['sections'][0]['entries'][0]['text']);
    }

    public function test_it_rejects_untrusted_sources_and_oversized_files(): void
    {
        $parser = new DevelopmentRoadmapMarkdownParser;
        $this->expectException(\InvalidArgumentException::class);
        $parser->parse('## Work', 'owner/repo', '../secrets.md', str_repeat('a', 40));
    }

    public function test_it_rejects_oversized_markdown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DevelopmentRoadmapMarkdownParser)->parse(str_repeat('x', DevelopmentRoadmapMarkdownParser::MAX_BYTES + 1), 'owner/repo', 'docs/development/ROADMAP.md', str_repeat('a', 40));
    }
}
