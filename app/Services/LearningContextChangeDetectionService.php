<?php

namespace App\Services;

/**
 * Canovia-owned, deterministic comparison of TWO previously authorized,
 * actor/Plan-bound Learning Context Snapshot v1 captures.
 *
 * This is an internal decision-support kernel, NOT an HTTP/MCP entry point,
 * scheduler, persistent alert, prediction, AI evaluation, or notification.
 * A future caller must verify source revisions and permissions again before
 * showing anything to the user; currently no result is delivery-eligible.
 */
final class LearningContextChangeDetectionService
{
    public const SCHEMA = 'canovia.learning_context_change_detection.v0.1';
    private const SNAPSHOT = 'canovia.learning_context_snapshot.v1';

    /**
     * Inputs MUST be produced by trusted first-party code, never client JSON.
     * The first-party caller is responsible for verifying Plan owner and
     * source freshness before binding both captures.
     *
     * @param array<string,mixed> $previous
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    public function compare(array $previous, array $current): array
    {
        $owner = $this->positiveId($previous['actor_id'] ?? null);
        $plan = $this->positiveId($previous['plan_id'] ?? null);
        if ($owner === null || $plan === null
            || $owner !== $this->positiveId($current['actor_id'] ?? null)
            || $plan !== $this->positiveId($current['plan_id'] ?? null)
            || ($previous['snapshot'] ?? null) === null
            || ($current['snapshot'] ?? null) === null) {
            return $this->block('ACTOR_OR_PLAN_BINDING_INVALID');
        }

        $before = $this->project($previous['snapshot']);
        $after = $this->project($current['snapshot']);
        if ($before === null || $after === null) {
            return $this->block('SNAPSHOT_SHAPE_OR_PROVENANCE_UNTRUSTED');
        }
        if ($before['plan']['title'] !== $after['plan']['title']) {
            // Until source-revision lineage exists, a title change cannot be
            // independently distinguished from a cross-Plan projection.
            return $this->block('PLAN_IDENTITY_NOT_RECONCILED');
        }

        $beforeHash = $this->fingerprint($before);
        $afterHash = $this->fingerprint($after);
        $result = [
            'schema' => self::SCHEMA,
            'status' => 'unchanged',
            'delivery' => 'internal_review_only',
            'plan_bound' => true,
            'previous_fingerprint' => $beforeHash,
            'current_fingerprint' => $afterHash,
            'dedupe_fingerprint' => hash('sha256',
                $owner.':'.$plan.':'.$beforeHash.':'.$afterHash),
            'events' => [],
            'requires_source_revision_recheck' => true,
            'requires_owner_permission_recheck' => true,
            'notification_eligible' => false,
            'notification_sent' => false,
            'mutation_performed' => false,
            'certainty' => 'recorded_context_only_not_event_history',
        ];
        if (hash_equals($beforeHash, $afterHash)) {
            return $result;
        }

        // Internal review candidates use only changes that can be stated
        // without inventing practice chronology or claiming competence.
        $events = [];
        $oldDeadline = $before['plan']['deadline'];
        $newDeadline = $after['plan']['deadline'];
        if ($oldDeadline !== $newDeadline) {
            $events[] = [
                'kind' => 'owner_recorded_deadline_changed',
                'review_priority' => 'check',
                'previous_value' => $oldDeadline,
                'current_value' => $newDeadline,
                'basis' => 'recorded_plan_date_comparison',
                'caveat' => 'Owner-recorded date, not a verified examination schedule.',
            ];
        }

        // At most eight active Tasks are exposed, and completed Tasks disappear
        // from that window. Only compare the SAME existing Task ID; never
        // infer completion or deletion from absence.
        if (! $before['tasks']['truncated'] && ! $after['tasks']['truncated']) {
            $oldTasks = [];
            foreach ($before['tasks']['items'] as $task) {
                $oldTasks[$task['id']] = $task;
            }
            foreach ($after['tasks']['items'] as $task) {
                $old = $oldTasks[$task['id']] ?? null;
                if ($old !== null && $old['status'] !== 'paused'
                    && $task['status'] === 'paused') {
                    $events[] = [
                        'kind' => 'recorded_task_paused',
                        'review_priority' => 'check',
                        'task_id' => $task['id'],
                        'basis' => 'same_task_recorded_status_changed',
                        'caveat' => 'An owner-recorded status change, not proof of inactivity.',
                    ];
                    break;
                }
            }
        }

        $oldPractice = $before['practice'];
        $newPractice = $after['practice'];
        if (! $oldPractice['truncated'] && ! $newPractice['truncated']
            && $oldPractice['scored'] >= 3 && $newPractice['scored'] >= 3
            && $oldPractice['average'] !== null && $newPractice['average'] !== null
            && abs($newPractice['average'] - $oldPractice['average']) >= 15) {
            $events[] = [
                'kind' => 'recent_practice_sample_changed',
                'review_priority' => 'check',
                'previous_average_percent' => $oldPractice['average'],
                'current_average_percent' => $newPractice['average'],
                'basis' => 'two_bounded_recent_applied_samples',
                'caveat' => 'Sample composition may differ; NOT a measured learning trend.',
            ];
        }

        $oldTop = $before['weaknesses'][0]['topic'] ?? null;
        $newTop = $after['weaknesses'][0]['topic'] ?? null;
        if (! $oldPractice['truncated'] && ! $newPractice['truncated']
            && $oldPractice['considered'] >= 3 && $newPractice['considered'] >= 3
            && $oldTop !== null && $newTop !== null && $oldTop !== $newTop) {
            $events[] = [
                'kind' => 'recent_weakness_tag_priority_changed',
                'review_priority' => 'check',
                'previous_topic' => $oldTop,
                'current_topic' => $newTop,
                'basis' => 'most_frequent_tag_in_each_bounded_sample',
                'caveat' => 'Tag ranking changed; NOT evidence of mastery or deterioration.',
            ];
        }

        $result['status'] = $events === [] ? 'changed_no_action' : 'review_candidate';
        $result['events'] = array_slice($events, 0, 4);
        return $result;
    }

    /** @return array<string,mixed>|null */
    private function project(mixed $snapshot): ?array
    {
        if (! is_array($snapshot)
            || ($snapshot['schema'] ?? null) !== self::SNAPSHOT
            || ($snapshot['purpose'] ?? null) !== 'support_one_private_study_plan'
            || ($snapshot['delivery'] ?? null) !== 'ephemeral_explicitly_consented_read_only'
            || ($snapshot['sources']['raw_evidence_shared'] ?? null) !== false) {
            return null;
        }
        $facts = $snapshot['facts'] ?? null;
        $observed = $snapshot['observations'] ?? null;
        if (! is_array($facts) || ! is_array($observed)) {
            return null;
        }
        $plan = $facts['plan'] ?? null;
        $tasks = $facts['active_tasks'] ?? null;
        $practice = $facts['recent_practice'] ?? null;
        $tags = $observed['weakness_tags'] ?? null;
        if (! is_array($plan) || ! is_string($plan['title'] ?? null)
            || trim($plan['title']) === '' || mb_strlen($plan['title']) > 160
            || ! is_array($tasks) || ! is_array($tasks['items'] ?? null)
            || ! array_is_list($tasks['items']) || count($tasks['items']) > 8
            || ! is_bool($tasks['truncated'] ?? null)
            || ! is_array($practice) || ! is_bool($practice['truncated'] ?? null)
            || ! is_array($tags) || ! array_is_list($tags) || count($tags) > 5) {
            return null;
        }
        $deadline = $plan['owner_recorded_deadline'] ?? null;
        if ($deadline !== null && (!$this->calendarDate($deadline))) {
            return null;
        }
        $taskItems = [];
        $seen = [];
        foreach ($tasks['items'] as $task) {
            if (! is_array($task)) {
                return null;
            }
            $id = $this->positiveId($task['task_id'] ?? null);
            $title = $task['title'] ?? null;
            $status = $task['status'] ?? null;
            $progress = $task['recorded_progress_percent'] ?? null;
            if ($id === null || isset($seen[$id]) || ! is_string($title)
                || trim($title) === '' || mb_strlen($title) > 160
                || ! in_array($status, ['todo', 'doing', 'paused'], true)
                || ($progress !== null && (! is_int($progress) || $progress < 0 || $progress > 100))) {
                return null;
            }
            $seen[$id] = true;
            $taskItems[] = ['id' => $id, 'title' => $title, 'status' => $status, 'progress' => $progress];
        }
        // Ordering in the first 8 Tasks is a UI preference, not a material event.
        usort($taskItems, static fn ($a, $b) => $a['id'] <=> $b['id']);

        $considered = $practice['applied_attempts_considered'] ?? null;
        $scored = $practice['scored_attempts'] ?? null;
        $external = $practice['owner_approved_external_results_considered'] ?? null;
        $average = $practice['average_score_percent'] ?? null;
        $latest = $practice['latest_score_percent'] ?? null;
        if (! is_int($considered) || $considered < 0 || $considered > 8
            || ! is_int($scored) || $scored < 0 || $scored > $considered
            || ! is_int($external) || $external < 0 || $external > $considered
            || ($average !== null && (! is_int($average) || $average < 0 || $average > 100))
            || ($latest !== null && (! is_int($latest) || $latest < 0 || $latest > 100))
            || ($scored === 0 && ($average !== null || $latest !== null))) {
            return null;
        }
        $weaknesses = [];
        $topics = [];
        foreach ($tags as $tag) {
            if (! is_array($tag)
                || ! is_string($tag['topic'] ?? null)
                || trim($tag['topic']) === '' || mb_strlen($tag['topic']) > 48
                || ! is_int($tag['mentions_in_sample'] ?? null)
                || $tag['mentions_in_sample'] < 0 || $tag['mentions_in_sample'] > 8
                || ($tag['source'] ?? null) !== 'approved_practice_aggregate') {
                return null;
            }
            $key = mb_strtolower(trim($tag['topic']));
            if (isset($topics[$key])) {
                return null;
            }
            $topics[$key] = true;
            $weaknesses[] = ['topic' => $tag['topic'], 'mentions' => $tag['mentions_in_sample']];
        }

        return [
            'plan' => ['title' => $plan['title'], 'deadline' => $deadline],
            'tasks' => ['items' => $taskItems, 'truncated' => $tasks['truncated']],
            'practice' => [
                'considered' => $considered, 'scored' => $scored,
                'external' => $external, 'average' => $average,
                'latest' => $latest, 'truncated' => $practice['truncated'],
            ],
            'weaknesses' => $weaknesses,
        ];
    }

    private function calendarDate(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function positiveId(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    /** @param array<string,mixed> $data */
    private function fingerprint(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,mixed> */
    private function block(string $reason): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => 'blocked',
            'reason' => $reason,
            'delivery' => 'internal_review_only',
            'events' => [],
            'notification_eligible' => false,
            'notification_sent' => false,
            'mutation_performed' => false,
            'requires_source_revision_recheck' => true,
            'requires_owner_permission_recheck' => true,
        ];
    }
}
