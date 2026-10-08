<?php

namespace App\Services;

/**
 * Lightweight self-reflection for occupation exploration. These answers are
 * hypotheses, not an employment aptitude test or a hiring-success score.
 */
final class CareerExplorationAssessmentService
{
    public const SESSION_KEY = 'canovia.career.exploration.v1';

    /**
     * Dimensions are stable across the Canovia and future imported-PDF paths.
     * An external source can cover a dimension only after user confirmation.
     *
     * @return array<string,array{label:string,options:array<string,string>}>
     */
    public function questions(): array
    {
        return [
            'interest_activity' => [
                'label' => 'どんな活動に興味がありますか？',
                'options' => [
                    'analyze' => '情報を集め、比較・分析する',
                    'create' => 'アイデアや形あるものを作る',
                    'support' => '人の役に立ち、相談に乗る',
                    'coordinate' => '人や物事を調整して進める',
                    'unsure' => 'まだ分からない',
                ],
            ],
            'work_value' => [
                'label' => '仕事を選ぶうえで、まず大切にしたいことは？',
                'options' => [
                    'stability' => '安定した環境',
                    'learning' => '成長・新しい経験',
                    'autonomy' => '裁量・自分なりの工夫',
                    'impact' => '人や社会への貢献',
                    'unsure' => 'まだ分からない',
                ],
            ],
            'work_style' => [
                'label' => '今の自分が取り組みやすいと思うのは？',
                'options' => [
                    'team' => '人と協力して進める',
                    'solo' => '一人で集中して進める',
                    'mixed' => 'どちらも関心がある',
                    'unsure' => 'まだ分からない',
                ],
            ],
            'location_preference' => [
                'label' => '勤務地や働き方の条件はありますか？',
                'options' => [
                    'local' => '通える範囲を重視したい',
                    'flexible' => '勤務地は幅広く検討したい',
                    'remote' => '在宅勤務の可能性を調べたい',
                    'unsure' => 'まだ決めていない',
                ],
            ],
        ];
    }

    /**
     * PDF extraction results must not be treated as known until the actor has
     * confirmed each dimension. This also prevents approximate MATCH plus
     * indicators from silently overriding a different Canovia question.
     *
     * @param array<string,array{value?:mixed,confirmed?:mixed}> $external
     * @return array<string,string>
     */
    public function confirmedCoverage(array $external): array
    {
        $coverage = [];
        foreach ($this->questions() as $key => $question) {
            $field = $external[$key] ?? null;
            if (! is_array($field) || ($field['confirmed'] ?? false) !== true) {
                continue;
            }
            $value = $field['value'] ?? null;
            if (is_string($value) && array_key_exists($value, $question['options'])) {
                $coverage[$key] = $value;
            }
        }

        return $coverage;
    }

    /**
     * @param array<string,mixed> $external
     * @return array<string,array{label:string,options:array<string,string>}>
     */
    public function remainingQuestions(array $external = []): array
    {
        return array_diff_key($this->questions(), $this->confirmedCoverage($external));
    }

    /**
     * @param array<string,string> $answers
     * @return array<int,array{title:string,reason:string,next_step:string}>
     */
    public function explorationDirections(array $answers): array
    {
        $interest = $answers['interest_activity'] ?? 'unsure';

        $directions = [
            'analyze' => [
                ['調査・分析', '情報を比較する活動への関心があるため', '調査・分析を行う仕事の実例を1件調べる'],
                ['業務改善', '課題を整理する仕事も比較対象になるため', '業務改善の仕事の一日を調べる'],
                ['企画・マーケティング', '調べた情報を施策に生かす仕事を比較するため', '企画職の仕事内容を調べる'],
            ],
            'create' => [
                ['制作・デザイン', 'ものを作る活動への関心があるため', '制作・デザイン職の仕事内容を調べる'],
                ['開発・エンジニアリング', '仕組みを形にする働き方を比べるため', '開発職の仕事内容を調べる'],
                ['企画', 'アイデアを実現する過程への関心を確かめるため', '企画職の役割を調べる'],
            ],
            'support' => [
                ['人事・採用', '人を支える仕事への関心を確かめるため', '採用・人事職の仕事内容を調べる'],
                ['カスタマーサポート', '相手の課題に向き合う仕事を比べるため', 'サポート職の仕事を調べる'],
                ['教育・研修', '人の成長を支える仕事を比較するため', '教育・研修関連職を調べる'],
            ],
            'coordinate' => [
                ['プロジェクト運営', '人や物事を調整する活動への関心があるため', '進行管理の仕事内容を調べる'],
                ['営業・顧客折衝', '相手との調整が多い仕事を比べるため', '営業職の仕事内容を調べる'],
                ['企画・運営', '企画を動かす役割への関心を確かめるため', '企画運営の仕事を調べる'],
            ],
            'unsure' => [
                ['調査・分析', '仕事内容の違いを知るための比較例', '調査・分析の仕事を1件調べる'],
                ['人を支える仕事', '仕事内容の違いを知るための比較例', 'サポート系の仕事を1件調べる'],
                ['制作・開発', '仕事内容の違いを知るための比較例', '制作系の仕事を1件調べる'],
            ],
        ];

        return array_map(
            fn (array $item): array => [
                'title' => $item[0],
                'reason' => $item[1],
                'next_step' => $item[2],
            ],
            $directions[$interest] ?? $directions['unsure'],
        );
    }
}
