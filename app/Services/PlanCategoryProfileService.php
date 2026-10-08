<?php

namespace App\Services;

use App\Data\PlanCategoryProfileData;
use App\Models\Plan;

class PlanCategoryProfileService
{
    /** Domain is an explicit owner choice; category remains legacy metadata. */
    public const DOMAIN_CATEGORIES = [
        'study' => '資格学習',
        'development' => 'ソフトウェア開発',
        'career' => '就活・キャリア',
        'creative' => '制作活動',
        'general' => 'その他',
    ];

    public function forPlan(Plan $plan): PlanCategoryProfileData
    {
        $domain = (string) ($plan->workspace_domain_override ?? '');
        $category = self::DOMAIN_CATEGORIES[$domain] ?? (string) ($plan->category ?? '');

        return $this->forCategory($category);
    }

    public function forCategory(string $category): PlanCategoryProfileData
    {
        $normalized = mb_strtolower(trim($category));

        if ($this->matches($normalized, ['資格', '学習', '試験', '勉強', 'study', 'certification'])) {
            return new PlanCategoryProfileData(
                key: 'study',
                label: '学習',
                roadmapRenderer: 'study_map',
                roadmapTitle: '学習ロードマップ',
                roadmapDescription: '試験や目標までの学習ステップと、今取り組む範囲を見渡します。',
                surfaceTone: 'practice',
            );
        }

        if ($this->matches($normalized, ['就活', '就職', '転職', 'キャリア', '求職', 'career', 'job'])) {
            return new PlanCategoryProfileData(
                key: 'career',
                label: '就活・キャリア',
                roadmapRenderer: 'pipeline',
                roadmapTitle: '選考ロードマップ',
                roadmapDescription: '企業探し・応募・面接・内定まで、選考の現在地を見渡します。',
                surfaceTone: 'pipeline',
            );
        }

        if ($this->matches($normalized, ['個人開発', 'ゲーム開発', '開発', 'development', 'software'])) {
            return new PlanCategoryProfileData(
                key: 'development',
                label: '開発',
                roadmapRenderer: 'delivery_flow',
                roadmapTitle: '開発ロードマップ',
                roadmapDescription: '設計・実装・確認・公開まで、成果物が前へ進む流れを見渡します。',
                surfaceTone: 'delivery',
            );
        }

        if ($this->matches($normalized, ['制作', '作品', '卒業制作', 'creative', 'project'])) {
            return new PlanCategoryProfileData(
                key: 'creative',
                label: '制作',
                roadmapRenderer: 'milestone',
                roadmapTitle: '制作ロードマップ',
                roadmapDescription: '構成・初稿・レビュー・完成など、成果物の節目を見渡します。',
                surfaceTone: 'milestone',
            );
        }

        return new PlanCategoryProfileData(
            key: 'general',
            label: '汎用',
            roadmapRenderer: 'task_flow',
            roadmapTitle: 'ロードマップ',
            roadmapDescription: '目標までのTaskと、次に進めることを見渡します。',
            surfaceTone: 'balanced',
        );
    }

    private function matches(string $category, array $needles): bool
    {
        if ($category === '') {
            return false;
        }

        foreach ($needles as $needle) {
            if (str_contains($category, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
