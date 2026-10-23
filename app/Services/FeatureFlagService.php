<?php

namespace App\Services;

use App\Enums\FeatureKey;

final class FeatureFlagService
{
    /**
     * Runtime publication boundary. Entitlement is intentionally evaluated elsewhere.
     *
     * @param array<string, mixed> $context
     */
    public function isEnabled(FeatureKey|string $feature, array $context = []): bool
    {
        if (app(ReleaseLevelService::class)->preparationOnly()) {
            return false;
        }

        $key = $feature instanceof FeatureKey ? $feature->value : $feature;
        $definition = config("features.flags.{$key}");

        if (is_bool($definition)) {
            return $definition;
        }

        if (! is_array($definition) || ! (bool) ($definition['enabled'] ?? false)) {
            return false;
        }

        $environment = $definition['environment'] ?? null;
        if (is_string($environment) && $environment !== '' && ! app()->environment($environment)) {
            return false;
        }

        $platform = $definition['platform'] ?? null;
        if (is_string($platform) && $platform !== '' && $platform !== 'all') {
            if (($context['platform'] ?? null) !== $platform) {
                return false;
            }
        }

        $minimumVersion = $definition['minimum_app_version'] ?? null;
        if (is_string($minimumVersion) && $minimumVersion !== '') {
            $appVersion = $context['app_version'] ?? null;
            if (! is_string($appVersion) || $appVersion === '') {
                return false;
            }

            if (version_compare(ltrim($appVersion, 'vV'), ltrim($minimumVersion, 'vV'), '<')) {
                return false;
            }
        }

        return true;
    }
}
