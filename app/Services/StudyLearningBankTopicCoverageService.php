<?php

namespace App\Services;

use App\Models\LearningRunItem;
use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionPack;
use Illuminate\Http\Request;

/**
 * V6.19: observed exposure vs available tagged Bank questions.
 * A sample of published Question Banks is NOT the official IPA syllabus or
 * an attested future exam distribution. Never infer weakness from no record.
 */
final class StudyLearningBankTopicCoverageService
{
    private const PACK_LIMIT = 16;
    private const QUESTION_LIMIT = 1200;
    private const TOPIC_LIMIT = 8;

    public function __construct(
        private readonly QuestionPackPublicationReadinessService $readiness,
        private readonly AdaptiveLearningBankQueueService $queue,
        private readonly AdaptiveExamProfileRegistry $profiles,
        private readonly StudyPracticeExamProfileService $examProfile,
    ) {}

    /** @return array<string,mixed> */
    public function forWorkspace(Request $request, Plan $plan, ?string $actorToken,
        bool $canEdit): array
    {
        $empty = [
            'available' => false, 'rows' => [], 'pack_limit' => self::PACK_LIMIT,
            'topic_limit' => self::TOPIC_LIMIT, 'source_question_total' => 0,
            'eligible_pack_count' => 0, 'blocked_pack_count' => 0,
            'unattested_pack_count' => 0, 'question_limit_reached' => false,
            'has_more_topics' => false, 'scope_confirmed' => false,
            'exam_question_count' => null,
        ];
        if (! $canEdit || (! $request->user() && ! filled($actorToken))) return $empty;

        // Only an AP-like Plan, never an explicitly Subject B Plan. If the
        // Plan says AP but not Subject A the page makes scope uncertainty
        // explicit, rather than silently marking Subject A as confirmed.
        $planIdentity = mb_strtolower((string) $plan->title.' '.(string) $plan->description);
        $isAp = str_contains($planIdentity, '応用情報')
            || preg_match('/(?:^|[^a-z])ap(?:$|[^a-z])/u', $planIdentity) === 1;
        $isBOnly = preg_match('/科目\s*b|科目b|午後/u', $planIdentity) === 1
            && preg_match('/科目\s*a|科目a|午前/u', $planIdentity) !== 1;
        if (! $isAp || $isBOnly) return $empty;

        $tasks = $plan->relationLoaded('tasks') ? $plan->tasks : $plan->tasks()->get();
        $scopeConfirmed = $tasks->contains(fn ($task) =>
            ($this->examProfile->forPlanTask($plan, $task)['key'] ?? null)
                === 'ap_subject_a_exam');
        $verified = $this->profiles->available()->first(fn (array $profile) =>
            $profile['exam_code'] === 'AP' && $profile['subject'] === '科目A');

        $packs = QuestionPack::query()
            ->where('status', 'published')->where('exam_code', 'AP')
            ->where('subject', '科目A')->orderBy('id')
            ->limit(self::PACK_LIMIT + 1)->get();
        $morePacks = $packs->count() > self::PACK_LIMIT;
        $topics = [];
        $questionIds = [];
        $eligiblePacks = 0;
        $blocked = 0;
        $unattested = 0;
        $truncated = false;
        foreach ($packs->take(self::PACK_LIMIT) as $pack) {
            $pack->load(['questions' => fn ($q) => $q->where('is_active', true)]);
            $check = $this->readiness->inspect($pack);
            if (! $check['publishable']) {
                $blocked++;
                continue;
            }
            $eligiblePacks++;
            // Preflight checks field completeness, NOT independent human signoff.
            $unattested++;
            foreach ($pack->questions as $question) {
                if (! $this->queue->isSupported($question)) continue;
                if (count($questionIds) >= self::QUESTION_LIMIT) {
                    $truncated = true;
                    break 2;
                }
                $id = (int) $question->id;
                $questionIds[$id] = true;
                $meta = is_array($question->learning_metadata) ? $question->learning_metadata : [];
                $labels = array_merge(
                    is_array($meta['concepts'] ?? null) ? $meta['concepts'] : [],
                    is_array($meta['weakness_targets'] ?? null) ? $meta['weakness_targets'] : [],
                );
                foreach (collect($labels)->filter(fn ($x) =>
                        is_string($x) && trim($x) !== '' && mb_strlen(trim($x)) <= 50)
                    ->map(fn ($x) => trim($x))->unique(fn ($x) => mb_strtolower($x))
                    ->take(4) as $label) {
                    $key = mb_strtolower($label);
                    $topics[$key] ??= ['label' => $label, 'question_ids' => []];
                    $topics[$key]['question_ids'][$id] = true;
                }
            }
        }

        $seen = [];
        if ($questionIds !== []) {
            // Complete source exposure check for the selected Bank IDs:
            // exams and excluded-grade answers still mean the source was seen.
            $query = LearningRunItem::query()->whereIn('question_id', array_keys($questionIds))
                ->whereHas('answer')
                ->whereHas('run', function ($q) use ($plan, $request, $actorToken) {
                    $q->where('plan_id', $plan->id);
                    if ($request->user()) {
                        $q->where('user_id', $request->user()->id);
                    } else {
                        $q->whereNull('user_id')->where('actor_token', $actorToken);
                    }
                });
            $seen = array_fill_keys($query->distinct()->pluck('question_id')
                ->map(fn ($id) => (int) $id)->all(), true);
        }

        $rows = [];
        foreach ($topics as $group) {
            $ids = array_keys($group['question_ids']);
            $observed = count(array_filter($ids, fn ($id) => isset($seen[$id])));
            $unseen = count($ids) - $observed;
            $rows[] = [
                'topic' => $group['label'],
                'available_questions' => count($ids),
                'answered_source_questions' => $observed,
                'unanswered_source_questions' => $unseen,
                'state' => $observed === 0 ? 'no_record'
                    : ($unseen === 0 ? 'seen_all' : 'partial_record'),
                // This describes the tagged Bank slice only. A multi-tagged
                // Question may contribute to more than one topic.
                'sample_percent' => count($questionIds) > 0
                    ? (int) round(100 * count($ids) / count($questionIds)) : null,
            ];
        }
        // Show unobserved eligible Bank topics before previously encountered
        // topics; then larger unexposed availability, then fixed label.
        usort($rows, static fn (array $a, array $b): int =>
            (($a['answered_source_questions'] > 0 ? 1 : 0)
                <=> ($b['answered_source_questions'] > 0 ? 1 : 0))
            ?: ($b['unanswered_source_questions'] <=> $a['unanswered_source_questions'])
            ?: strcmp($a['topic'], $b['topic']));

        return [
            ...$empty,
            'available' => true,
            'rows' => array_slice($rows, 0, self::TOPIC_LIMIT),
            'has_more_topics' => count($rows) > self::TOPIC_LIMIT || $morePacks || $truncated,
            'source_question_total' => count($questionIds),
            'eligible_pack_count' => $eligiblePacks,
            'blocked_pack_count' => $blocked,
            'unattested_pack_count' => $unattested,
            'question_limit_reached' => $truncated,
            'scope_confirmed' => $scopeConfirmed,
            'exam_question_count' => is_array($verified)
                ? (int) $verified['question_count'] : null,
        ];
    }
}
