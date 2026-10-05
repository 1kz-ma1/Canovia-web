<?php

namespace App\Intelligence\Study;

use App\Models\Plan;

final class StudyLearningTypeRouter
{
    public const SCORE_EXAM = 'score_exam';
    public const SCHOOL_TEST = 'school_test';
    public const CERTIFICATION_EXAM = 'certification_exam';
    public const SKILL_LEARNING = 'skill_learning';
    public const MEMORIZATION = 'memorization';
    public const GENERAL_LEARNING = 'general_learning';

    /**
     * @return array<string,array{
     *   label:string,
     *   choice_label:string,
     *   description:string
     * }>
     */
    public function options(): array
    {
        return [
            self::SCORE_EXAM => [
                'label' => 'スコア型試験',
                'choice_label' => 'スコアを上げる',
                'description' => 'TOEIC・IELTSなど、数値スコアの向上を目標にする学習',
            ],
            self::SCHOOL_TEST => [
                'label' => '学校テスト',
                'choice_label' => '学校のテスト',
                'description' => '中間・期末・小テストなど、出題範囲がある学校内テスト',
            ],
            self::CERTIFICATION_EXAM => [
                'label' => '資格試験',
                'choice_label' => '資格に合格',
                'description' => 'AP・簿記・宅建など、合格判定がある資格試験',
            ],
            self::SKILL_LEARNING => [
                'label' => 'スキル学習',
                'choice_label' => 'スキル習得',
                'description' => 'プログラミング・英会話など、実践できる能力を身につける学習',
            ],
            self::MEMORIZATION => [
                'label' => '暗記・定着',
                'choice_label' => '暗記・定着',
                'description' => '単語・用語・知識など、思い出せる状態を作る学習',
            ],
            self::GENERAL_LEARNING => [
                'label' => '一般学習',
                'choice_label' => 'その他の学習',
                'description' => '上記に固定せず、TaskとEvidenceから柔軟に進める学習',
            ],
        ];
    }

    /**
     * @return array{
     *   key:string,
     *   label:string,
     *   confidence:float,
     *   reasons:array<int,string>,
     *   target_score:int|float|null,
     *   source:string,
     *   inferred_key:string,
     *   override_key:string|null,
     *   needs_confirmation:bool
     * }
     */
    public function route(Plan $plan): array
    {
        $inferred = $this->infer($plan);
        $override = trim(
            (string) $plan->study_learning_type_override,
        );

        if (array_key_exists($override, $this->options())) {
            return [
                ...$this->result(
                    $override,
                    1.0,
                    ['explicit_override'],
                    $this->targetForType(
                        $override,
                        $plan,
                    ),
                ),
                'source' => 'explicit_override',
                'inferred_key' => $inferred['key'],
                'override_key' => $override,
                'needs_confirmation' => false,
            ];
        }

        return [
            ...$inferred,
            'source' => 'heuristic',
            'inferred_key' => $inferred['key'],
            'override_key' => null,
            'needs_confirmation' =>
                (float) $inferred['confidence'] < 0.80,
        ];
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
    private function infer(Plan $plan): array
    {
        $title = $this->normalize((string) $plan->title);
        $description = $this->normalize(
            (string) $plan->description,
        );
        $category = $this->normalize((string) $plan->category);
        $text = trim($title.' '.$description.' '.$category);

        if ($this->containsAny($text, [
            'toeic',
            'toefl',
            'ielts',
            'gre',
            'gmat',
        ])) {
            return $this->result(
                self::SCORE_EXAM,
                0.98,
                ['score_exam_keyword'],
                $this->scoreTarget(
                    $title.' '.$description,
                ),
            );
        }

        if ($this->containsAny($text, [
            '定期テスト',
            '中間テスト',
            '期末テスト',
            '学年末',
            '小テスト',
            '学校のテスト',
            '中間試験',
            '期末試験',
        ])) {
            return $this->result(
                self::SCHOOL_TEST,
                0.97,
                ['school_test_keyword'],
                $this->pointTarget(
                    $title.' '.$description,
                ),
            );
        }

        if (
            str_contains($category, '資格')
            || $this->containsAny($text, [
                '応用情報',
                '基本情報',
                '情報処理技術者',
                '簿記',
                '宅建',
                '行政書士',
                '社労士',
                '公認会計士',
                '資格試験',
                'certification',
            ])
            || preg_match(
                '/(?:^|\s)ap(?:\s|$)/u',
                $text,
            ) === 1
        ) {
            return $this->result(
                self::CERTIFICATION_EXAM,
                str_contains($category, '資格')
                    ? 0.94
                    : 0.90,
                str_contains($category, '資格')
                    ? ['qualification_category']
                    : ['certification_keyword'],
            );
        }

        if ($this->containsAny($text, [
            '暗記',
            '単語',
            '語彙',
            '漢字',
            '用語暗記',
            'フラッシュカード',
            'vocabulary',
            'memorization',
        ])) {
            return $this->result(
                self::MEMORIZATION,
                0.90,
                ['memorization_keyword'],
            );
        }

        if ($this->containsAny($text, [
            'python',
            'swift',
            'javascript',
            'プログラミング',
        ])) {
            return $this->result(
                self::SKILL_LEARNING,
                0.94,
                ['strong_skill_keyword'],
            );
        }

        if ($this->containsAny($text, [
            '習得',
            '身につける',
            '学ぶ',
            '英会話',
            '技能',
            'スキル',
        ])) {
            return $this->result(
                self::SKILL_LEARNING,
                0.76,
                ['generic_skill_keyword'],
            );
        }

        return $this->result(
            self::GENERAL_LEARNING,
            0.55,
            ['fallback'],
            $this->scoreTarget(
                $title.' '.$description,
            ),
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
        float $confidence,
        array $reasons,
        int|float|null $targetScore = null,
    ): array {
        return [
            'key' => $key,
            'label' => $this->options()[$key]['label'],
            'confidence' => $confidence,
            'reasons' => $reasons,
            'target_score' => $targetScore,
        ];
    }

    private function targetForType(
        string $type,
        Plan $plan,
    ): int|float|null {
        $text = (string) $plan->title.' '.
            (string) $plan->description;

        return match ($type) {
            self::SCORE_EXAM => $this->scoreTarget($text),
            self::SCHOOL_TEST => $this->pointTarget($text),
            default => null,
        };
    }

    private function normalize(string $value): string
    {
        $value = mb_convert_kana(
            mb_strtolower(trim($value)),
            'as',
            'UTF-8',
        );

        return preg_replace(
            '/[\s　]+/u',
            ' ',
            $value,
        ) ?? $value;
    }

    /**
     * @param array<int,string> $needles
     */
    private function containsAny(
        string $text,
        array $needles,
    ): bool {
        foreach ($needles as $needle) {
            if (
                str_contains(
                    $text,
                    $this->normalize($needle),
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function pointTarget(
        string $text,
    ): int|float|null {
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

    private function scoreTarget(
        string $text,
    ): int|float|null {
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
