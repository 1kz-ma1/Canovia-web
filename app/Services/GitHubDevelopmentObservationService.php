<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Models\DevelopmentActivityObservation;
use App\Models\GitHubWebhookDelivery;
use App\Models\PlanArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

final class GitHubDevelopmentObservationService
{
    public function __construct(
        private readonly GitHubRepositoryWriter $github,
        private readonly GitHubWorkflowService $workflow,
        private readonly FeatureAccessService $access,
        private readonly PlanCategoryProfileService $profiles,
    ) {}

    /** @return array{observed:int,plan_ids:array<int,int>} */
    public function observeDelivery(GitHubWebhookDelivery $delivery): array
    {
        $repo = trim((string) $delivery->repo_full_name);
        $installationId = (int) $delivery->installation_id;
        $targets = collect((array) $delivery->routing_targets)
            ->filter(fn ($target) => is_array($target))
            ->values();

        if ($repo === '' || $installationId <= 0 || $targets->isEmpty()) {
            return ['observed' => 0, 'plan_ids' => []];
        }

        $roots = $this->connectedRepositories($repo, $installationId);
        if ($roots->isEmpty()) {
            return ['observed' => 0, 'plan_ids' => []];
        }

        $observed = 0;
        $planIds = [];

        foreach ($targets as $target) {
            $fact = $this->factFromTarget($repo, $target);
            if ($fact === null) {
                continue;
            }

            foreach ($roots as $root) {
                $item = $this->persist($root, $repo, $fact);
                if ($item->wasRecentlyCreated || $item->wasChanged()) {
                    $observed++;
                }
                $planIds[(int) $root->plan_id] = true;
            }
        }

        return [
            'observed' => $observed,
            'plan_ids' => array_map('intval', array_keys($planIds)),
        ];
    }

    /** @return array{observed:int,plan_ids:array<int,int>} */
    public function bootstrapRepository(PlanArtifact $root): array
    {
        $root->loadMissing('plan.user');

        if (! $this->eligible($root)) {
            return ['observed' => 0, 'plan_ids' => []];
        }

        $parsed = $this->workflow->parseUrl((string) $root->url);
        $repo = trim((string) ($parsed['repo_full_name'] ?? ''));

        if ($repo === '') {
            return ['observed' => 0, 'plan_ids' => []];
        }

        $observed = 0;
        foreach ($this->github->bootstrapDevelopmentActivity($repo, 20) as $fact) {
            if (! is_array($fact)) {
                continue;
            }

            $item = $this->persist($root, $repo, $fact);
            if ($item->wasRecentlyCreated || $item->wasChanged()) {
                $observed++;
            }
        }

        return [
            'observed' => $observed,
            'plan_ids' => [(int) $root->plan_id],
        ];
    }

    /** @return Collection<int,PlanArtifact> */
    private function connectedRepositories(string $repo, int $installationId): Collection
    {
        return PlanArtifact::query()
            ->with('plan.user')
            ->where('provider', 'github')
            ->where('artifact_type', 'repository')
            ->get()
            ->filter(function (PlanArtifact $root) use ($repo, $installationId) {
                $parsed = $this->workflow->parseUrl((string) $root->url);

                return ($parsed['kind'] ?? null) === 'repository'
                    && strcasecmp((string) ($parsed['repo_full_name'] ?? ''), $repo) === 0
                    && data_get($root->metadata, 'github_app_connection.status') === 'connected'
                    && (int) data_get($root->metadata, 'github_app_connection.installation_id', 0) === $installationId
                    && $this->eligible($root);
            })
            ->unique(fn (PlanArtifact $root) => (int) $root->plan_id)
            ->values();
    }

    private function eligible(PlanArtifact $root): bool
    {
        $plan = $root->plan;

        return $plan
            && $root->provider === 'github'
            && $root->artifact_type === 'repository'
            && data_get($root->metadata, 'github_app_connection.status') === 'connected'
            && $this->profiles->forPlan($plan)->key === 'development'
            && $this->access->canUse(
                $plan->user,
                FeatureKey::DeveloperGithubEvidence,
                [
                    'plan_id' => (int) $plan->id,
                    'artifact_id' => (int) $root->id,
                    'source' => 'github_observation',
                ],
            );
    }

    /** @param array<string,mixed> $target */
    private function factFromTarget(string $repo, array $target): ?array
    {
        $kind = (string) ($target['kind'] ?? '');

        if ($kind === 'pull_request') {
            return $this->pullFact($this->github->inspectPullRequestReturn(
                $repo,
                (int) ($target['number'] ?? 0),
            ));
        }

        if ($kind === 'issue') {
            return $this->issueFact($this->github->inspectIssue(
                $repo,
                (int) ($target['number'] ?? 0),
            ));
        }

        if ($kind === 'branch') {
            return $this->branchFact($this->github->inspectBranch(
                $repo,
                (string) ($target['branch'] ?? ''),
            ));
        }

        if ($kind === 'push') {
            return $this->commitFact(
                $this->github->inspectCommit(
                    $repo,
                    (string) ($target['commit_sha'] ?? ''),
                ),
                (string) ($target['branch'] ?? ''),
            );
        }

        if ($kind === 'deployment') {
            return $this->deploymentFact($this->github->inspectDeployment(
                $repo,
                (int) ($target['deployment_id'] ?? 0),
            ));
        }

        return null;
    }

    private function pullFact(array $snapshot): array
    {
        $pull = (array) ($snapshot['pull_request'] ?? []);
        $number = (int) ($pull['number'] ?? 0);
        $state = (string) ($pull['state'] ?? '');

        return [
            'kind' => 'pull_request',
            'identity' => (string) $number,
            'provider_number' => $number,
            'title' => $pull['title'] ?? null,
            'state' => (bool) ($pull['merged'] ?? false)
                ? 'merged'
                : ((bool) ($pull['draft'] ?? false) && $state === 'open' ? 'draft' : $state),
            'ref' => $pull['head_ref'] ?? null,
            'sha' => $pull['head_sha'] ?? null,
            'url' => $pull['url'] ?? null,
            'occurred_at' => $pull['merged_at'] ?? $pull['closed_at'] ?? $pull['updated_at'] ?? $snapshot['fetched_at'] ?? null,
        ];
    }

    private function issueFact(array $snapshot): array
    {
        $issue = (array) ($snapshot['issue'] ?? []);
        $number = (int) ($issue['number'] ?? 0);

        return [
            'kind' => 'issue',
            'identity' => (string) $number,
            'provider_number' => $number,
            'state' => $issue['state'] ?? null,
            'url' => $issue['url'] ?? null,
            'occurred_at' => $issue['closed_at'] ?? $issue['updated_at'] ?? $snapshot['fetched_at'] ?? null,
        ];
    }

    private function branchFact(array $snapshot): array
    {
        $branch = (array) ($snapshot['branch'] ?? []);
        $name = trim((string) ($branch['name'] ?? ''));

        return [
            'kind' => 'branch',
            'identity' => $name,
            'state' => (bool) ($branch['protected'] ?? false) ? 'protected' : 'active',
            'ref' => $name,
            'sha' => $branch['head_sha'] ?? null,
            'occurred_at' => $snapshot['fetched_at'] ?? null,
        ];
    }

    private function commitFact(array $snapshot, string $branch): array
    {
        $commit = (array) ($snapshot['commit'] ?? []);
        $sha = trim((string) ($commit['sha'] ?? ''));

        return [
            'kind' => 'commit',
            'identity' => $sha,
            'state' => 'observed',
            'ref' => trim($branch) ?: null,
            'sha' => $sha,
            'url' => $commit['url'] ?? null,
            'occurred_at' => $commit['committed_at'] ?? $commit['authored_at'] ?? $snapshot['fetched_at'] ?? null,
        ];
    }

    private function deploymentFact(array $snapshot): array
    {
        $deployment = (array) ($snapshot['deployment'] ?? []);
        $id = (int) ($deployment['id'] ?? 0);

        return [
            'kind' => 'deployment',
            'identity' => (string) $id,
            'provider_number' => $id,
            'state' => $deployment['latest_status'] ?? 'created',
            'ref' => $deployment['ref'] ?? null,
            'sha' => $deployment['sha'] ?? null,
            'occurred_at' => $deployment['latest_status_at'] ?? $deployment['updated_at'] ?? $deployment['created_at'] ?? $snapshot['fetched_at'] ?? null,
        ];
    }

    /** @param array<string,mixed> $fact */
    private function persist(PlanArtifact $root, string $repo, array $fact): DevelopmentActivityObservation
    {
        $kind = trim((string) ($fact['kind'] ?? ''));
        $identity = trim((string) ($fact['identity'] ?? ''));

        if ($kind === '' || $identity === '') {
            throw new RuntimeException('GitHub Observation identityを確認できませんでした。');
        }

        $key = 'github:'.mb_strtolower($repo).':'.$kind.':'.mb_strtolower($identity);
        if (mb_strlen($key) > 191) {
            $key = 'github:sha256:'.hash('sha256', $key);
        }

        return DevelopmentActivityObservation::query()->updateOrCreate(
            [
                'plan_id' => (int) $root->plan_id,
                'provider' => 'github',
                'external_key' => $key,
            ],
            [
                'repository_artifact_id' => (int) $root->id,
                'kind' => $kind,
                'provider_number' => isset($fact['provider_number']) ? (int) $fact['provider_number'] : null,
                'url' => $this->url($fact['url'] ?? null),
                'title' => $this->text($fact['title'] ?? null, 255),
                'state' => $this->text($fact['state'] ?? null, 64),
                'ref' => $this->text($fact['ref'] ?? null, 255),
                'sha' => $this->sha($fact['sha'] ?? null),
                'occurred_at' => $this->date($fact['occurred_at'] ?? null),
                'last_observed_at' => now(),
            ],
        );
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function url(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== ''
            && mb_strlen($value) <= 2048
            && filter_var($value, FILTER_VALIDATE_URL)
                ? $value
                : null;
    }

    private function sha(mixed $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{7,64}$/', $value) ? $value : null;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
