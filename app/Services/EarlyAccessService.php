<?php

namespace App\Services;

use App\Enums\ReleaseLevel;
use App\Models\User;
use Illuminate\Http\Request;

final class EarlyAccessService
{
    public function __construct(
        private readonly ReleaseLevelService $levels,
        private readonly AdminAccessService $adminAccess,
    ) {}

    public function activeFor(
        ?User $user = null,
        ?Request $request = null,
    ): bool {
        $request ??= app()->bound('request') ? request() : null;
        $user ??= $request?->user();

        if ($user && $this->adminAccess->isSuperAdmin($user)) {
            return false;
        }

        $level = $this->levels->levelFor($user, $request);

        return $level->value >= ReleaseLevel::EarlyAccessCore->value
            && $level->value <= ReleaseLevel::BetaExpansion->value;
    }
}
