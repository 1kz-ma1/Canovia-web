<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\DevelopmentCreativePlanAccessService;
use App\Services\DevelopmentGitHubRoadmapReader;
use App\Services\DevelopmentRoadmapContextProjector;
use App\Services\DevelopmentRoadmapRevisionDiffer;
use App\Services\GitHubIntegrationReadinessService;
use App\Services\GitHubWorkflowService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Explicit, authorized pull endpoint for compact development context.
 * This is a first-party session endpoint, not an unauthenticated AI tool.
 */
final class DevelopmentRoadmapContextController extends Controller
{
    public function __invoke(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        DevelopmentCreativePlanAccessService $creative,
        GitHubIntegrationReadinessService $readiness,
        GitHubWorkflowService $workflow,
        DevelopmentGitHubRoadmapReader $reader,
        DevelopmentRoadmapContextProjector $projector,
        DevelopmentRoadmapRevisionDiffer $revisionDiffer,
    ): JsonResponse {
        abort_unless($request->user(), 401);
        $ownership->authorizeView($request, $plan);

        $eligible = $creative->partition(
            $request,
            $ownership->ownedPlans($request),
            $profiles,
        )['plans']->contains(fn (Plan $candidate) => $candidate->is($plan));
        abort_unless($eligible, 404);

        $validated = $request->validate([
            'scope' => ['sometimes', 'in:overview,priority,workstream'],
            'priority' => ['required_if:scope,priority', 'nullable', 'in:P0,P1,P2,P3'],
            'title' => ['required_if:scope,workstream', 'nullable', 'string', 'max:180'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'verify' => ['sometimes', 'in:0,1'],
            'compare' => ['sometimes', 'in:0,1'],
        ]);

        $repository = $plan->artifacts()
            ->where('provider', 'github')
            ->where('artifact_type', 'repository')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
        if (! $repository) {
            return response()->json(['error' => 'repository_not_connected'], 409);
        }

        $status = $readiness->forActor($request->user());
        $connection = $readiness->connectionStatus(
            $status,
            (array) data_get($repository->metadata, 'github_app_connection', []),
        );
        if (! data_get($status, 'evidence.allowed', false)
            || data_get($connection, 'state') !== 'ready') {
            return response()->json(['error' => 'github_read_not_ready'], 403);
        }

        $parsed = $workflow->parseUrl((string) $repository->url);
        if (! ($parsed['valid'] ?? false) || empty($parsed['repo_full_name'])) {
            return response()->json(['error' => 'invalid_repository_url'], 422);
        }

        $compare = ($validated['compare'] ?? '0') === '1';
        if ($compare && (($validated['verify'] ?? '0') === '1'
            || isset($validated['scope'])
            || isset($validated['title'])
            || isset($validated['priority']))) {
            return response()->json(['error' => 'incompatible_comparison_options'], 422);
        }

        try {
            if ($compare) {
                $diff = $reader->diffFromPrevious(
                    (string) $parsed['repo_full_name'],
                    $revisionDiffer,
                );
                return response()->json($diff)
                    ->header('Cache-Control', 'private, no-store');
            }

            $snapshot = $reader->read(
                (string) $parsed['repo_full_name'],
                ($validated['verify'] ?? '0') === '1',
            );
        } catch (\RuntimeException|\InvalidArgumentException $exception) {
            // Avoid leaking provider or installation internals to API consumers.
            return response()->json(['error' => 'roadmap_unavailable'], 503);
        }

        $scope = $validated['scope'] ?? 'overview';
        $rows = (array) ($snapshot['workstreams'] ?? []);
        if ($scope === 'priority') {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => ($row['priority'] ?? null) === $validated['priority'],
            ));
        } elseif ($scope === 'workstream') {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row) => ($row['title'] ?? null) === $validated['title'],
            ));
        }
        $snapshot['workstreams'] = $rows;

        $context = $projector->project($snapshot, (int) ($validated['limit'] ?? 8));
        $context['scope'] = $scope;
        $context['matched'] = count($rows);

        return response()->json($context)
            ->header('Cache-Control', 'private, no-store');
    }
}
