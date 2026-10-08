<?php

namespace Tests\Unit;

use App\Services\DevelopmentRoadmapContextProjector;
use App\Services\DevelopmentRoadmapEvidenceLinker;
use PHPUnit\Framework\TestCase;

final class DevelopmentRoadmapIssueContextTest extends TestCase
{
    public function test_only_explicit_issue_references_are_linked_and_bounded(): void
    {
        $linker = new DevelopmentRoadmapEvidenceLinker;
        $refs = $linker->issueReferences([
            ['evidence' => 'PR #12, Issue #33, #99', 'next' => 'Issue #34'],
            ['evidence' => 'Issue #33 and Issue #40', 'next' => ''],
            ['evidence' => 'v58.9 #200, PR #13', 'next' => ''],
        ]);
        $this->assertSame([33, 34], $refs[0]);
        $this->assertSame([33, 40], $refs[1]);
        $this->assertArrayNotHasKey(2, $refs);
        $this->assertSame([33, 34, 40], $linker->uniqueNumbers($refs));
    }

    public function test_issue_closure_does_not_mark_completion_and_context_is_compact(): void
    {
        $linker = new DevelopmentRoadmapEvidenceLinker;
        $snapshot = [
            'source' => ['repository' => 'example/repo', 'path' => 'docs/development/ROADMAP.md',
                'sha' => str_repeat('a', 40)],
            'workstreams' => [
                ['priority' => 'P0', 'title' => 'Learning', 'next' => 'Device E2E pending',
                    'status' => 'unverified', 'evidence' => 'Issue #33'],
                ['priority' => 'P1', 'title' => 'Release', 'next' => 'Deploy'],
            ],
        ];
        $linked = $linker->attachIssues($snapshot, [0 => [33]], [
            33 => ['number' => 33, 'state' => 'closed', 'url' => 'https://github.com/example/repo/issues/33'],
        ]);
        $this->assertSame('unverified', $linked['workstreams'][0]['status']);
        $context = (new DevelopmentRoadmapContextProjector)->project($linked, 1);
        $this->assertSame('canovia.development_context.v1', $context['schema']);
        $this->assertSame('closed', $context['items'][0]['issue_evidence'][0]['state']);
        $this->assertSame('unverified', $context['items'][0]['completion']);
        $this->assertTrue($context['truncated']);
        $this->assertArrayNotHasKey('evidence', $context['items'][0]);
        $this->assertSame(str_repeat('a', 40), $context['source']['sha']);
    }
}
