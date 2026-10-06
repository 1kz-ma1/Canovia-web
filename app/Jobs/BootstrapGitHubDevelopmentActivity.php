<?php

namespace App\Jobs;

use App\Models\PlanArtifact;
use App\Services\GitHubDevelopmentObservationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BootstrapGitHubDevelopmentActivity implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 90;

    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(
        public readonly int $repositoryArtifactId,
    ) {}

    public function handle(
        GitHubDevelopmentObservationService $observations,
    ): void {
        $artifact = PlanArtifact::query()
            ->with('plan.user')
            ->find($this->repositoryArtifactId);

        if (! $artifact instanceof PlanArtifact) {
            return;
        }

        $observations->bootstrapRepository($artifact);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('GitHub development activity bootstrap failed.', [
            'repository_artifact_id' => $this->repositoryArtifactId,
            'exception' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);
    }
}
