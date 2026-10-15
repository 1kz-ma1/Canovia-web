<?php

namespace App\Services;

use App\Models\McpStudyResultProposal;
use App\Models\Plan;
use App\Models\StudyPracticeAttempt;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * First-party, actor-bound capture for the internal Learning change detector.
 * This is intentionally NOT an MCP/HTTP route or notification producer.
 *
 * Source revision fingerprints derive from real Plan/Task/applied-attempt rows.
 * No raw question/answer/assessment payload leaves this service.
 * Caller MUST re-check isCurrent() at the eventual decision/delivery boundary.
 */
final class LearningContextSourceCaptureService
{
    public const CAPTURE_SCHEMA = 'canovia.learning_context_source_capture.v0.2';
    private const LIMIT = 8;

    // Fingerprints must cover more than the 8 visible items: historical
    // corrections, deletions and withdrawals can invalidate prior context.
    // Do not silently truncate the source revision. Over budget, refuse the
    // capture until a scalable change-log/epoch protocol is available.
    private const MAX_TASK_SOURCE_ROWS = 256;
    private const MAX_PRACTICE_SOURCE_ROWS = 512;

    public function __construct(
        private readonly PlanCategoryProfileService $profiles,
    ) {}

    /** @return array<string,mixed>|null */
    public function capture(User $actor, int $planId): ?array
    {
        if ($actor->id === null || $planId < 1) {
            return null;
        }
        try {
            return DB::transaction(function () use ($actor, $planId): ?array {
                // Lock the owner Plan for this projection, but do not claim
                // this serializes independent writers that ignore that lock.
                $plan = Plan::query()->whereKey($planId)->lockForUpdate()->first();
                if ($plan === null
                    || $plan->user_id === null
                    || (int) $plan->user_id !== (int) $actor->id
                    || (bool) $plan->is_collaborative
                    || (bool) $plan->is_public
                    || $this->profiles->forPlan($plan)->key !== 'study'
                    || trim((string) $plan->title) === '') {
                    return null;
                }

                $tasksQuery = Task::query()->where('plan_id', $plan->id)
                    ->whereIn('status', ['doing', 'todo', 'paused']);
                $taskCount = (clone $tasksQuery)->count();
                if ($taskCount > self::MAX_TASK_SOURCE_ROWS) {
                    return null; // no partial task checksum
                }
                $taskRows = $tasksQuery->orderBy('id')
                    ->get(['id', 'title', 'status', 'progress_percent', 'updated_at']);
                $tasks = [];
                // Bounded full eligible task history; only 8 are presented.
                $taskSources = $taskRows->map(static fn (Task $task): array => [
                    (int) $task->id, (string) $task->title,
                    (string) $task->status, $task->progress_percent,
                    $task->updated_at?->toIso8601String(),
                ])->all();
                foreach ($taskRows->take(self::LIMIT) as $task) {
                    $progress = $task->progress_percent;
                    if ($progress !== null && (! is_int($progress) || $progress < 0 || $progress > 100)) {
                        return null;
                    }
                    if (trim((string) $task->title) === '') {
                        return null;
                    }
                    $tasks[] = [
                        // Internal only. This stable ID was deliberately
                        // excluded from the externally shared MCP Snapshot v1.
                        'task_id' => (int) $task->id,
                        'title' => mb_substr((string) $task->title, 0, 160),
                        'status' => (string) $task->status,
                        'recorded_progress_percent' => $progress,
                    ];
                }

                $nativeQ = StudyPracticeAttempt::query()
                    ->where('user_id', $actor->id)
                    ->where('plan_id', $plan->id)
                    ->whereNotNull('applied_at');
                $nativeCount = (clone $nativeQ)->count();

                $approvedQ = McpStudyResultProposal::query()
                    ->where('user_id', $actor->id)
                    ->where('plan_id', $plan->id)
                    ->where('status', McpStudyResultProposal::APPROVED)
                    ->whereNotNull('decided_at');
                $approvedCount = (clone $approvedQ)->count();
                if ($nativeCount + $approvedCount > self::MAX_PRACTICE_SOURCE_ROWS) {
                    return null; // fail closed rather than miss an old correction
                }
                $native = $nativeQ->orderByDesc('applied_at')->orderByDesc('id')
                    ->get(['id', 'score_percent', 'weaknesses', 'assessment',
                        'applied_at', 'updated_at']);
                $approved = $approvedQ->orderByDesc('decided_at')->orderByDesc('id')
                    ->get(['id', 'question_count', 'correct_count',
                        'weakness_topics', 'decided_at', 'updated_at']);

                // Full eligible history is read only within a strict cap.
                // The displayed interpretation remains the latest 8 records.
                // No raw question, answer or assessment enters the output.
                $candidateRows = [];
                foreach ($native as $row) {
                    $candidateRows[] = [
                        'source' => 'native',
                        'id' => (int) $row->id,
                        'at' => $row->applied_at?->getTimestamp() ?? 0,
                        'score' => $row->score_percent,
                        'labels' => $this->nativeWeaknesses($row),
                        'revision_fields' => [
                            $row->score_percent,
                            $row->weaknesses,
                            $row->assessment,
                            $row->applied_at?->toIso8601String(),
                            $row->updated_at?->toIso8601String(),
                        ],
                    ];
                }
                foreach ($approved as $row) {
                    $candidateRows[] = [
                        'source' => 'owner_approved_mcp',
                        'id' => (int) $row->id,
                        'at' => $row->decided_at?->getTimestamp() ?? 0,
                        'score' => $row->question_count > 0
                            ? (int) round(100 * $row->correct_count / $row->question_count)
                            : null,
                        'labels' => is_array($row->weakness_topics) ? $row->weakness_topics : [],
                        'revision_fields' => [
                            $row->question_count,
                            $row->correct_count,
                            $row->weakness_topics,
                            $row->decided_at?->toIso8601String(),
                            $row->updated_at?->toIso8601String(),
                        ],
                    ];
                }
                // Stable origin + primary-key ordering is essential: changing
                // an older timestamp must also invalidate the revision.
                $historyRevisionRows = array_map(static fn (array $row): array => [
                    $row['source'], $row['id'], $row['at'], $row['revision_fields'],
                ], $candidateRows);
                usort($historyRevisionRows, static fn (array $a, array $b): int =>
                    strcmp($a[0], $b[0]) ?: ($a[1] <=> $b[1]));

                usort($candidateRows, static function (array $a, array $b): int {
                    return ($b['at'] <=> $a['at'])
                        ?: strcmp($a['source'], $b['source'])
                        ?: ($b['id'] <=> $a['id']);
                });
                $selected = array_slice($candidateRows, 0, self::LIMIT);
                $scores = [];
                $tags = [];
                $revisionRows = [];
                $ownerApproved = 0;
                foreach ($selected as $row) {
                    $revisionRows[] = [
                        $row['source'], $row['id'], $row['at'], $row['revision_fields'],
                    ];
                    if ($row['source'] === 'owner_approved_mcp') {
                        ++$ownerApproved;
                    }
                    if (is_int($row['score']) && $row['score'] >= 0 && $row['score'] <= 100) {
                        $scores[] = $row['score'];
                    }
                    foreach (array_slice($row['labels'], 0, 16) as $label) {
                        if (! is_string($label)) {
                            continue;
                        }
                        $label = trim(preg_replace('/[\p{C}]+/u', ' ', $label) ?? '');
                        if ($label === '' || mb_strlen($label) > 48) {
                            continue;
                        }
                        $key = mb_strtolower($label);
                        if (! isset($tags[$key]) && count($tags) >= 16) {
                            continue;
                        }
                        if (! isset($tags[$key])) {
                            $tags[$key] = ['topic' => $label, 'mentions_in_sample' => 0,
                                'source' => 'approved_practice_aggregate'];
                        }
                        ++$tags[$key]['mentions_in_sample'];
                    }
                }
                $weaknessTags = array_values($tags);
                usort($weaknessTags, static fn (array $a, array $b): int =>
                    ($b['mentions_in_sample'] <=> $a['mentions_in_sample'])
                    ?: strcmp($a['topic'], $b['topic']));

                $deadline = $plan->deadline?->toDateString();
                $revision = hash('sha256', json_encode([
                    'version' => 3,
                    'actor_id' => (int) $actor->id,
                    'plan_id' => (int) $plan->id,
                    'plan_fields' => [(string) $plan->title, $deadline,
                        (string) $plan->category, $plan->workspace_domain_override,
                        (bool) $plan->is_collaborative, (bool) $plan->is_public,
                        $plan->updated_at?->toIso8601String()],
                    'tasks' => ['count' => $taskCount, 'all_eligible_rows' => $taskSources],
                    'practice' => [
                        'native_count' => $nativeCount,
                        'approved_count' => $approvedCount,
                        'all_eligible_rows' => $historyRevisionRows,
                        'selected' => $revisionRows,
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                // Internal shape is compatible with Change Detection v0.1,
                // without expanding the external ChatGPT consent/projection.
                return [
                    'schema' => self::CAPTURE_SCHEMA,
                    'actor_id' => (int) $actor->id,
                    'plan_id' => (int) $plan->id,
                    'source_revision' => $revision,
                    'snapshot' => [
                        'schema' => 'canovia.learning_context_snapshot.v1',
                        'delivery' => 'internal_owner_scoped_read_only',
                        'purpose' => 'support_one_private_study_plan',
                        'sources' => ['raw_evidence_shared' => false],
                        'facts' => [
                            'plan' => [
                                'title' => mb_substr((string) $plan->title, 0, 160),
                                'owner_recorded_deadline' => $deadline,
                            ],
                            'active_tasks' => [
                                'items' => $tasks,
                                'truncated' => $taskCount > self::LIMIT,
                            ],
                            'recent_practice' => [
                                'applied_attempts_considered' => count($selected),
                                'scored_attempts' => count($scores),
                                'owner_approved_external_results_considered' => $ownerApproved,
                                'average_score_percent' => $scores === []
                                    ? null : (int) round(array_sum($scores) / count($scores)),
                                'latest_score_percent' => $scores[0] ?? null,
                                'truncated' => ($nativeCount + $approvedCount) > self::LIMIT,
                            ],
                        ],
                        'observations' => [
                            'weakness_tags' => array_slice($weaknessTags, 0, 5),
                        ],
                    ],
                ];
            }, 3);
        } catch (Throwable) {
            // The conservative response to missing/corrupt source rows or a
            // read failure is "unavailable", never a partially stale capture.
            return null;
        }
    }

    /**
     * Check CURRENT access and actual source fingerprint; only valid at the
     * instant of verification, not a notification/send authorization.
     */
    public function isCurrent(User $actor, int $planId, ?string $expected): bool
    {
        if (! is_string($expected)
            || preg_match('/\A[a-f0-9]{64}\z/D', $expected) !== 1) {
            return false;
        }
        $capture = $this->capture($actor, $planId);
        return $capture !== null && hash_equals($expected, $capture['source_revision']);
    }

    /** @return list<string> */
    private function nativeWeaknesses(StudyPracticeAttempt $attempt): array
    {
        $feedback = data_get($attempt->assessment, 'question_feedback', []);
        $feedback = is_array($feedback) ? array_slice($feedback, 0, 30) : [];
        $structured = collect($feedback)->contains(static fn ($item) =>
            is_array($item) && (array_key_exists('correctness', $item)
                || array_key_exists('error_type', $item)));
        if (! $structured) {
            return is_array($attempt->weaknesses) ? $attempt->weaknesses : [];
        }

        $result = [];
        foreach ($feedback as $item) {
            if (! is_array($item)
                || ! in_array($item['correctness'] ?? null, ['incorrect', 'partial'], true)) {
                continue;
            }
            $labels = $item['weakness_topics'] ?? [];
            if (! is_array($labels) || $labels === []) {
                $labels = $item['misconceptions'] ?? [];
            }
            if (is_array($labels)) {
                array_push($result, ...array_slice($labels, 0, 8));
            }
        }
        return $result;
    }
}
