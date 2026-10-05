<?php

namespace App\Intelligence\Study;

use App\Models\Plan;

final class StudyLearningTypeRouter
{
    /**
     * @return array{
     *   key:string,
     *   label:string,
     *   confidence:float,
     *   reasons:array<int,string>,
     *   target_score:int|float|null
     * }
     */
    public function route(Plan $plan): array
    {
        $title = $this->normalize((string) $plan->title);
        $description = $this->normalize((string) $plan->description);
        $category = $this->normalize((string) $plan->category);
        $text = trim($title.' '.$description.' '.$category);

        $scoreTarget = $this->scoreTarget($title.' '.$description);

        if ($this->containsAny($text, [
            'toeic', 'toefl', 'ielts', 'gre', 'gmat',
        ])) {
            return $this->result(
                'score_exam',
                'スコア型試験',
                0.98,
                ['score_exam_keyword'],
                $scoreTarget,
            );
        }

        if ($this->containsAny($text, [
            '定期テスト', '中間テスト', '期末テスト', '学年末',
            '小テスト', '学校のテスト', '中間試験', '期末試験',
        ])) {
            return $this->result(
                'school_test',
                '学校テスト',
                0.97,
                ['school_test_keyword'],
                $this->pointTarget($title.' '.$description),
            );
        }

        if (
            str_contains($category, '資格')
            || $this->containsAny($text, [
                '応用情報', '基本情報', '情報処理技術者', '簿記',
                '宅建', '行政書士', '社労士', '公認会計士',
                '資格試験', 'certification',
            ])
            || preg_match('/(?:^|\s)ap(?:\s|$)/u', $text) === 1
        ) {
            return $this->result(
                'certification_exam',
                '資格試験',
                str_contains($category, '資格') ? 0.94 : 0.90,
                str_contains($category, '資格')
                    ? ['qualification_category']
                    : ['certification_keyword'],
            );
        }

        if ($this->containsAny($text, [
            '暗記', '単語', '語彙', '漢字', '用語暗記',
            'フラッシュカード', 'vocabulary', 'memorization',
        ])) {
            return $this->result(
                'memorization',
                '暗記・定着',
                0.90,
                ['memorization_keyword'],
            );
        }

        if ($this->containsAny($text, [
            '習得', '身につける', '学ぶ', 'python', 'swift',
            'javascript', 'プログラミング', '英会話', '技能',
            'スキル',
        ])) {
            return $this->result(
                'skill_learning',
                'スキル学習',
                0.82,
                ['skill_keyword'],
            );
        }

        return $this->result(
            'general_learning',
            '一般学習',
            0.55,
            ['fallback'],
            $scoreTarget,
        );
    }

    /**
     * @return array{
     *   key:string,
     *   label:string,
     *   confidence:float,
     *   reasons:array<int,string>,
     *   target_score:int|float|null
     * }
     */
    private function result(
        string $key,
        string $label,
        float $confidence,
        array $reasons,
        int|float|null $targetScore = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'confidence' => $confidence,
            'reasons' => $reasons,
            'target_score' => $targetScore,
        ];
    }

    private function normalize(string $value): string
    {
        $value = mb_convert_kana(
            mb_strtolower(trim($value)),
            'as',
            'UTF-8',
        );

        return preg_replace('/[\s　]+/u', ' ', $value) ?? $value;
    }

    /**
     * @param array<int,string> $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $this->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    private function pointTarget(string $text): int|float|null
    {
        $text = $this->normalize($text);

        if (
            preg_match(
                '/([0-9]{1,3}(?:\.[0-9]+)?)\s*(?:点|%|パーセント)/u',
                $text,
                $matches,
            ) === 1
        ) {
            $value = (float) $matches[1];

            if ($value >= 0 && $value <= 100) {
                return floor($value) === $value
                    ? (int) $value
                    : $value;
            }
        }

        return null;
    }

    private function scoreTarget(string $text): int|float|null
    {
        $text = $this->normalize($text);

        if (
            preg_match(
                '/(?:toeic|toefl|gre|gmat)[^0-9]{0,12}([0-9]{2,4})/u',
                $text,
                $matches,
            ) === 1
        ) {
            return (int) $matches[1];
        }

        if (
            preg_match(
                '/ielts[^0-9]{0,12}([0-9](?:\.[05])?)/u',
                $text,
                $matches,
            ) === 1
        ) {
            return (float) $matches[1];
        }

        return null;
    }
}
