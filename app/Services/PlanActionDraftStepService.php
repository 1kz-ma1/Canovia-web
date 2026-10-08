<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanActionDraft;

/**
 * Intentional small, deterministic sequence proposal.
 *
 * These are suggested checkpoints, not inferred facts, not a completed
 * roadmap, and not automatically executed tasks. The Plan owner can edit
 * the three titles before explicitly accepting the entire bundle.
 */
final class PlanActionDraftStepService
{
    public const STEP_COUNT = 3;

    public function __construct(private readonly PlanCategoryProfileService $profiles) {}

    /** @return list<string> */
    public function suggest(Plan $plan, PlanActionDraft $draft): array
    {
        $domain = $this->profiles->forPlan($plan)->key;

        [$second, $third] = match ($domain) {
            'study' => [
                '選んだ項目を小さな演習や復習で試す',
                '結果を記録し、次の学習内容を見直す',
            ],
            'development' => [
                '選んだ開発項目を小さく実装・確認する',
                'テストや実機確認の結果を記録し、次の修正を選ぶ',
            ],
            'career' => [
                '選んだ職種や企業について、確認したい質問を1つ準備する',
                '確認した内容や感想を記録し、次に取る行動を選ぶ',
            ],
            default => [
                '選んだ内容を小さく試して結果を確認する',
                '結果と発見を記録し、次の一歩を見直す',
            ],
        };

        // The first action is the owner's CURRENT candidate, not a stale
        // copy of a past recommendation. This works even for old V58.71 rows.
        return [
            mb_substr(trim($draft->suggested_next_action), 0, 255),
            $second,
            $third,
        ];
    }
}
