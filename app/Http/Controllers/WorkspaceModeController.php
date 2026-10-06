<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceMode;
use App\Services\WorkspaceModePreference;
use App\Services\WorkspaceModeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WorkspaceModeController extends Controller
{
    public function enter(
        string $workspaceMode,
        WorkspaceModeRegistry $registry,
    ): RedirectResponse {
        $mode = $this->publicMode($workspaceMode, $registry);

        return $this->redirectForMode($mode);
    }

    public function select(
        Request $request,
        string $workspaceMode,
        WorkspaceModeRegistry $registry,
        WorkspaceModePreference $preference,
    ): RedirectResponse {
        $mode = $this->publicMode($workspaceMode, $registry);

        $preference->remember($request, $mode);

        return $this->redirectForMode($mode);
    }

    public function reset(
        Request $request,
        WorkspaceModePreference $preference,
    ): RedirectResponse {
        $preference->clear($request);

        return redirect()
            ->route('home')
            ->with('status', 'Workspaceを自動判定に戻しました。');
    }

    private function publicMode(
        string $workspaceMode,
        WorkspaceModeRegistry $registry,
    ): WorkspaceMode {
        $mode = WorkspaceMode::tryFrom(
            mb_strtolower(trim($workspaceMode)),
        );

        abort_unless(
            $mode instanceof WorkspaceMode
                && in_array(
                    $mode->value,
                    $registry->publicKeys(),
                    true,
                ),
            404,
        );

        return $mode;
    }

    private function redirectForMode(
        WorkspaceMode $mode,
    ): RedirectResponse {
        return redirect()->route(
            match ($mode) {
                WorkspaceMode::Overview => 'workspace.overview.index',
                WorkspaceMode::Study => 'workspace.study.top',
                WorkspaceMode::Development => 'workspace.development.top',
                WorkspaceMode::Career => 'workspace.career.index',
            },
        );
    }
}
