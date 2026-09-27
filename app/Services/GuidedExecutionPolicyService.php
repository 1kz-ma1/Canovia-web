<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Task;

class GuidedExecutionPolicyService
{
    /**
     * Guided Execution is for actions Canovia cannot perform itself but whose
     * outcome can be observed/reflected on afterwards.
     *
     * This is deliberately conservative: an unclassified desk/focus Task keeps
     * Timer as Primary, while Guided Execution remains available as a secondary
     * tool.
     *
     * @return array{recommended:bool,score:int,reason:string}
     */
    public function assess(Plan $plan, Task $task): array
    {
        $text = mb_strtolower(trim(
            $plan->title.' '.($plan->category ?? '').' '.$task->title.' '.($task->description ?? '').' '.($task->next_action_note ?? '')
        ));

        $realWorld = preg_match(
            '/練習|トレーニング|実践|実戦|試合|商談|営業|接客|顧客|訪問|面接|面談|電話|架電|交渉|提案|プレゼン|発表|会議|ミーティング|ロープレ|試乗|運動|筋トレ|ジム|ランニング|ジョギング|ウォーキング|撮影|演奏|ライブ|料理|掃除|片付け|買い物|手続き|応募|提出|会う|話す|聞く|相談する|指導|コーチング/u',
            $text,
        ) === 1;

        $focusWork = preg_match(
            '/実装|修正|開発|コード|コーディング|デバッグ|調査|リサーチ|読む|読書|資料作成|文書|レポート|執筆|設計書|整理する|考える|検討|学習|勉強|演習|問題|暗記|復習/u',
            $text,
        ) === 1;

        if ($realWorld) {
            return [
                'recommended' => true,
                'score' => 90,
                'reason' => 'Canovia外で実行し、結果や気づきを振り返るTaskなので、時間計測より事前方針とReflectionが適しています。',
            ];
        }

        if ($focusWork) {
            return [
                'recommended' => false,
                'score' => 25,
                'reason' => '集中して進める作業として扱えるため、PrimaryはTimer fallbackを維持します。',
            ];
        }

        return [
            'recommended' => false,
            'score' => 45,
            'reason' => '実行前後の振り返りは利用できますが、現時点ではTimerより優先する根拠が十分ではありません。',
        ];
    }
}
