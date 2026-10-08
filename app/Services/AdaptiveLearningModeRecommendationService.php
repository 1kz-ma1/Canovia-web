<?php

namespace App\Services;

use App\Models\LearningAnswerEvent;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Http\Request;

/**
 * Explainable and conservative v1, not a mastery estimator.
 * Evidence is a history of graded attempts, not an estimated pass rate.
 * Results use coarse multi-answer thresholds; single answers never flip rank.
 */
final class AdaptiveLearningModeRecommendationService
{
    public const VERSION = 'multi_answer_rules_v1';

    /**
     * @return array{version:string,ranking:list<array{mode:string,label:string,reason:string,available:bool}>,evidence:string,diagnostic_hint:string}
     */
    public function forPlanTask(Request $request, Plan $plan, Task $task,
        string $actorToken, bool $examAvailable): array
    {
        $q = LearningAnswerEvent::query()
            ->whereDoesntHave('evaluationAdjustment')
            ->whereHas('item.run', function ($runs) use ($request, $plan, $task, $actorToken) {
                $runs->where('plan_id', $plan->id)->where('task_id', $task->id);
                if ($request->user()) {
                    $runs->where('user_id', $request->user()->id);
                } else {
                    $runs->whereNull('user_id')->where('actor_token', $actorToken);
                }
            })
            ->with('item:id,learning_run_id')
            ->latest('id')->limit(30)->get();

        $sessions = $q->pluck('item.learning_run_id')->filter()->unique()->count();
        $recent = $q->take(6);
        $correct = $recent->filter(fn (LearningAnswerEvent $a) => $a->was_correct)->count();
        $count = $q->count();

        $legacy = \App\Models\StudyPracticeAttempt::query()
            ->where('plan_id', $plan->id)->where('task_id', $task->id);
        if ($request->user()) {
            $legacy->where('user_id', $request->user()->id);
        } else {
            $legacy->whereNull('user_id')->where('actor_token', $actorToken);
        }
        $legacyCount = $legacy->count();

        // Old whole-set scores prove historical engagement but cannot be
        // misrepresented as comparable individual new AnswerEvents.
        $hasStableBasis = $count >= max(4, (int) config('study.adaptive_learning.ranking_evidence_min', 6))
            && $sessions >= 2;
        $practicePreferred = $hasStableBasis && $correct >= 4;

        $understanding = [
            'mode' => 'understanding', 'label' => '理解モード',
            'available' => true,
            'reason' => $hasStableBasis && ! $practicePreferred
                ? '直近の複数回答を見直し、迷った理由や解説を確認する学習が候補です。単発の間違いを弱点と断定しません。'
                : '解説を確認しながら始められます。理解度が不確かな段階でも取り組みやすい方法です。',
        ];
        $practice = [
            'mode' => 'practice', 'label' => '演習モード',
            'available' => true,
            'reason' => $practicePreferred
                ? '複数セッションにまたがる回答履歴で正答が続いているため、テンポのよい演習で再確認する候補です。習熟を保証するものではありません。'
                : '短時間でも1問ずつ解けます。広い範囲を解きたい場合に選択できます。',
        ];
        $exam = [
            'mode' => 'exam', 'label' => '模擬試験モード',
            'available' => $examAvailable,
            'reason' => $examAvailable
                ? '検証済みの試験仕様と問題集が利用できます。時間内での再現性を確認したい場合に選択できます。'
                : '検証済みの試験プロファイルと対応問題集が未登録のため、現在は選択できません。',
        ];

        $ranking = $practicePreferred
            ? [$practice, $understanding, $exam]
            : [$understanding, $practice, $exam];

        $evidence = $count === 0
            ? ($legacyCount > 0
                ? '従来の学習履歴はありますが、新方式と単問比較できる証拠はまだ不足しています。'
                : 'Canovia内の回答履歴がまだありません。学習経験を決めつけず、任意のモードから始められます。')
            : "新方式の解答記録{$count}件・異なる学習Run{$sessions}件を参考にしています。採点結果を合格可能性に換算していません。";

        $diagnostic = $count >= 6 || $legacyCount >= 2
            ? '既存履歴があるため、最初から再診断する必要はありません。必要なら問題集を選んで確認できます。'
            : '既に学習経験があれば、現在地を確かめる問題集を任意で選べます。診断は必須ではありません。';

        return [
            'version' => self::VERSION,
            'ranking' => $ranking,
            'evidence' => $evidence,
            'diagnostic_hint' => $diagnostic,
        ];
    }
}
