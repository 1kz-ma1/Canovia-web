<?php

namespace App\Services;

use App\Enums\FeatureKey;
use App\Enums\ReleaseLevel;
use App\Enums\WorkspaceMode;
use App\Models\User;
use Illuminate\Http\Request;

final class ReleaseLevelService
{
    public const ADMIN_PREVIEW_SESSION_KEY = 'canovia_admin_release_preview_level';

    public function __construct(
        private readonly AdminAccessService $adminAccess,
    ) {}

    public function publicLevel(): ReleaseLevel
    {
        return $this->normalizeLevel(
            config(
                'release_levels.public_level',
                ReleaseLevel::InternalPreview->value,
            ),
            ReleaseLevel::InternalPreview,
        );
    }

    public function levelFor(
        ?User $user = null,
        ?Request $request = null,
    ): ReleaseLevel {
        $request ??= app()->bound('request') ? request() : null;
        $user ??= $request?->user();

        if (
            $user
            && $this->adminAccess->isSuperAdmin($user)
            && $request?->hasSession()
        ) {
            $preview = $request->session()->get(
                self::ADMIN_PREVIEW_SESSION_KEY,
            );

            if ($preview !== null) {
                return $this->normalizeLevel(
                    $preview,
                    $this->publicLevel(),
                );
            }
        }

        return $user
            ? $this->assignedLevelFor($user)
            : $this->publicLevel();
    }

    public function assignedLevelFor(User $user): ReleaseLevel
    {
        if ($user->release_level_override !== null) {
            return $this->normalizeLevel(
                $user->release_level_override,
                $this->publicLevel(),
            );
        }

        return $this->publicLevel();
    }

    public function setAdminPreview(
        Request $request,
        ?ReleaseLevel $level,
    ): void {
        abort_unless($this->adminAccess->authorized($request), 403);

        if ($level === null) {
            $request->session()->forget(
                self::ADMIN_PREVIEW_SESSION_KEY,
            );

            return;
        }

        $request->session()->put(
            self::ADMIN_PREVIEW_SESSION_KEY,
            $level->value,
        );
    }

    public function adminPreview(
        ?User $user = null,
        ?Request $request = null,
    ): ?ReleaseLevel {
        $request ??= app()->bound('request') ? request() : null;
        $user ??= $request?->user();

        if (
            ! $user
            || ! $this->adminAccess->isSuperAdmin($user)
            || ! $request?->hasSession()
        ) {
            return null;
        }

        $value = $request->session()->get(
            self::ADMIN_PREVIEW_SESSION_KEY,
        );

        if ($value === null) {
            return null;
        }

        return $this->normalizeLevel($value, $this->publicLevel());
    }

    public function setUserOverride(
        User $user,
        ?ReleaseLevel $level,
    ): void {
        if (
            $level
            && $level->value > (int) config(
                'release_levels.maximum_user_override',
                ReleaseLevel::BetaExpansion->value,
            )
        ) {
            abort(422);
        }

        $user->forceFill([
            'release_level_override' => $level?->value,
        ])->save();
    }

    public function minimumForFeature(
        FeatureKey $feature,
    ): ReleaseLevel {
        return $this->normalizeLevel(
            config(
                "release_levels.feature_minimum.{$feature->value}",
                ReleaseLevel::InternalPreview->value,
            ),
            ReleaseLevel::InternalPreview,
        );
    }

    public function allowsFeature(
        FeatureKey $feature,
        ?User $user = null,
        ?Request $request = null,
    ): bool {
        return $this->levelFor($user, $request)
            ->isAtLeast($this->minimumForFeature($feature));
    }

    public function minimumForWorkspace(
        WorkspaceMode $mode,
    ): ReleaseLevel {
        return $this->normalizeLevel(
            config(
                "release_levels.workspace_minimum.{$mode->value}",
                ReleaseLevel::InternalPreview->value,
            ),
            ReleaseLevel::InternalPreview,
        );
    }

    public function allowsWorkspace(
        WorkspaceMode $mode,
        ?User $user = null,
        ?Request $request = null,
    ): bool {
        return $this->levelFor($user, $request)
            ->isAtLeast($this->minimumForWorkspace($mode));
    }

    public function allowsMinimum(
        ReleaseLevel|int|string $minimum,
        ?User $user = null,
        ?Request $request = null,
    ): bool {
        return $this->levelFor($user, $request)
            ->isAtLeast(
                $this->normalizeLevel(
                    $minimum,
                    ReleaseLevel::InternalPreview,
                ),
            );
    }

    private function normalizeLevel(
        mixed $value,
        ReleaseLevel $fallback,
    ): ReleaseLevel {
        if ($value instanceof ReleaseLevel) {
            return $value;
        }

        if (
            is_int($value)
            || (is_string($value) && preg_match('/^\d+$/', $value))
        ) {
            return ReleaseLevel::tryFrom((int) $value) ?? $fallback;
        }

        return $fallback;
    }
}
