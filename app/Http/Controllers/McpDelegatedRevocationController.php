<?php

namespace App\Http\Controllers;

use App\Services\McpDelegatedRevocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Owner-only, session/CSRF-protected revocation actions. These routes only
 * remove future access eligibility; they never issue an OAuth grant.
 */
final class McpDelegatedRevocationController extends Controller
{
    public function grant(
        Request $request,
        int $grant,
        McpDelegatedRevocationService $revocations,
    ): RedirectResponse {
        abort_unless(
            $request->user()
                && $revocations->revokeGrant($request->user(), $grant),
            404,
        );

        return redirect()->route('auth.account')->with(
            'status',
            'ChatGPT向けのPlan共有許可を取り消しました。',
        );
    }

    public function subject(
        Request $request,
        int $subject,
        McpDelegatedRevocationService $revocations,
    ): RedirectResponse {
        abort_unless(
            $request->user()
                && $revocations->revokeSubject($request->user(), $subject),
            404,
        );

        return redirect()->route('auth.account')->with(
            'status',
            'ChatGPT向けの外部ID連携を解除しました。関連するPlan共有許可も取り消しました。',
        );
    }
}
