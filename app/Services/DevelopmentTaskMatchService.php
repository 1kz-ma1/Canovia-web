<?php

namespace App\Services;

use App\Models\DevelopmentActivityObservation;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Support\Collection;

final class DevelopmentTaskMatchService
{
    public const MIN_CONFIDENCE = 0.72;
    public const MIN_GAP = 0.10;

    /**
     * Refresh deterministic suggestions for unresolved GitHub observations.
     *
     * No AI request is used and this method never links a Task automatically.
     *
     * @return Collection<int,DevelopmentActivityObservation>
     */
    public function refreshPlan(Plan $plan): Collection
    {
        $tasks = $plan->tasks()
            ->whereNotIn('status', ['done', 'cancelled'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'plan_id', 'title', 'status', 'sort_order']);

        $observations = DevelopmentActivityObservation::query()
            ->where('plan_id', $plan->id)
            ->whereIn('resolution_status', ['unlinked', 'suggested'])
            ->whereIn('kind', ['pull_request', 'issue', 'branch', 'commit'])
            ->latest('last_observed_at')
            ->latest('id')
            ->limit(50)
            ->get();

        foreach ($observations as $observation) {
            $this->refreshObservation($observation, $tasks);
        }

        return $observations->fresh([
            'suggestedTask:id,plan_id,title,status,sort_order',
            'repositoryArtifact:id,plan_id,title,url',
        ]);
    }

    /**
     * @param Collection<int,Task> $tasks
     */
    public function refreshObservation(
        DevelopmentActivityObservation $observation,
        Collection $tasks,
    ): DevelopmentActivityObservation {
        if (in_array($observation->resolution_status, ['linked', 'ignored'], true)) {
            return $observation;
        }

        $ranked = $tasks
            ->filter(fn (Task $task) =>
                (int) $task->plan_id === (int) $observation->plan_id
                && ! in_array($task->status, ['done', 'cancelled'], true)
            )
            ->map(fn (Task $task) => [
                'task' => $task,
                ...$this->score($observation, $task),
            ])
            ->sortByDesc('score')
            ->values();

        $top = $ranked->first();
        $second = $ranked->get(1);
        $topScore = (float) ($top['score'] ?? 0.0);
        $secondScore = (float) ($second['score'] ?? 0.0);
        $gap = $topScore - $secondScore;

        if (
            ! is_array($top)
            || $topScore < self::MIN_CONFIDENCE
            || $gap < self::MIN_GAP
        ) {
            $observation->update([
                'suggested_task_id' => null,
                'suggestion_confidence' => null,
                'suggestion_basis' => null,
                'resolution_status' => 'unlinked',
            ]);

            return $observation->refresh();
        }

        /** @var Task $task */
        $task = $top['task'];
        $observation->update([
            'suggested_task_id' => (int) $task->id,
            'suggestion_confidence' => round($topScore, 4),
            'suggestion_basis' => [
                'version' => 1,
                'method' => (string) ($top['method'] ?? 'deterministic'),
                'source' => (string) ($top['source'] ?? 'observation'),
                'similarity' => round((float) ($top['similarity'] ?? 0.0), 4),
                'candidate_score' => round($topScore, 4),
                'second_score' => round($secondScore, 4),
                'gap' => round($gap, 4),
            ],
            'resolution_status' => 'suggested',
        ]);

        return $observation->refresh();
    }

    /**
     * @return array{score:float,method:string,source:string,similarity:float}
     */
    private function score(
        DevelopmentActivityObservation $observation,
        Task $task,
    ): array {
        $title = trim((string) $observation->title);
        $ref = trim((string) $observation->ref);
        $taskTitle = trim((string) $task->title);
        $source = trim($title.' '.$ref);

        if ($this->explicitTaskId($source) === (int) $task->id) {
            return [
                'score' => 1.0,
                'method' => 'explicit_task_id',
                'source' => $ref !== '' ? 'title_ref' : 'title',
                'similarity' => 1.0,
            ];
        }

        $taskCompact = $this->compact($taskTitle);
        $titleCompact = $this->compact($title);
        $refCompact = $this->compact($ref);

        if (
            mb_strlen($taskCompact) >= 4
            && (
                ($titleCompact !== '' && str_contains($titleCompact, $taskCompact))
                || ($refCompact !== '' && str_contains($refCompact, $taskCompact))
            )
        ) {
            return [
                'score' => $refCompact !== '' && str_contains($refCompact, $taskCompact)
                    ? 0.95
                    : 0.92,
                'method' => 'normalized_containment',
                'source' => $refCompact !== '' && str_contains($refCompact, $taskCompact)
                    ? 'ref'
                    : 'title',
                'similarity' => 1.0,
            ];
        }

        $titleSimilarity = $this->dice($taskCompact, $titleCompact);
        $refSimilarity = $this->dice($taskCompact, $refCompact);
        $similarity = max($titleSimilarity, $refSimilarity);
        $bestSource = $refSimilarity > $titleSimilarity ? 'ref' : 'title';

        if ($similarity < 0.68) {
            return [
                'score' => 0.0,
                'method' => 'no_strong_match',
                'source' => $bestSource,
                'similarity' => $similarity,
            ];
        }

        return [
            'score' => min(0.91, 0.72 + (($similarity - 0.68) * 0.60)),
            'method' => 'character_ngram_similarity',
            'source' => $bestSource,
            'similarity' => $similarity,
        ];
    }

    private function explicitTaskId(string $value): ?int
    {
        if (! preg_match(
            '/(?:canovia[-_ ]*)?task(?:[-_ #:]*)?(\d{1,10})/iu',
            $value,
            $matches,
        )) {
            return null;
        }

        $id = (int) ($matches[1] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function compact(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';

        return mb_substr($value, 0, 512);
    }

    private function dice(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        $leftNgrams = $this->ngrams($left);
        $rightNgrams = $this->ngrams($right);

        if ($leftNgrams === [] || $rightNgrams === []) {
            return 0.0;
        }

        $leftCounts = array_count_values($leftNgrams);
        $rightCounts = array_count_values($rightNgrams);
        $intersection = 0;

        foreach ($leftCounts as $gram => $count) {
            $intersection += min($count, $rightCounts[$gram] ?? 0);
        }

        return (2 * $intersection) / (count($leftNgrams) + count($rightNgrams));
    }

    /** @return array<int,string> */
    private function ngrams(string $value): array
    {
        $length = mb_strlen($value);
        if ($length <= 2) {
            return [$value];
        }

        $size = $length >= 8 ? 3 : 2;
        $grams = [];

        for ($i = 0; $i <= $length - $size; $i++) {
            $grams[] = mb_substr($value, $i, $size);
        }

        return $grams;
    }
}
