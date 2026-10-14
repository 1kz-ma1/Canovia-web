<?php

namespace App\Http\Controllers;

use App\Models\McpDelegatedGrant;
use App\Models\McpLinkedSubject;
use App\Services\McpStagingSyntheticActorBootstrap;
use App\Services\McpDelegatedRevocationService;
use App\Services\McpStagingLoginPilotPolicy;
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
        if (config('app.env') === 'staging') {
            $actor = $request->user();
            $existing = $actor
                ? McpDelegatedGrant::query()->where('user_id', $actor->id)
                    ->with('plan')->find($grant)
                : null;
            $policy = app(McpStagingLoginPilotPolicy::class);
            abort_unless($actor && $existing && $existing->plan
                && $policy->planConsentReady()
                && $policy->isApprovedSyntheticPlan($actor, $existing->plan), 404);
        }
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
        if (config('app.env') === 'staging') {
            $actor = $request->user();
            abort_unless($actor
                && $actor->email === McpStagingSyntheticActorBootstrap::EMAIL
                && app(McpStagingLoginPilotPolicy::class)->accountLinkReady()
                && McpLinkedSubject::query()->whereKey($subject)
                    ->where('user_id', $actor->id)
                    ->where('status', McpLinkedSubject::STATUS_LINKED)
                    ->whereNull('revoked_at')->exists(), 404);
        }

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
