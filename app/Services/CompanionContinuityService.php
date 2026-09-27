<?php

namespace App\Services;

use App\Models\CompanionMessage;
use App\Models\CompanionMutationCandidate;
use App\Models\CompanionThread;
use Carbon\Carbon;

class CompanionContinuityService
{
    public const KIND_PENDING_CANDIDATE = 'pending_candidate';
    public const KIND_NEW_EVIDENCE = 'new_evidence';
    public const KIND_KNOWN_UNKNOWN = 'known_unknown';
    public const KIND_NEXT_ACTION = 'next_action_clarification';

    /**
     * Build unresolved continuation points from current Canovia state.
     * Nothing is persisted here; resolving the source state makes the signal disappear.
     *
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    public function signals(CompanionThread $thread, array $context): array
    {
        $signals = [];

        $pendingCandidates = CompanionMutationCandidate::query()
            ->where('companion_thread_id', $thread->id)
            ->where('status', CompanionMutationCandidate::STATUS_PENDING)
            ->latest('id')
            ->get();

        if ($pendingCandidates->isNotEmpty()) {
            $candidate = $pendingCandidates->first();
            $count = $pendingCandidates->count();

            $signals[] = [
                'key' => 'pending_candidate:'.$candidate->id,
                'kind' => self::KIND_PENDING_CANDIDATE,
                'priority' => 100,
                'title' => $count > 1
                    ? '確認待ちの変更候補が'.$count.'件あります'
                    : '確認待ちの変更候補があります',
                'summary' => trim((string) $candidate->title)
                    ?: '前の会話で作った変更候補を確認できます。',
                'action' => 'review',
                'anchor' => 'companion-candidate-'.$candidate->id,
                'candidate_id' => (int) $candidate->id,
            ];
        }

        $lastAssistant = CompanionMessage::query()
            ->where('companion_thread_id', $thread->id)
            ->where('role', 'assistant')
            ->latest('id')
            ->first();

        if ($lastAssistant) {
            $freshEvidence = collect(data_get($context, 'recent_evidence', []))
                ->first(function ($evidence) use ($lastAssistant) {
                    $occurredAt = data_get($evidence, 'occurred_at');
                    if (! is_string($occurredAt) || trim($occurredAt) === '') {
                        return false;
                    }

                    try {
                        return Carbon::parse($occurredAt)->gt($lastAssistant->created_at);
                    } catch (\Throwable) {
                        return false;
                    }
                });

            if (is_array($freshEvidence)) {
                $summary = trim((string) data_get($freshEvidence, 'summary'));
                $signals[] = [
                    'key' => 'new_evidence:'.sha1(
                        (string) data_get($freshEvidence, 'type')
                        .'|'.(string) data_get($freshEvidence, 'occurred_at')
                        .'|'.$summary
                    ),
                    'kind' => self::KIND_NEW_EVIDENCE,
                    'priority' => 90,
                    'title' => '前回の会話後に新しいEvidenceがあります',
                    'summary' => $summary !== ''
                        ? $summary
                        : '前回の会話以降にTaskのEvidenceが追加されています。',
                    'action' => 'ask',
                    'suggested_message' => '前回の会話後に新しいEvidenceが増えました。これを踏まえてPlanやTaskの見直しが必要か整理して。変更が必要なら、直接反映せずCandidateとして提案してください。',
                    'evidence_type' => data_get($freshEvidence, 'type'),
                    'occurred_at' => data_get($freshEvidence, 'occurred_at'),
                ];
            }
        }

        $unknown = collect(data_get($context, 'goal_context.known_unknowns', []))->first();
        if (is_array($unknown)) {
            $label = trim((string) data_get($unknown, 'label'));
            $key = trim((string) data_get($unknown, 'key'));

            $signals[] = [
                'key' => 'known_unknown:'.($key !== '' ? $key : sha1($label)),
                'kind' => self::KIND_KNOWN_UNKNOWN,
                'priority' => 70,
                'title' => 'まだ確認できていないことがあります',
                'summary' => $label !== '' ? $label : 'Goal Contextに未確認項目があります。',
                'action' => 'ask',
                'suggested_message' => 'Goal Contextでまだ不明な「'.($label !== '' ? $label : '未確認項目').'」について、推測で埋めず、次にどう確認・観測すればよいか整理して。',
                'fact_key' => $key !== '' ? $key : null,
            ];
        }

        $taskStatus = (string) data_get($context, 'task.status', '');
        $nextAction = trim((string) data_get($context, 'task.next_action_note', ''));

        if (
            is_array(data_get($context, 'task'))
            && ! in_array($taskStatus, ['done', 'cancelled'], true)
            && $nextAction === ''
        ) {
            $signals[] = [
                'key' => 'next_action_clarification',
                'kind' => self::KIND_NEXT_ACTION,
                'priority' => 60,
                'title' => '次のActionがまだ明確ではありません',
                'summary' => '現在のGoal Context・Task・Evidenceから、次に実行しやすい一歩を整理できます。',
                'action' => 'ask',
                'suggested_message' => 'このTaskの次のActionがまだ明確ではありません。現在のGoal Context・Task・Evidenceから、次に実行しやすい一歩を整理して。Taskの変更が必要ならCandidateとして提案してください。',
            ];
        }

        return collect($signals)
            ->sortByDesc('priority')
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * Keep AI continuity context factual and compact.
     * UI-specific action text is intentionally excluded.
     *
     * @param array<int,array<string,mixed>> $signals
     * @return array<int,array<string,mixed>>
     */
    public function promptContext(array $signals): array
    {
        return collect($signals)
            ->map(fn (array $signal) => [
                'kind' => $signal['kind'] ?? null,
                'title' => $signal['title'] ?? null,
                'summary' => $signal['summary'] ?? null,
            ])
            ->values()
            ->all();
    }
}
