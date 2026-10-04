<?php

namespace App\Services;

use App\Enums\WorkspaceMode;
use Illuminate\Http\Request;

final class WorkspaceModePreference
{
    public const SESSION_KEY = 'canovia_workspace_mode_preference';

    public function __construct(
        private readonly WorkspaceModeRegistry $registry,
    ) {}

    public function selected(Request $request): ?WorkspaceMode
    {
        $value = $request->user()
            ? $request->user()->workspace_mode_preference
            : ($request->hasSession()
                ? $request->session()->get(self::SESSION_KEY)
                : null);

        return $this->publicMode($value);
    }

    public function remember(Request $request, WorkspaceMode $mode): void
    {
        abort_unless($this->isPublic($mode), 404);

        if ($user = $request->user()) {
            $user->forceFill([
                'workspace_mode_preference' => $mode->value,
            ])->save();

            return;
        }

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $mode->value);
        }
    }

    public function clear(Request $request): void
    {
        if ($user = $request->user()) {
            $user->forceFill([
                'workspace_mode_preference' => null,
            ])->save();
        }

        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }

    private function publicMode(mixed $value): ?WorkspaceMode
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $mode = WorkspaceMode::tryFrom(
            mb_strtolower(trim($value)),
        );

        return $mode instanceof WorkspaceMode && $this->isPublic($mode)
            ? $mode
            : null;
    }

    private function isPublic(WorkspaceMode $mode): bool
    {
        return in_array(
            $mode->value,
            $this->registry->publicKeys(),
            true,
        );
    }
}
