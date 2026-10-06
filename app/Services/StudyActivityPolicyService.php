<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;

class StudyActivityPolicyService
{
    public const QUESTION_PRACTICE = 'question_practice';
    public const RECALL = 'recall';
    public const RESOURCE_STUDY = 'resource_study';
    public const LISTENING = 'listening';
    public const DICTATION = 'dictation';
    public const SHADOWING = 'shadowing';

    /**
     * A qualification Plan can contain administrative Tasks (booking, applying,
     * publishing, etc.). Those Tasks should stay executable, but they should not
     * be presented as if AI practice / recall / resource study were a fit.
     */
    public function supportsTask(Task $task): bool
    {
        $context = mb_strtolower(implode(' ', [
            (string) $task->title,
            (string) ($task->description ?? ''),
            (string) ($task->next_action_note ?? ''),
        ]));

        return $this->containsAny($context, [
            '演習',
            '問題',
            '過去問',
            '模試',
            '模擬試験',
            '計算',
            '理解確認',
            '確認問題',
            '復習',
            '誤答',
            '曖昧',
            '解く',
            '採点',
            '対策',
            'sql',
            'ネットワーク',
            'データベース',
            '科目a',
            '科目b',
            '単語',
            '語彙',
            '熟語',
            '暗記',
            '記憶',
            '覚える',
            'フラッシュカード',
            'flashcard',
            'スペル',
            '参考書',
            '教科書',
            '教材',
            '解説',
            '動画',
            '講義',
            'インプット',
            '読む',
            '学習',
            '勉強',
            '練習',
            'リスニング',
            'listening',
            '聴解',
            '聞き取り',
            '聞き取る',
            '音声を聞く',
            'ディクテーション',
            'dictation',
            '書き取り',
            '聞き取って書く',
            '音声を書き取る',
            'シャドーイング',
            'shadowing',
            '音声に続いて',
            '復唱',
            '発音練習',
        ]);
    }

    /**
     * Decide the learning activity from the actual Task, not merely from the
     * fact that the Plan belongs to the qualification-study category.
     *
     * @return array<string,mixed>
     */
    public function forPlanTask(Plan $plan, Task $task): array
    {
        $context = mb_strtolower(implode(' ', [
            (string) $plan->title,
            (string) ($plan->description ?? ''),
            (string) $task->title,
            (string) ($task->description ?? ''),
            (string) ($task->next_action_note ?? ''),
        ]));

        $scores = [
            self::QUESTION_PRACTICE => 58,
            self::RECALL => 35,
            self::RESOURCE_STUDY => 35,
            self::LISTENING => 20,
            self::DICTATION => 20,
            self::SHADOWING => 20,
        ];

        $scores[self::QUESTION_PRACTICE] += $this->termScore($context, [
            '演習' => 22,
            '問題' => 18,
            '過去問' => 28,
            '模試' => 28,
            '模擬試験' => 28,
            '計算' => 18,
            '理解確認' => 22,
            '確認問題' => 22,
            'sql' => 16,
            'ネットワーク' => 10,
            'データベース' => 10,
            '科目a' => 10,
            'part 5' => 18,
            'part5' => 18,
            '長文問題' => 18,
        ]);

        $scores[self::RECALL] += $this->termScore($context, [
            '英単語' => 38,
            '単語' => 32,
            '語彙' => 30,
            'vocabulary' => 32,
            '熟語' => 28,
            '暗記' => 26,
            '記憶' => 22,
            '覚える' => 24,
            'フラッシュカード' => 36,
            'flashcard' => 36,
            'スペル' => 20,
        ]);

        $scores[self::RESOURCE_STUDY] += $this->termScore($context, [
            '参考書' => 32,
            '教科書' => 32,
            '教材を読む' => 36,
            '解説を読む' => 34,
            '動画を見る' => 34,
            '講義' => 26,
            'インプット' => 24,
            '章を読む' => 30,
            'テキストを読む' => 34,
            '資料を読む' => 34,
        ]);

        $scores[self::LISTENING] += $this->termScore($context, [
            'リスニング' => 55,
            'listening' => 55,
            '聴解' => 50,
            '聞き取り' => 45,
            '聞き取る' => 45,
            '音声を聞く' => 40,
            '耳で理解' => 35,
            '聞く練習' => 32,
        ]);

        $scores[self::DICTATION] += $this->termScore($context, [
            'ディクテーション' => 65,
            'dictation' => 65,
            '書き取り' => 58,
            '聞き取って書く' => 65,
            '音声を書き取る' => 65,
            '聞こえた英文を書く' => 58,
        ]);

        $scores[self::SHADOWING] += $this->termScore($context, [
            'シャドーイング' => 68,
            'shadowing' => 68,
            '音声に続いて' => 58,
            '復唱' => 45,
            '発音練習' => 40,
            '音声をまねる' => 48,
        ]);

        // A direct practice intent wins over incidental memorization words such
        // as "暗記問題". Conversely, a pure TOEIC vocabulary Task should not
        // be pulled into AI question generation just because the Plan is a test.
        if ($this->containsAny($context, ['過去問', '模試', '模擬試験', '演習問題', '問題演習'])) {
            $scores[self::QUESTION_PRACTICE] += 25;
        }

        if ($this->containsAny($context, ['ディクテーション', 'dictation', '聞き取って書く', '音声を書き取る'])) {
            $scores[self::DICTATION] += 20;
            $scores[self::QUESTION_PRACTICE] -= 10;
        }

        if ($this->containsAny($context, ['シャドーイング', 'shadowing', '音声に続いて'])) {
            $scores[self::SHADOWING] += 20;
            $scores[self::QUESTION_PRACTICE] -= 10;
        }

        if (
            $this->containsAny($context, ['リスニング', 'listening', '聴解', '聞き取り', '音声を聞く'])
            && ! $this->containsAny($context, ['過去問', '模試', '模擬試験', '演習問題', '問題演習'])
        ) {
            $scores[self::LISTENING] += 15;
            $scores[self::QUESTION_PRACTICE] -= 10;
        }

        if (
            str_contains($context, 'toeic')
            && $this->containsAny($context, ['単語', '語彙', 'vocabulary', '熟語'])
            && ! $this->containsAny($context, ['part 5', 'part5', '問題演習', '模試'])
        ) {
            $scores[self::RECALL] += 25;
            $scores[self::QUESTION_PRACTICE] -= 15;
        }

        $scores = collect($scores)
            ->map(fn (int $score) => max(20, min(100, $score)))
            ->all();

        $definitions = [
            self::QUESTION_PRACTICE => [
                'label' => 'Question Practice',
                'short_label' => '問題演習',
                'icon' => '✦',
                'description' => '問題を解き、採点とフィードバックから理解の穴を確認します。',
                'action_label' => '問題演習で進める',
            ],
            self::RECALL => [
                'label' => 'Recall',
                'short_label' => '記憶・想起',
                'icon' => '◉',
                'description' => '答えを見ずに思い出す反復を中心に、語彙や用語を定着させます。',
                'action_label' => '記憶学習で進める',
            ],
            self::RESOURCE_STUDY => [
                'label' => 'Resource Study',
                'short_label' => '教材学習',
                'icon' => '⌘',
                'description' => '参考書・教材・解説から知識を入れ、必要な箇所だけ後で演習します。',
                'action_label' => '教材学習で進める',
            ],
            self::LISTENING => [
                'label' => 'Listening',
                'short_label' => 'リスニング',
                'icon' => '◌',
                'description' => '答えや字幕を見る前に音声を聞き、意味と聞き取れない箇所を切り分けます。',
                'action_label' => 'リスニングで進める',
            ],
            self::DICTATION => [
                'label' => 'Dictation',
                'short_label' => 'ディクテーション',
                'icon' => '✎',
                'description' => '短い音声を書き取り、聞き落とした音・語・境界を教材と比較します。',
                'action_label' => 'ディクテーションで進める',
            ],
            self::SHADOWING => [
                'label' => 'Shadowing',
                'short_label' => 'シャドーイング',
                'icon' => '≈',
                'description' => '音声の直後を追って発話し、リズム・強勢・音のつながりを反復します。',
                'action_label' => 'シャドーイングで進める',
            ],
        ];

        $ranked = collect($scores)
            ->map(fn (int $score, string $key) => array_merge($definitions[$key], [
                'key' => $key,
                'fit_score' => $score,
                'fit_label' => $this->fitLabel($score),
            ]))
            ->sortByDesc('fit_score')
            ->values();

        $primary = $ranked->first();

        return [
            'version' => 'v1',
            'primary' => array_merge($primary, [
                'reason' => $this->reason($primary['key'], $plan, $task),
            ]),
            'alternatives' => $ranked->skip(1)->values()->all(),
            'all' => $ranked->all(),
        ];
    }

    private function reason(string $key, Plan $plan, Task $task): string
    {
        return match ($key) {
            self::RECALL => 'このTaskは語彙・用語を「思い出せる状態」にする比重が高いため、問題生成より想起反復を優先します。',
            self::RESOURCE_STUDY => 'このTaskは新しい知識を入れる工程が中心なので、先に教材から理解を作り、その後に必要なら演習する方が合っています。',
            self::LISTENING => 'このTaskは音声を聞いて意味を取ること自体が中心なので、問題生成よりListening反復を優先します。',
            self::DICTATION => 'このTaskは聞こえた音を文字へ変換し、聞き落としを特定することが中心なので、Dictationを優先します。',
            self::SHADOWING => 'このTaskは音声のリズム・強勢・音のつながりを追って再現することが中心なので、Shadowingを優先します。',
            default => 'このTaskは問題を解いて理解・判断を確認する工程が中心なので、Question Practiceが最も合っています。',
        };
    }

    /**
     * @param array<string,int> $terms
     */
    private function termScore(string $context, array $terms): int
    {
        $score = 0;
        foreach ($terms as $term => $weight) {
            if (str_contains($context, mb_strtolower($term))) {
                $score += $weight;
            }
        }

        return $score;
    }

    /**
     * @param array<int,string> $terms
     */
    private function containsAny(string $context, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($context, mb_strtolower($term))) {
                return true;
            }
        }

        return false;
    }

    private function fitLabel(int $score): string
    {
        return match (true) {
            $score >= 85 => 'かなり高い',
            $score >= 70 => '高い',
            $score >= 55 => '中',
            default => '低い',
        };
    }
}
