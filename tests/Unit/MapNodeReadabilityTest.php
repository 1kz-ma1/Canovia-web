<?php

namespace Tests\Unit;

use App\Support\MapNodePresentation;
use PHPUnit\Framework\TestCase;

class MapNodeReadabilityTest extends TestCase
{
    public function test_recommendation_is_role_only_on_canvas_without_mutating_source_data(): void
    {
        $node = [
            'type' => 'task',
            'state' => 'primary',
            'position_role' => 'action-primary',
            'label' => '画面の重なりを直す',
        ];
        $original = $node;

        $presentation = MapNodePresentation::for($node);

        $this->assertSame('おすすめ', $presentation['label']);
        $this->assertNull($presentation['eyebrow']);
        $this->assertSame('おすすめ', MapNodePresentation::role($node));
        $this->assertSame($original, $node);
    }

    public function test_non_recommended_leaf_tasks_keep_their_concrete_names(): void
    {
        foreach (['レビュー結果を確認する', '<script>alert(1)</script>'] as $title) {
            $node = ['type' => 'task', 'position_role' => 'future-next', 'label' => $title];
            $presentation = MapNodePresentation::for($node);

            $this->assertSame($title, $presentation['label']);
            $this->assertSame('次にやる', $presentation['eyebrow']);
        }
    }

    public function test_missing_name_has_a_readable_fallback_and_unknown_role_has_no_invented_reason(): void
    {
        $this->assertSame('名前のないタスク', MapNodePresentation::for(['type' => 'task', 'label' => '  '])['label']);
        $this->assertNull(MapNodePresentation::reason(['type' => 'unknown', 'state' => 'ready']));
    }

    public function test_next_candidate_is_not_presented_as_ready_or_completed(): void
    {
        $reason = MapNodePresentation::reason(['type' => 'task', 'position_role' => 'future-next']);
        $this->assertStringContainsString('候補', $reason);
        $this->assertStringContainsString('前提条件', $reason);
        $this->assertStringNotContainsString('開始できます', $reason);
    }

    public function test_external_review_and_dependency_reasons_use_explicit_roles(): void
    {
        $this->assertStringContainsString('外部サービス', MapNodePresentation::reason(['eyebrow' => 'EXTERNAL TOOL']));
        $this->assertStringContainsString('レビュー', MapNodePresentation::reason(['eyebrow' => 'REVIEW WAITING']));
        $this->assertStringContainsString('前提タスク', MapNodePresentation::reason(['position_role' => 'future-dependency-1']));
    }
}
