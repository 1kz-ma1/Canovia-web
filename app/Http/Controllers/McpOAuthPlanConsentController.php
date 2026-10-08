<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\McpExplicitPlanConsentService;
use App\Services\McpOAuthAccountLinkProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Separate Plan consent from earlier 'prepared' preferences and IdP link.
 * It requires fresh OAuth + PKCE in the existing account-link callback,
 * then a second, explicit authenticated POST to activate one Plan grant.
 */
final class McpOAuthPlanConsentController extends Controller
{
    public const VERIFIED_SESSION = 'mcp.plan_consent.verified';
    public const MAX_AGE_SECONDS = 300;

    public function start(
        Request $request,
        Plan $plan,
        McpExplicitPlanConsentService $consent,
        McpOAuthAccountLinkProvider $provider,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor && $consent->isPersonalDevelopmentOwner($actor, $plan), 404);

        $validated = $request->validate([
            'scope' => ['required', 'in:overview,tasks'],
            'duration_days' => ['required', 'integer', 'in:1,7,30'],
        ]);
        $scope = (string) $validated['scope'];
        $days = (int) $validated['duration_days'];

        // Never create a consent without an independently verified, currently
        // linked stable IdP subject and live-compatible account-link client.
        if (! $consent->isEnabled()
            || ! $consent->hasActiveLinkedIdentity($actor)
            || ! $consent->isScopeAndDurationAllowed($scope, $days)) {
            return $this->failure();
        }

        // Consent and identity-link handshake share the same fixed callback;
        // invalidate both previously verified session outcomes first.
        $request->session()->forget([
            'mcp.account_link.pending',
            'mcp.account_link.verified',
            self::VERIFIED_SESSION,
        ]);

        if (! $provider->isProviderVerified()) {
            return $this->failure();
        }

        $state = $this->base64Url(random_bytes(32));
        $verifier = $this->base64Url(random_bytes(48));
        $challenge = $this->base64Url(hash('sha256', $verifier, true));
        $url = $provider->authorizationUrl($state, $challenge);
        if ($url === null) {
            return $this->failure();
        }

        $request->session()->put('mcp.account_link.pending', [
            'actor_id' => (int) $actor->id,
            'state_hash' => hash('sha256', $state),
            'verifier' => $verifier,
            'issued_at' => now()->timestamp,
            'purpose' => 'plan_consent',
            'plan_id' => (int) $plan->id,
            'scope' => $scope,
            'duration_days' => $days,
        ]);

        return redirect()->away($url)->header('Cache-Control', 'no-store, private');
    }

    public function confirm(
        Request $request,
        McpExplicitPlanConsentService $consent,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor, 403);

        // Verification evidence is one-time, even if Plan permissions have
        // since been revoked or the final database update fails.
        $verified = $request->session()->pull(self::VERIFIED_SESSION);

        if (! $consent->isEnabled()
            || ! is_array($verified)
            || (int) ($verified['actor_id'] ?? 0) !== (int) $actor->id
            || ! is_int($verified['verified_at'] ?? null)
            || $verified['verified_at'] > now()->timestamp
            || now()->timestamp - $verified['verified_at'] > self::MAX_AGE_SECONDS
            || ! is_int($verified['plan_id'] ?? null)
            || ! is_string($verified['identity_fingerprint'] ?? null)
            || ! is_string($verified['scope'] ?? null)
            || ! is_int($verified['duration_days'] ?? null)
            || ! $consent->grant(
                $actor,
                $verified['plan_id'],
                $verified['identity_fingerprint'],
                $verified['scope'],
                $verified['duration_days'],
            )) {
            return $this->failure();
        }

        return redirect()->route('auth.account')->with(
            'status',
            '指定した開発Planの共有範囲と期限を承認しました。ChatGPTからの直接取得はまだ有効になっていません。',
        );
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget([
            'mcp.account_link.pending',
            'mcp.account_link.verified',
            self::VERIFIED_SESSION,
        ]);
        return redirect()->route('auth.account')->with(
            'status',
            'Planの共有承認を中止しました。新しい許可は作成していません。',
        );
    }

    private function failure(): RedirectResponse
    {
        return redirect()->route('auth.account')->with(
            'status',
            'Plan共有の本人確認・許可を完了できませんでした。対象Planと認証状態を確認し、最初からやり直してください。',
        );
    }

    private function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
