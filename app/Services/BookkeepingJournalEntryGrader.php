<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Deterministic grading of a journal entry as a balanced multiset of
 * (debit/credit, account, amount) postings. Neither AI nor string similarity
 * decides accounting correctness.
 */
final class BookkeepingJournalEntryGrader
{
    public const MAX_LINES = 4;

    /**
     * @param array<int,array<string,mixed>> $rawEntries
     * @param array<int,array{side:string,account:string,amount:int}> $expected
     * @param array<int,string> $allowedAccounts
     * @return array{correct:bool,error_type:string,entries:array<int,array{side:string,account:string,amount:int}>}
     */
    public function grade(array $rawEntries, array $expected, array $allowedAccounts): array
    {
        if (count($rawEntries) > self::MAX_LINES) {
            throw ValidationException::withMessages(['entries' => '仕訳の行数が多すぎます。']);
        }

        $entries = [];
        foreach ($rawEntries as $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(['entries' => '仕訳の形式が正しくありません。']);
            }
            $side = $row['side'] ?? '';
            $account = $row['account'] ?? '';
            $amount = $row['amount'] ?? '';
            if ($side === '' && $account === '' && ($amount === '' || $amount === null)) {
                continue;
            }
            if (! is_string($side) || ! in_array($side, ['debit', 'credit'], true)
                || ! is_string($account) || ! in_array($account, $allowedAccounts, true)
                || ! is_scalar($amount) || ! preg_match('/^[0-9]{1,9}$/D', (string) $amount)
                || (int) $amount <= 0) {
                throw ValidationException::withMessages([
                    'entries' => '入力途中の行があります。借方・貸方、勘定科目、1以上の整数金額を確認してください。',
                ]);
            }
            $entries[] = ['side' => $side, 'account' => $account, 'amount' => (int) $amount];
        }

        if ($entries === []) {
            throw ValidationException::withMessages(['entries' => '少なくとも1行の仕訳を入力してください。']);
        }

        $actualMap = $this->aggregate($entries);
        $expectedMap = $this->aggregate($expected);
        if ($actualMap === $expectedMap) {
            return ['correct' => true, 'error_type' => 'none', 'entries' => $entries];
        }

        $debit = array_sum(array_column(array_filter($entries, fn (array $row) => $row['side'] === 'debit'), 'amount'));
        $credit = array_sum(array_column(array_filter($entries, fn (array $row) => $row['side'] === 'credit'), 'amount'));

        if ($debit !== $credit) {
            $errorType = 'unbalanced';
        } elseif ($this->aggregateByAccount($entries) === $this->aggregateByAccount($expected)) {
            $errorType = 'side_mismatch';
        } elseif ($this->accounts($entries) !== $this->accounts($expected)) {
            $errorType = 'account_mismatch';
        } else {
            $errorType = 'amount_mismatch';
        }

        return ['correct' => false, 'error_type' => $errorType, 'entries' => $entries];
    }

    /**
     * Normalize repeated rows on one side, so equivalent split journal entries
     * and differing display order receive the same grade.
     *
     * @param array<int,array{side:string,account:string,amount:int}> $entries
     * @return array<string,int>
     */
    private function aggregate(array $entries): array
    {
        $result = [];
        foreach ($entries as $row) {
            $key = $row['side'].'|'.$row['account'];
            $result[$key] = ($result[$key] ?? 0) + $row['amount'];
        }
        ksort($result);

        return $result;
    }

    /** @param array<int,array{side:string,account:string,amount:int}> $entries */
    private function aggregateByAccount(array $entries): array
    {
        $result = [];
        foreach ($entries as $row) {
            $result[$row['account']] = ($result[$row['account']] ?? 0) + $row['amount'];
        }
        ksort($result);

        return $result;
    }

    /** @param array<int,array{side:string,account:string,amount:int}> $entries */
    private function accounts(array $entries): array
    {
        $result = array_values(array_unique(array_column($entries, 'account')));
        sort($result);

        return $result;
    }
}
