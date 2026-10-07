<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Enums\ReleaseLevel;
use App\Enums\WorkspaceMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

final class ReleaseGateService
{
    public function __construct(
        private readonly ReleaseLevelService $levels,
    ) {}

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function assessments(): Collection
    {
        return collect(ReleaseLevel::cases())
            ->map(fn (ReleaseLevel $level) => $this->assess($level));
    }

    /**
     * @return array<string,mixed>
     */
    public function assess(ReleaseLevel $target): array
    {
        $checks = collect($this->inventoryChecks());
        $manualChecks = collect();

        foreach (ReleaseLevel::cases() as $sourceLevel) {
            if ($sourceLevel->value > $target->value) {
                continue;
            }

            $definition = $this->gateDefinition($sourceLevel);

            foreach ((array) ($definition['required_workspaces'] ?? []) as $workspaceKey) {
                $workspace = WorkspaceMode::tryFrom((string) $workspaceKey);
                $passed = $workspace instanceof WorkspaceMode
                    && $this->levels->minimumForWorkspace($workspace)->value <= $sourceLevel->value;

                $checks->push([
                    'key' => 'workspace:'.$sourceLevel->value.':'.$workspaceKey,
                    'kind' => 'workspace',
                    'source_level' => $sourceLevel->value,
                    'label' => sprintf(
                        'L%d requires Workspace %s',
                        $sourceLevel->value,
                        $workspaceKey,
                    ),
                    'passed' => $passed,
                    'detail' => $passed
                        ? 'Workspace publication contract is satisfied.'
                        : 'Workspace is unknown or its minimum Release Level is too high.',
                ]);
            }

            foreach ((array) ($definition['required_features'] ?? []) as $featureKey) {
                $feature = FeatureKey::tryFrom((string) $featureKey);
                $hasEntitlementDefinition = $feature instanceof FeatureKey
                    && config('entitlements.features.'.$feature->value) !== null;
                $passed = $feature instanceof FeatureKey
                    && $hasEntitlementDefinition
                    && $this->levels->minimumForFeature($feature)->value <= $sourceLevel->value;

                $checks->push([
                    'key' => 'feature:'.$sourceLevel->value.':'.$featureKey,
                    'kind' => 'feature',
                    'source_level' => $sourceLevel->value,
                    'label' => sprintf(
                        'L%d requires Feature %s',
                        $sourceLevel->value,
                        $featureKey,
                    ),
                    'passed' => $passed,
                    'detail' => $passed
                        ? 'Feature maturity and entitlement contracts are present.'
                        : 'Feature is unknown, missing entitlement policy, or assigned above this Level.',
                ]);
            }

            foreach ((array) ($definition['required_routes'] ?? []) as $routeName) {
                $routeName = (string) $routeName;
                $passed = Route::has($routeName);

                $checks->push([
                    'key' => 'route:'.$sourceLevel->value.':'.$routeName,
                    'kind' => 'route',
                    'source_level' => $sourceLevel->value,
                    'label' => sprintf(
                        'L%d requires route %s',
                        $sourceLevel->value,
                        $routeName,
                    ),
                    'passed' => $passed,
                    'detail' => $passed
                        ? 'Canonical route exists.'
                        : 'Canonical route is missing.',
                ]);
            }

            foreach ((array) ($definition['manual_checks'] ?? []) as $manualKey => $manualCheck) {
                $manualChecks->push([
                    'source_level' => $sourceLevel->value,
                    'level_label' => $sourceLevel->label(),
                    'check_key' => is_string($manualKey)
                        ? $manualKey
                        : 'manual_'.($manualKey + 1),
                    'label' => (string) $manualCheck,
                ]);
            }
        }

        $automaticReady = $checks->every(
            fn (array $check) => (bool) $check['passed'],
        );

        return [
            'level' => $target,
            'automatic_ready' => $automaticReady,
            'status' => ! $automaticReady
                ? 'blocked'
                : ($manualChecks->isEmpty() ? 'ready' : 'manual_review'),
            'checks' => $checks->values(),
            'failed_checks' => $checks
                ->reject(fn (array $check) => (bool) $check['passed'])
                ->values(),
            'manual_checks' => $manualChecks->values(),
            'automatic_check_count' => $checks->count(),
            'failed_check_count' => $checks
                ->reject(fn (array $check) => (bool) $check['passed'])
                ->count(),
        ];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function featureInventory(): Collection
    {
        $gateDefinitions = (array) config(
            'release_levels.gate.levels',
            [],
        );

        return collect(FeatureKey::cases())
            ->map(function (FeatureKey $feature) use ($gateDefinitions) {
                $minimum = $this->levels->minimumForFeature($feature);
                $requiredAt = collect($gateDefinitions)
                    ->filter(
                        fn (array $definition) => in_array(
                            $feature->value,
                            (array) ($definition['required_features'] ?? []),
                            true,
                        ),
                    )
                    ->keys()
                    ->map(fn ($value) => (int) $value)
                    ->sort()
                    ->values();

                return [
                    'feature' => $feature,
                    'label' => (string) config(
                        'entitlements.features.'.$feature->value.'.label',
                        $feature->value,
                    ),
                    'minimum_level' => $minimum,
                    'required_at' => $requiredAt,
                    'free' => (bool) config(
                        'entitlements.features.'.$feature->value.'.free',
                        false,
                    ),
                    'has_feature_flag' => array_key_exists(
                        $feature->value,
                        (array) config('features.flags', []),
                    ),
                ];
            })
            ->sortBy(
                fn (array $item) => sprintf(
                    '%02d:%s',
                    $item['minimum_level']->value,
                    $item['feature']->value,
                ),
            )
            ->values();
    }

    public function recommendedTarget(): ReleaseLevel
    {
        return ReleaseLevel::tryFrom(
            (int) config(
                'release_levels.early_access_target',
                ReleaseLevel::EarlyAccessCore->value,
            ),
        ) ?? ReleaseLevel::EarlyAccessCore;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function inventoryChecks(): array
    {
        $configuredFeatures = array_keys(
            (array) config('release_levels.feature_minimum', []),
        );
        $featureKeys = array_map(
            static fn (FeatureKey $feature) => $feature->value,
            FeatureKey::cases(),
        );
        sort($configuredFeatures);
        sort($featureKeys);

        $entitlementKeys = array_keys(
            (array) config('entitlements.features', []),
        );
        sort($entitlementKeys);

        $configuredWorkspaces = array_keys(
            (array) config('release_levels.workspace_minimum', []),
        );
        $workspaceKeys = array_map(
            static fn (WorkspaceMode $mode) => $mode->value,
            WorkspaceMode::cases(),
        );
        sort($configuredWorkspaces);
        sort($workspaceKeys);

        return [
            [
                'key' => 'inventory:feature_minimum',
                'kind' => 'inventory',
                'source_level' => null,
                'label' => 'Every FeatureKey has exactly one Release Level minimum',
                'passed' => $configuredFeatures === $featureKeys,
                'detail' => $configuredFeatures === $featureKeys
                    ? 'Feature maturity inventory is complete.'
                    : 'FeatureKey and release_levels.feature_minimum are out of sync.',
            ],
            [
                'key' => 'inventory:entitlements',
                'kind' => 'inventory',
                'source_level' => null,
                'label' => 'Every FeatureKey has an entitlement policy',
                'passed' => $entitlementKeys === $featureKeys,
                'detail' => $entitlementKeys === $featureKeys
                    ? 'Entitlement inventory is complete.'
                    : 'FeatureKey and entitlements.features are out of sync.',
            ],
            [
                'key' => 'inventory:workspace_minimum',
                'kind' => 'inventory',
                'source_level' => null,
                'label' => 'Every WorkspaceMode has a Release Level minimum',
                'passed' => $configuredWorkspaces === $workspaceKeys,
                'detail' => $configuredWorkspaces === $workspaceKeys
                    ? 'Workspace maturity inventory is complete.'
                    : 'WorkspaceMode and release_levels.workspace_minimum are out of sync.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function gateDefinition(ReleaseLevel $level): array
    {
        $definitions = (array) config(
            'release_levels.gate.levels',
            [],
        );

        return (array) ($definitions[$level->value] ?? []);
    }
}
