<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceMode;
use App\Services\WorkspaceModePreference;
use App\Services\WorkspaceNavigationService;
use App\Services\WorkspaceModeRegistry;
use App\Services\PersonalizationLivingProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WorkspaceModeController extends Controller
{
    public function enter(
        string $workspaceMode,
        WorkspaceModeRegistry $registry,
    ): RedirectResponse {
        $mode = $this->publicMode($workspaceMode, $registry);

        return $mode === WorkspaceMode::Overview
            ? $this->redirectForMode($mode)
            : redirect()->to(app(WorkspaceNavigationService::class)->resumeUrl(request(), $mode));
    }

    public function resume(Request $request, WorkspaceNavigationService $navigation): RedirectResponse
    {
        $mode = $navigation->lastMode($request);
        if (! in_array($mode->value, app(WorkspaceModeRegistry::class)->publicKeys(), true)) {
            return redirect()->route('home');
        }
        return redirect()->to($navigation->resumeUrl($request, $mode));
    }

    public function select(
        Request $request,
        string $workspaceMode,
        WorkspaceModeRegistry $registry,
        WorkspaceModePreference $preference,
        PersonalizationLivingProfileService $livingProfile,
    ): RedirectResponse {
        $mode = $this->publicMode($workspaceMode, $registry);

        $preference->remember($request, $mode);

        if (
            $request->user()
            && in_array(
                $mode,
                [WorkspaceMode::Study, WorkspaceMode::Development],
                true,
            )
        ) {
            $livingProfile->refresh(
                $request,
                'workspace_change',
            );
        }

        return $mode === WorkspaceMode::Overview
            ? $this->redirectForMode($mode)
            : redirect()->to(app(WorkspaceNavigationService::class)->resumeUrl($request, $mode));
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
