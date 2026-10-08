<?php

namespace App\Services;

/**
 * Original, bounded Bookkeeping Grade 3 foundation check. This is a placement
 * hypothesis, not an official Nissho pass prediction or Grade 2 gate.
 */
final class BookkeepingPlacementDiagnosticService
{
    public const SOURCE = 'Canovia簿記基礎診断';
    public const METRIC = 'bookkeeping_foundations_percent';

    public const TOPICS = [
        'basic_journal' => '基本仕訳',
        'receivables_payables' => '売掛金・買掛金',
        'cash_deposits' => '現金・預金',
        'adjusting_entries' => '決算整理',
    ];

    /**
     * @return array<int,array{id:string,topic:string,prompt:string,choices:array<string,string>,correct:string,explanation:string}>
     */
    public function questions(): array
    {
        return [
            $this->item('q01', 'basic_journal', '商品10,000円を現金で仕入れた（商品売買は三分法）。', [
                'A' => '借方：仕入 10,000 ／ 貸方：現金 10,000',
                'B' => '借方：現金 10,000 ／ 貸方：仕入 10,000',
                'C' => '借方：売上 10,000 ／ 貸方：現金 10,000',
                'D' => '借方：仕入 10,000 ／ 貸方：買掛金 10,000',
            ], 'A', '現金で商品を仕入れたので、仕入が借方・現金が貸方です。'),
            $this->item('q02', 'basic_journal', '商品20,000円を掛けで売り上げた（三分法）。', [
                'A' => '借方：売上 20,000 ／ 貸方：売掛金 20,000',
                'B' => '借方：売掛金 20,000 ／ 貸方：売上 20,000',
                'C' => '借方：買掛金 20,000 ／ 貸方：売上 20,000',
                'D' => '借方：現金 20,000 ／ 貸方：売上 20,000',
            ], 'B', '掛け売上では売掛金が増加し、売上が計上されます。'),
            $this->item('q03', 'basic_journal', '備品30,000円を現金で購入した。', [
                'A' => '借方：仕入 30,000 ／ 貸方：現金 30,000',
                'B' => '借方：現金 30,000 ／ 貸方：備品 30,000',
                'C' => '借方：備品 30,000 ／ 貸方：現金 30,000',
                'D' => '借方：消耗品費 30,000 ／ 貸方：買掛金 30,000',
            ], 'C', '備品は商品仕入ではなく資産の取得です。'),
            $this->item('q04', 'receivables_payables', '売掛金8,000円を現金で回収した。', [
                'A' => '借方：現金 8,000 ／ 貸方：売掛金 8,000',
                'B' => '借方：売掛金 8,000 ／ 貸方：現金 8,000',
                'C' => '借方：現金 8,000 ／ 貸方：売上 8,000',
                'D' => '借方：買掛金 8,000 ／ 貸方：現金 8,000',
            ], 'A', '売掛金の回収は売上の再計上ではありません。'),
            $this->item('q05', 'receivables_payables', '掛けで販売した商品5,000円が返品された（三分法）。', [
                'A' => '借方：仕入 5,000 ／ 貸方：買掛金 5,000',
                'B' => '借方：売上 5,000 ／ 貸方：売掛金 5,000',
                'C' => '借方：売掛金 5,000 ／ 貸方：売上 5,000',
                'D' => '借方：現金 5,000 ／ 貸方：売上 5,000',
            ], 'B', '売上返品では売上と売掛金を取り消します。'),
            $this->item('q06', 'receivables_payables', '商品12,000円を掛けで仕入れた（三分法）。', [
                'A' => '借方：買掛金 12,000 ／ 貸方：仕入 12,000',
                'B' => '借方：仕入 12,000 ／ 貸方：売掛金 12,000',
                'C' => '借方：仕入 12,000 ／ 貸方：買掛金 12,000',
                'D' => '借方：現金 12,000 ／ 貸方：仕入 12,000',
            ], 'C', '掛け仕入は買掛金の増加として計上します。'),
            $this->item('q07', 'cash_deposits', '普通預金から現金10,000円を引き出した。', [
                'A' => '借方：現金 10,000 ／ 貸方：普通預金 10,000',
                'B' => '借方：普通預金 10,000 ／ 貸方：現金 10,000',
                'C' => '借方：現金 10,000 ／ 貸方：売上 10,000',
                'D' => '借方：現金 10,000 ／ 貸方：借入金 10,000',
            ], 'A', '資産内で普通預金が減り、現金が増えます。'),
            $this->item('q08', 'cash_deposits', '預金利息500円が普通預金に入金された。', [
                'A' => '借方：受取利息 500 ／ 貸方：普通預金 500',
                'B' => '借方：普通預金 500 ／ 貸方：受取利息 500',
                'C' => '借方：現金 500 ／ 貸方：売上 500',
                'D' => '借方：普通預金 500 ／ 貸方：借入金 500',
            ], 'B', '預金（資産）が増加し、受取利息（収益）が発生します。'),
            $this->item('q09', 'cash_deposits', '手元の現金7,000円を普通預金に預け入れた。', [
                'A' => '借方：現金 7,000 ／ 貸方：普通預金 7,000',
                'B' => '借方：預り金 7,000 ／ 貸方：現金 7,000',
                'C' => '借方：普通預金 7,000 ／ 貸方：現金 7,000',
                'D' => '借方：普通預金 7,000 ／ 貸方：売上 7,000',
            ], 'C', '預金（資産）が増加し、現金（資産）が減少します。'),
            $this->item('q10', 'adjusting_entries', '決算時、当期に費用計上済みの保険料のうち次期分3,000円を繰り延べる。', [
                'A' => '借方：前払保険料 3,000 ／ 貸方：支払保険料 3,000',
                'B' => '借方：支払保険料 3,000 ／ 貸方：前払保険料 3,000',
                'C' => '借方：未払保険料 3,000 ／ 貸方：現金 3,000',
                'D' => '借方：保険料 3,000 ／ 貸方：普通預金 3,000',
            ], 'A', '次期に対応する費用を前払費用（資産）へ振り替えます。'),
            $this->item('q11', 'adjusting_entries', '備品の当期減価償却費4,000円を間接法で計上する。', [
                'A' => '借方：備品 4,000 ／ 貸方：減価償却費 4,000',
                'B' => '借方：減価償却費 4,000 ／ 貸方：備品減価償却累計額 4,000',
                'C' => '借方：備品減価償却累計額 4,000 ／ 貸方：減価償却費 4,000',
                'D' => '借方：減価償却費 4,000 ／ 貸方：現金 4,000',
            ], 'B', '間接法では備品を直接減らさず、減価償却累計額へ貸記します。'),
            $this->item('q12', 'adjusting_entries', '決算時、当期分の水道光熱費1,000円が未払いである。', [
                'A' => '借方：未払費用 1,000 ／ 貸方：水道光熱費 1,000',
                'B' => '借方：現金 1,000 ／ 貸方：水道光熱費 1,000',
                'C' => '借方：水道光熱費 1,000 ／ 貸方：未払費用 1,000',
                'D' => '借方：前払費用 1,000 ／ 貸方：現金 1,000',
            ], 'C', '当期発生した費用は未払いでも当期に計上します。'),
        ];
    }

    /**
     * @param array<string,string> $answers
     * @return array<string,mixed>
     */
    public function assess(array $answers, bool $wantsAdvance): array
    {
        $counts = array_fill_keys(array_keys(self::TOPICS), ['total' => 0, 'correct' => 0]);
        $feedback = [];
        $correct = 0;

        foreach ($this->questions() as $question) {
            $answer = $answers[$question['id']] ?? null;
            $isCorrect = $answer === $question['correct'];
            $correct += $isCorrect ? 1 : 0;
            $counts[$question['topic']]['total']++;
            $counts[$question['topic']]['correct'] += $isCorrect ? 1 : 0;
            $feedback[] = [
                'id' => $question['id'],
                'topic' => $question['topic'],
                'correct' => $isCorrect,
                'explanation' => $question['explanation'],
            ];
        }

        $scores = [];
        foreach ($counts as $topic => $values) {
            $scores[$topic] = (int) round(100 * $values['correct'] / max(1, $values['total']));
        }

        $scorePercent = (int) round(100 * $correct / count($this->questions()));
        $placement = $this->placement($scorePercent, $scores, $wantsAdvance);

        return [
            'version' => 1,
            'score_percent' => $scorePercent,
            'correct_count' => $correct,
            'total_count' => count($this->questions()),
            'topic_scores' => $scores,
            ...$placement,
            'wants_advance' => $wantsAdvance,
            'feedback' => $feedback,
            'disclaimer' => '12問の簡易確認です。合格可能性や3級全範囲の習熟を保証せず、2級への進学を制限するものではありません。',
        ];
    }

    /**
     * This policy can be re-evaluated on every revisit from the persisted
     * per-topic results, without a fixed Grade 3-before-Grade 2 sequence.
     *
     * @param array<string,mixed> $recordedScores
     * @return array{weak_topics:array<int,string>,status:string,message:string}
     */
    public function placement(int $scorePercent, array $recordedScores, bool $wantsAdvance): array
    {
        $scores = [];
        foreach (self::TOPICS as $key => $label) {
            $value = $recordedScores[$key] ?? null;
            if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
                return [
                    'weak_topics' => [],
                    'status' => 'diagnostic_needed',
                    'message' => '単元別の確認結果が不足しています。まず基礎診断で現在地を確かめましょう。',
                ];
            }
            $scores[$key] = (int) $value;
        }

        $weakTopics = array_keys(array_filter($scores, fn (int $score) => $score < 67));
        $status = $weakTopics !== []
            ? 'review_prerequisites'
            : ($scorePercent >= 83 && $wantsAdvance
                ? 'trial_next_grade'
                : 'review_and_verify');

        return [
            'weak_topics' => $weakTopics,
            'status' => $status,
            'message' => match ($status) {
                'review_prerequisites' => '3級範囲の弱い単元を先に短く復習しましょう。2級の先取りは、関連する基礎を確かめながら少しずつ進められます。',
                'trial_next_grade' => '今回の3級基礎は概ね安定しています。3級を定期的に復習しつつ、2級の導入単元を試す候補です。',
                default => '今回の範囲に大きな穴は見えませんが、定着はまだ未確認です。別の問題でも確認しながら復習を続けましょう。',
            ],
        ];
    }

    /** @return array{id:string,topic:string,prompt:string,choices:array<string,string>,correct:string,explanation:string} */
    private function item(
        string $id,
        string $topic,
        string $prompt,
        array $choices,
        string $correct,
        string $explanation,
    ): array {
        return compact('id', 'topic', 'prompt', 'choices', 'correct', 'explanation');
    }
}
