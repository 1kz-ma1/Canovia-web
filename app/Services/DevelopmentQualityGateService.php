<?php

namespace App\Services;

use App\Enums\EvidenceSource;
use App\Models\Task;
use App\Models\TaskEvidence;
use Illuminate\Validation\ValidationException;

final class DevelopmentQualityGateService
{
    public const GATES = [
        'verification',
        'spec_sync',
    ];

    /**
     * @return array<string,array<int,string>>
     */
    public function statuses(): array
    {
        return [
            'verification' => ['passed', 'failed'],
            'spec_sync' => ['passed', 'failed', 'not_required'],
        ];
    }

    public function __construct(
        private readonly TaskEvidenceService $evidence,
    ) {}

    public function confirm(
        Task $task,
        string $gate,
        string $status,
        string $requestId,
        ?int $userId = null,
        ?string $actorToken = null,
        ?string $targetSha = null,
        ?int $deploymentId = null,
    ): TaskEvidence {
        $gate = trim($gate);
        $status = trim($status);

        if (! in_array($gate, self::GATES, true)) {
            throw ValidationException::withMessages([
                'quality_gate' => '確認できる開発Quality Gateではありません。',
            ]);
        }

        $allowed = $this->statuses()[$gate] ?? [];
        if (! in_array($status, $allowed, true)) {
            throw ValidationException::withMessages([
                'gate_status' => 'このQuality Gateで選べる状態ではありません。',
            ]);
        }

        if (! preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $requestId,
        )) {
            throw ValidationException::withMessages([
                'confirmation_request_id' => '確認Request IDを確認できませんでした。',
            ]);
        }

        return $this->evidence->record(
            $task,
            EvidenceSource::Native,
            'development_quality_gate_confirmed',
            [
                'quality_gate' => $gate,
                'gate_status' => $status,
                'confirmation_source' => 'human',
                'target_sha' => filled($targetSha)
                    ? mb_strtolower(mb_substr(trim((string) $targetSha), 0, 64))
                    : null,
                'deployment_id' => $deploymentId && $deploymentId > 0
                    ? $deploymentId
                    : null,
            ],
            confidence: 0.9,
            externalKey: implode(':', [
                'development-quality-gate',
                (int) $task->id,
                $gate,
                strtolower($requestId),
            ]),
            userId: $userId,
            actorToken: $userId ? null : $actorToken,
            occurredAt: now(),
        );
    }
}
