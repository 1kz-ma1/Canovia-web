<?php

namespace App\Intelligence\Development;

use App\Intelligence\Contracts\StateBuilder;
use App\Intelligence\Data\EvidenceObservation;
use App\Intelligence\Data\StateSnapshot;
use App\Intelligence\Enums\IntelligenceDomain;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class DevelopmentStateBuilder implements StateBuilder
{
    public const GATES = [
        'implementation',
        'ci',
        'review',
        'merge',
        'deploy',
        'verification',
        'spec_sync',
    ];

    public function build(
        IntelligenceDomain $domain,
        array $context,
        iterable $evidence,
    ): StateSnapshot {
        if ($domain !== IntelligenceDomain::Development) {
            throw new InvalidArgumentException(
                'DevelopmentStateBuilder only supports the development domain.',
            );
        }

        $observations = collect($evidence)
            ->filter(fn ($item) => $item instanceof EvidenceObservation)
            ->sort(function (
                EvidenceObservation $left,
                EvidenceObservation $right,
            ): int {
                $time = $left->occurredAt <=> $right->occurredAt;

                return $time !== 0
                    ? $time
                    : strcmp($left->reference, $right->reference);
            })
            ->values();

        $taskStates = $observations
            ->filter(fn (EvidenceObservation $item) =>
                (int) ($item->facts['task_id'] ?? 0) > 0
            )
            ->groupBy(fn (EvidenceObservation $item) =>
                (int) $item->facts['task_id']
            )
            ->map(fn (Collection $items, int|string $taskId) =>
                $this->taskState((int) $taskId, $items)
            )
            ->values()
            ->sort(function (array $left, array $right): int {
                $score = ((int) $right['focus_rank'])
                    <=> ((int) $left['focus_rank']);

                if ($score !== 0) {
                    return $score;
                }

                $recent = ((int) $right['latest_evidence_ts'])
                    <=> ((int) $left['latest_evidence_ts']);

                if ($recent !== 0) {
                    return $recent;
                }

                return ((int) $left['task_id']) <=> ((int) $right['task_id']);
            })
            ->values();

        $focus = $taskStates->first();
        $focusGates = is_array($focus)
            ? (array) ($focus['gates'] ?? [])
            : [];

        $passCount = collect(self::GATES)
            ->filter(fn (string $gate) =>
                ($focusGates[$gate]['status'] ?? 'unknown') === 'passed'
            )
            ->count();
        $failedCount = collect(self::GATES)
            ->filter(fn (string $gate) =>
                ($focusGates[$gate]['status'] ?? 'unknown') === 'failed'
            )
            ->count();
        $unknownCount = collect(self::GATES)
            ->filter(fn (string $gate) =>
                ($focusGates[$gate]['status'] ?? 'unknown') === 'unknown'
            )
            ->count();

        $capturedAt = $this->capturedAt($context['captured_at'] ?? null);

        return new StateSnapshot(
            domain: IntelligenceDomain::Development,
            scopeType: trim((string) ($context['scope_type'] ?? 'development_plan'))
                ?: 'development_plan',
            scopeId: $context['scope_id'] ?? null,
            capturedAt: $capturedAt,
            metrics: [
                'tracked_task_count' => $taskStates->count(),
                'development_evidence_count' => $observations->count(),
                'focus_task_id' => is_array($focus)
                    ? (int) $focus['task_id']
                    : null,
                'release_gate_pass_count' => $passCount,
                'release_gate_failed_count' => $failedCount,
                'release_gate_unknown_count' => $unknownCount,
                'release_gate_coverage_percent' => is_array($focus)
                    ? (int) round(($passCount / count(self::GATES)) * 100)
                    : null,
            ],
            facts: [
                'development_intelligence_version' => '53.8',
                'quality_gate_policy' => 'software_release_v1',
                'focus_task_id' => is_array($focus)
                    ? (int) $focus['task_id']
                    : null,
                'focus_task_state' => $focus,
                'task_states' => $taskStates
                    ->take(24)
                    ->values()
                    ->all(),
            ],
            evidenceReferences: $observations
                ->flatMap(fn (EvidenceObservation $item) => [
                    $item->reference,
                    ...$item->references,
                ])
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
        );
    }

    /**
     * @param Collection<int,EvidenceObservation> $items
     * @return array<string,mixed>
     */
    private function taskState(int $taskId, Collection $items): array
    {
        $gates = collect(self::GATES)
            ->mapWithKeys(fn (string $gate) => [
                $gate => [
                    'status' => 'unknown',
                    'source_type' => null,
                    'occurred_at' => null,
                ],
            ])
            ->all();

        $facts = [
            'task_id' => $taskId,
            'issue_number' => null,
            'issue_state' => null,
            'pull_request_number' => null,
            'pull_request_state' => null,
            'pull_request_draft' => false,
            'head_sha' => null,
            'branch' => null,
            'deployment_id' => null,
            'deployment_sha' => null,
            'deployment_environment' => null,
            'deployment_production' => false,
            'deployment_stale' => false,
            'verification_stale' => false,
            'spec_sync_stale' => false,
            'spec_sync_not_required' => false,
        ];

        $latestTs = 0;
        $reviewObserved = false;
        $reviewDecisions = [];
        $manualGateConfirmations = [];
        $currentImplementationSha = null;

        $observeImplementationSha = function (
            mixed $value,
            bool $mergeTransition = false,
        ) use (
            &$currentImplementationSha,
            &$facts,
            &$gates,
            &$reviewDecisions,
            &$reviewObserved,
        ): void {
            $sha = mb_strtolower(trim((string) $value));

            if (! preg_match('/^[a-f0-9]{7,64}$/', $sha)) {
                return;
            }

            if (
                $currentImplementationSha !== null
                && ! $this->sameSha($currentImplementationSha, $sha)
            ) {
                if ($mergeTransition) {
                    foreach (['deploy', 'verification', 'spec_sync'] as $gate) {
                        $gates[$gate] = $this->gate(
                            'unknown',
                            null,
                            null,
                        );
                    }
                } else {
                    foreach (
                        ['ci', 'review', 'merge', 'deploy', 'verification', 'spec_sync']
                        as $gate
                    ) {
                        $gates[$gate] = $this->gate(
                            'unknown',
                            null,
                            null,
                        );
                    }

                    $reviewDecisions = [];
                    $reviewObserved = false;
                }
            }

            $currentImplementationSha = $sha;
            $facts['head_sha'] = $sha;
        };

        foreach ($items as $item) {
            $latestTs = max($latestTs, $item->occurredAt->getTimestamp());
            $occurredAt = $item->occurredAt->format(DATE_ATOM);

            switch ($item->type) {
                case 'github_issue_observed':
                    $facts['issue_number'] = $item->facts['issue_number'] ?? null;
                    $facts['issue_state'] = $item->facts['issue_state'] ?? null;
                    break;

                case 'github_branch_observed':
                    $facts['branch'] = $item->facts['branch'] ?? $facts['branch'];
                    $observeImplementationSha(
                        $item->facts['head_sha'] ?? null,
                    );

                    if ($gates['implementation']['status'] === 'unknown') {
                        $gates['implementation'] = $this->gate(
                            'pending',
                            $item->type,
                            $occurredAt,
                        );
                    }
                    break;

                case 'github_commit_observed':
                    $facts['branch'] = $item->facts['branch'] ?? $facts['branch'];
                    $observeImplementationSha(
                        $item->facts['commit_sha'] ?? null,
                    );
                    $gates['implementation'] = $this->gate(
                        'passed',
                        $item->type,
                        $occurredAt,
                    );
                    break;

                case 'pull_request_observed':
                    $facts['pull_request_number'] = $item->facts['pull_request_number'] ?? null;
                    $facts['pull_request_state'] = $item->facts['pull_request_state'] ?? null;
                    $facts['pull_request_draft'] = (bool) ($item->facts['draft'] ?? false);
                    $observeImplementationSha(
                        $item->facts['head_sha'] ?? null,
                    );
                    $facts['branch'] = $item->facts['head_ref'] ?? $facts['branch'];

                    $gates['implementation'] = $this->gate(
                        'passed',
                        $item->type,
                        $occurredAt,
                    );

                    if ((bool) ($item->facts['merged'] ?? false)) {
                        $gates['merge'] = $this->gate(
                            'passed',
                            $item->type,
                            $occurredAt,
                        );
                    } elseif (($item->facts['pull_request_state'] ?? null) === 'closed') {
                        $gates['merge'] = $this->gate(
                            'failed',
                            $item->type,
                            $occurredAt,
                        );
                    } else {
                        $gates['merge'] = $this->gate(
                            'pending',
                            $item->type,
                            $occurredAt,
                        );
                    }
                    break;

                case 'pull_request_review_submitted':
                    $reviewObserved = true;
                    $state = strtoupper((string) ($item->facts['review_state'] ?? ''));
                    $reviewKey = (string) (
                        $item->facts['reviewer_key']
                        ?? ('review:'.(int) ($item->facts['review_id'] ?? 0))
                    );

                    if ($state === 'DISMISSED') {
                        unset($reviewDecisions[$reviewKey]);
                        break;
                    }

                    if (in_array($state, ['APPROVED', 'CHANGES_REQUESTED'], true)) {
                        $reviewDecisions[$reviewKey] = [
                            'state' => $state,
                            'occurred_at' => $occurredAt,
                        ];
                    }
                    break;

                case 'pull_request_ci_observed':
                    $observeImplementationSha(
                        $item->facts['head_sha'] ?? null,
                    );
                    $ci = strtolower((string) ($item->facts['ci_state'] ?? ''));
                    $gates['ci'] = $this->gate(
                        match ($ci) {
                            'success' => 'passed',
                            'failure' => 'failed',
                            'pending' => 'pending',
                            default => 'unknown',
                        },
                        $item->type,
                        $occurredAt,
                    );
                    break;

                case 'pull_request_merged':
                    $gates['implementation'] = $this->gate(
                        'passed',
                        $item->type,
                        $occurredAt,
                    );
                    $gates['merge'] = $this->gate(
                        'passed',
                        $item->type,
                        $occurredAt,
                    );
                    $facts['pull_request_number'] = $item->facts['pull_request_number'] ?? $facts['pull_request_number'];
                    $observeImplementationSha(
                        $item->facts['merge_commit_sha']
                            ?? $item->facts['head_sha']
                            ?? null,
                        true,
                    );
                    break;

                case 'github_deployment_observed':
                    $deploymentStatus = strtolower((string) (
                        $item->facts['deployment_status'] ?? ''
                    ));
                    $production = (bool) (
                        $item->facts['production_environment'] ?? false
                    );

                    $facts['deployment_id'] = $item->facts['deployment_id'] ?? null;
                    $facts['deployment_sha'] = $item->facts['deployment_sha'] ?? null;
                    $facts['deployment_environment'] = $item->facts['environment'] ?? null;
                    $facts['deployment_production'] = $production;

                    $gates['deploy'] = $this->gate(
                        match (true) {
                            in_array($deploymentStatus, ['failure', 'error'], true) => 'failed',
                            $deploymentStatus === 'success' && $production => 'passed',
                            $deploymentStatus !== '' => 'pending',
                            default => 'unknown',
                        },
                        $item->type,
                        $occurredAt,
                    );
                    break;

                case 'development_quality_gate_confirmed':
                    $gate = (string) ($item->facts['quality_gate'] ?? '');
                    $status = (string) ($item->facts['gate_status'] ?? '');

                    if (! in_array($gate, ['verification', 'spec_sync'], true)) {
                        break;
                    }

                    if (! in_array($status, ['passed', 'failed', 'not_required'], true)) {
                        break;
                    }

                    if ($gate === 'verification' && $status === 'not_required') {
                        break;
                    }

                    $manualGateConfirmations[$gate] = [
                        'status' => $status,
                        'occurred_at' => $occurredAt,
                        'target_sha' => mb_strtolower(trim((string) (
                            $item->facts['target_sha'] ?? ''
                        ))),
                        'deployment_id' => (int) (
                            $item->facts['deployment_id'] ?? 0
                        ),
                    ];
                    break;
            }
        }

        if ($reviewDecisions !== []) {
            $changeRequests = collect($reviewDecisions)
                ->where('state', 'CHANGES_REQUESTED');

            if ($changeRequests->isNotEmpty()) {
                $latest = $changeRequests
                    ->sortByDesc('occurred_at')
                    ->first();

                $gates['review'] = $this->gate(
                    'failed',
                    'pull_request_review_submitted',
                    is_array($latest) ? ($latest['occurred_at'] ?? null) : null,
                );
            } else {
                $approvals = collect($reviewDecisions)
                    ->where('state', 'APPROVED');
                $latest = $approvals
                    ->sortByDesc('occurred_at')
                    ->first();

                $gates['review'] = $this->gate(
                    'passed',
                    'pull_request_review_submitted',
                    is_array($latest) ? ($latest['occurred_at'] ?? null) : null,
                );
            }
        } elseif ($reviewObserved) {
            $gates['review'] = $this->gate(
                'pending',
                'pull_request_review_submitted',
                null,
            );
        }

        $deploymentSha = mb_strtolower(trim((string) (
            $facts['deployment_sha'] ?? ''
        )));
        $headSha = mb_strtolower(trim((string) (
            $facts['head_sha'] ?? ''
        )));

        if (
            ($gates['deploy']['status'] ?? 'unknown') !== 'unknown'
            && $deploymentSha !== ''
            && $headSha !== ''
            && ! $this->sameSha($deploymentSha, $headSha)
        ) {
            $facts['deployment_stale'] = true;
            $gates['deploy'] = $this->gate(
                'pending',
                'github_deployment_observed',
                $gates['deploy']['occurred_at'] ?? null,
            );
        }

        $verification = $manualGateConfirmations['verification'] ?? null;
        if (is_array($verification)) {
            $validVerification = ($gates['deploy']['status'] ?? null) === 'passed'
                && (int) ($facts['deployment_id'] ?? 0) > 0
                && (int) ($verification['deployment_id'] ?? 0)
                    === (int) ($facts['deployment_id'] ?? 0)
                && $deploymentSha !== ''
                && $this->sameSha(
                    (string) ($verification['target_sha'] ?? ''),
                    $deploymentSha,
                );

            if ($validVerification) {
                $gates['verification'] = $this->gate(
                    (string) $verification['status'],
                    'development_quality_gate_confirmed',
                    $verification['occurred_at'] ?? null,
                );
            } else {
                $facts['verification_stale'] = true;
                $gates['verification'] = $this->gate(
                    'pending',
                    'development_quality_gate_confirmed',
                    $verification['occurred_at'] ?? null,
                );
            }
        }

        $specSync = $manualGateConfirmations['spec_sync'] ?? null;
        if (is_array($specSync)) {
            $validSpecSync = ($gates['implementation']['status'] ?? null) === 'passed'
                && $headSha !== ''
                && $this->sameSha(
                    (string) ($specSync['target_sha'] ?? ''),
                    $headSha,
                );

            if ($validSpecSync) {
                $status = (string) ($specSync['status'] ?? '');
                $gates['spec_sync'] = $this->gate(
                    $status === 'not_required' ? 'passed' : $status,
                    'development_quality_gate_confirmed',
                    $specSync['occurred_at'] ?? null,
                );
                $facts['spec_sync_not_required'] = $status === 'not_required';
            } else {
                $facts['spec_sync_stale'] = true;
                $gates['spec_sync'] = $this->gate(
                    'pending',
                    'development_quality_gate_confirmed',
                    $specSync['occurred_at'] ?? null,
                );
            }
        }

        $failed = collect(self::GATES)
            ->contains(fn (string $gate) =>
                ($gates[$gate]['status'] ?? 'unknown') === 'failed'
            );

        $rank = match (true) {
            $failed => 1000,
            ($gates['deploy']['status'] ?? null) === 'passed' => 900,
            ($gates['merge']['status'] ?? null) === 'passed' => 800,
            ($gates['ci']['status'] ?? null) === 'passed' => 700,
            ($gates['review']['status'] ?? null) === 'passed' => 600,
            $facts['pull_request_number'] !== null => 500,
            ($gates['implementation']['status'] ?? null) === 'passed' => 400,
            ($gates['implementation']['status'] ?? null) === 'pending' => 300,
            $facts['issue_number'] !== null => 200,
            default => 100,
        };

        return [
            ...$facts,
            'gates' => $gates,
            'evidence_count' => $items->count(),
            'latest_evidence_ts' => $latestTs,
            'latest_evidence_at' => $latestTs > 0
                ? (new DateTimeImmutable('@'.$latestTs))->format(DATE_ATOM)
                : null,
            'focus_rank' => $rank,
        ];
    }

    /**
     * @return array{status:string,source_type:?string,occurred_at:?string}
     */
    private function gate(
        string $status,
        ?string $sourceType,
        ?string $occurredAt,
    ): array {
        return [
            'status' => $status,
            'source_type' => $sourceType,
            'occurred_at' => $occurredAt,
        ];
    }

    private function sameSha(string $left, string $right): bool
    {
        $left = mb_strtolower(trim($left));
        $right = mb_strtolower(trim($right));

        return $left !== ''
            && $right !== ''
            && (
                hash_equals($left, $right)
                || str_starts_with($left, $right)
                || str_starts_with($right, $left)
            );
    }

    private function capturedAt(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value) && trim($value) !== '') {
            return new DateTimeImmutable($value);
        }

        return new DateTimeImmutable();
    }
}
