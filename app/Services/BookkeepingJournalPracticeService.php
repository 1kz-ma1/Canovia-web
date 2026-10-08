<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Small original Grade 3 journal-entry set, with direct debit/credit input.
 * The score concerns only these problems; it is not an official qualification
 * score or proof that any other Grade 3 topic is mastered.
 */
final class BookkeepingJournalPracticeService
{
    public const METRIC = 'bookkeeping_journal_practice_percent';
    public const SOURCE = 'Canovia仕訳入力演習';

    public const ACCOUNTS = [
        '仕入', '現金', '売上', '売掛金', '買掛金', '普通預金',
        '備品', '未払金', '借入金', '支払利息',
        '減価償却費', '備品減価償却累計額',
    ];

    public function __construct(
        private readonly BookkeepingJournalEntryGrader $grader,
    ) {}

    /**
     * @return array<int,array{id:string,topic:string,prompt:string,explanation:string,expected:array}>
     */
    private function questionsWithAnswers(): array
    {
        return [
            [
                'id' => 'j01',
                'topic' => 'basic_journal',
                'prompt' => '商品10,000円を現金で仕入れた（三分法）。',
                'explanation' => '仕入（費用）が増加し、現金（資産）が減少します。',
                'expected' => [
                    ['side' => 'debit', 'account' => '仕入', 'amount' => 10000],
                    ['side' => 'credit', 'account' => '現金', 'amount' => 10000],
                ],
            ],
            [
                'id' => 'j02',
                'topic' => 'receivables_payables',
                'prompt' => '商品20,000円を売り上げ、8,000円を現金で受け取り、残額は掛けとした（三分法）。',
                'explanation' => '現金8,000円と売掛金12,000円が増加し、売上20,000円を計上します。',
                'expected' => [
                    ['side' => 'debit', 'account' => '現金', 'amount' => 8000],
                    ['side' => 'debit', 'account' => '売掛金', 'amount' => 12000],
                    ['side' => 'credit', 'account' => '売上', 'amount' => 20000],
                ],
            ],
            [
                'id' => 'j03',
                'topic' => 'loans_interest',
                'prompt' => '借入金20,000円と、その利息500円を合わせて現金で支払った。',
                'explanation' => '借入金の返済20,000円と支払利息500円を借方、現金20,500円を貸方に記録します。',
                'expected' => [
                    ['side' => 'debit', 'account' => '借入金', 'amount' => 20000],
                    ['side' => 'debit', 'account' => '支払利息', 'amount' => 500],
                    ['side' => 'credit', 'account' => '現金', 'amount' => 20500],
                ],
            ],
            [
                'id' => 'j04',
                'topic' => 'fixed_assets',
                'prompt' => '備品30,000円を購入し、10,000円を現金で支払い、残額は後払いとした。',
                'explanation' => '備品は商品ではないので買掛金ではなく未払金を使います。',
                'expected' => [
                    ['side' => 'debit', 'account' => '備品', 'amount' => 30000],
                    ['side' => 'credit', 'account' => '現金', 'amount' => 10000],
                    ['side' => 'credit', 'account' => '未払金', 'amount' => 20000],
                ],
            ],
        ];
    }

    /**
     * Public questions deliberately omit answer keys.
     *
     * @return array<int,array{id:string,topic:string,prompt:string}>
     */
    public function questions(): array
    {
        return array_map(
            static fn (array $question): array => [
                'id' => $question['id'],
                'topic' => $question['topic'],
                'prompt' => $question['prompt'],
            ],
            $this->questionsWithAnswers(),
        );
    }

    /**
     * @param array<string,mixed> $answers
     * @return array<string,mixed>
     */
    public function assess(array $answers): array
    {
        $details = [];
        $correct = 0;
        $topics = [];
        $errorTypes = [];

        foreach ($this->questionsWithAnswers() as $question) {
            $id = $question['id'];
            $raw = $answers[$id] ?? null;
            if (! is_array($raw)) {
                throw ValidationException::withMessages([
                    'answers.'.$id => 'この問題の仕訳を入力してください。',
                ]);
            }

            try {
                $graded = $this->grader->grade($raw, $question['expected'], self::ACCOUNTS);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    'answers.'.$id => '空欄以外の行は、借方・貸方、勘定科目、金額をすべて入力してください。金額は1以上の整数です。',
                ]);
            }

            $correct += $graded['correct'] ? 1 : 0;
            $topics[$question['topic']] = $graded['correct'] ? 100 : 0;
            if (! $graded['correct']) {
                $error = $graded['error_type'];
                $errorTypes[$error] = ($errorTypes[$error] ?? 0) + 1;
            }

            $details[] = [
                'id' => $id,
                'topic' => $question['topic'],
                'prompt' => $question['prompt'],
                'correct' => $graded['correct'],
                'error_type' => $graded['error_type'],
                'entered' => $graded['entries'],
                'expected' => $question['expected'],
                'explanation' => $question['explanation'],
            ];
        }

        $needsReview = array_values(array_keys(array_filter($topics, fn (int $score) => $score === 0)));
        $nextActions = $needsReview === []
            ? [
                '別の仕訳問題で解き方の定着を確認する',
                '複合仕訳の別パターンに進む',
            ]
            : array_map(
                fn (string $topic): string =>
                    ($this->topicLabels()[$topic] ?? $topic).'を復習し、別の仕訳で再確認する',
                $needsReview,
            );

        return [
            'version' => 1,
            'score_percent' => (int) round($correct * 100 / count($details)),
            'correct_count' => $correct,
            'total_count' => count($details),
            'topic_scores' => $topics,
            'weak_topics' => $needsReview,
            'error_types' => $errorTypes,
            'next_actions' => $nextActions,
            'details' => $details,
            'disclaimer' => 'Canoviaオリジナルの4問です。日商簿記の公式問題ではなく、3級全体・2級の習熟や合格可能性の判定には使いません。',
        ];
    }

    /** @return array<string,string> */
    public function topicLabels(): array
    {
        return [
            'basic_journal' => '基本仕訳',
            'receivables_payables' => '売掛金・複合仕訳',
            'loans_interest' => '借入金・支払利息',
            'fixed_assets' => '固定資産・未払金',
        ];
    }

    public function errorLabel(string $type): string
    {
        return match ($type) {
            'unbalanced' => '借方と貸方の金額が一致していません',
            'side_mismatch' => '借方と貸方の配置を確認しましょう',
            'account_mismatch' => '勘定科目の選択を確認しましょう',
            'amount_mismatch' => '科目ごとの金額を確認しましょう',
            default => '別の仕訳でも確認しましょう',
        };
    }
}
