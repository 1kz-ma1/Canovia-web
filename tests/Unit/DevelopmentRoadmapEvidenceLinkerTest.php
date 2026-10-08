<?php

namespace Tests\Unit;

use App\Services\DevelopmentRoadmapEvidenceLinker;
use PHPUnit\Framework\TestCase;

final class DevelopmentRoadmapEvidenceLinkerTest extends TestCase
{
    public function test_it_extracts_only_bounded_explicit_pr_references_from_evidence(): void
    {
        $linker = new DevelopmentRoadmapEvidenceLinker;
        $references = $linker->references([
            ['evidence' => 'PR #362–#366 shipped; #371–#373 legacy shell'],
            ['evidence' => 'v58.3, Issue #999, #12, and no explicit PR'],
            ['evidence' => 'PR #373 merged'],
            ['evidence' => 'PR #100–#99999 invalid and PR #7–#6 backward'],
        ]);

        $this->assertSame([362, 363, 364, 365, 366, 371, 372, 373], $references[0]);
        $this->assertArrayNotHasKey(1, $references);
        $this->assertSame([373], $references[2]);
        $this->assertArrayNotHasKey(3, $references);
        $this->assertSame([362, 363, 364, 365, 366, 371, 372, 373], $linker->uniqueNumbers($references));
    }

    public function test_it_caps_cross_workstream_references_and_never_sets_completion(): void
    {
        $linker = new DevelopmentRoadmapEvidenceLinker;
        $rows = [
            ['evidence' => 'PR #1-#8', 'status' => 'unverified'],
            ['evidence' => 'PR #9-#20', 'status' => 'unverified'],
        ];
        $refs = $linker->references($rows);
        $this->assertSame(range(1, 8), $refs[0]);
        // A range wider than seven steps is ignored instead of flooding API.
        $this->assertArrayNotHasKey(1, $refs);

        $roadmap = $linker->attach(['workstreams' => $rows], $refs, [
            1 => ['number' => 1, 'merge_state' => 'merged_default', 'ci_state' => 'observed_pass'],
        ]);
        $this->assertSame('unverified', $roadmap['workstreams'][0]['status']);
        $this->assertSame('merged_default', $roadmap['workstreams'][0]['github_signals'][0]['merge_state']);
        $this->assertSame('unknown', $roadmap['workstreams'][0]['github_signals'][1]['merge_state']);
    }

    public function test_it_limits_global_unique_references_to_ten(): void
    {
        $linker = new DevelopmentRoadmapEvidenceLinker;
        $refs = $linker->references([
            ['evidence' => 'PR #1-#8'],
            ['evidence' => 'PR #9-#12'],
            ['evidence' => 'PR #13'],
        ]);
        $this->assertSame(range(1, 10), $linker->uniqueNumbers($refs));
    }
}
