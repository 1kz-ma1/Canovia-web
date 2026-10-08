<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\McpExplicitPlanConsentService;
use App\Services\McpDelegatedIdentityFingerprintService;
use App\Services\McpOAuthAccountLinkCommitter;
use App\Services\McpOAuthAccountLinkConfiguration;
use App\Services\McpOAuthAccountLinkProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Canovia account holder → external IdP subject linking only.
 *
 * This is NOT the ChatGPT OAuth redirect or an MCP authorization endpoint.
 * Sessions, CSRF + PKCE, RFC 9207 issuer and token introspection are all
 * required before the user can explicitly confirm the link.
 */
final class McpOAuthAccountLinkController extends Controller
{
    private const PENDING_SESSION = 'mcp.account_link.pending';
    private const VERIFIED_SESSION = 'mcp.account_link.verified';
    private const MAX_AGE_SECONDS = 300;

    public function start(
        Request $request,
        McpOAuthAccountLinkProvider $provider,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user, 403);

        // Clear older verified and pending state before every fresh attempt.
        $request->session()->forget([
            self::PENDING_SESSION,
            self::VERIFIED_SESSION,
            McpOAuthPlanConsentController::VERIFIED_SESSION,
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

        $request->session()->put(self::PENDING_SESSION, [
            'actor_id' => (int) $user->id,
            'state_hash' => hash('sha256', $state),
            'verifier' => $verifier,
            'issued_at' => now()->timestamp,
        ]);

        return redirect()->away($url)->header('Cache-Control', 'no-store, private');
    }

    public function callback(
        Request $request,
        McpOAuthAccountLinkConfiguration $configuration,
        McpOAuthAccountLinkProvider $provider,
        McpDelegatedIdentityFingerprintService $fingerprints,
        McpExplicitPlanConsentService $consent,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user, 403);

        // One-time use even for failures; stale state can never be replayed.
        $pending = $request->session()->pull(self::PENDING_SESSION);
        $request->session()->forget([
            self::VERIFIED_SESSION,
            McpOAuthPlanConsentController::VERIFIED_SESSION,
        ]);

        $state = $request->query('state');
        $code = $request->query('code');
        $issuer = $request->query('iss');
        $settings = $configuration->settings();

        if ($settings === null || ! is_array($pending)
            || (int) ($pending['actor_id'] ?? 0) !== (int) $user->id
            || ! is_int($pending['issued_at'] ?? null)
            || $pending['issued_at'] > now()->timestamp
            || now()->timestamp - $pending['issued_at'] > self::MAX_AGE_SECONDS
            || ! is_string($state)
            || strlen($state) > 128
            || ! is_string($pending['state_hash'] ?? null)
            || ! hash_equals($pending['state_hash'], hash('sha256', $state))
            || ! is_string($issuer)
            || ! hash_equals($settings['issuer'], $issuer)
            || $request->has('error')
            || ! is_string($code)
            || ! is_string($pending['verifier'] ?? null)) {
            return $this->failure();
        }

        $principal = $provider->exchangeAndVerify($code, $pending['verifier']);

        if ($principal === null
            || $principal->issuer !== $settings['issuer']
            || $principal->clientId !== $settings['client_id']
            || $principal->audience !== $settings['resource']) {
            return $this->failure();
        }

        $fingerprint = $fingerprints->subject($principal->issuer, $principal->subject);
        if ($fingerprint === null) {
            return $this->failure();
        }

        // A Plan consent needs its OWN verified outcome. Never turn a grant
        // OAuth callback into an implicit identity-link confirmation.
        if (($pending['purpose'] ?? null) === 'plan_consent') {
            $planId = $pending['plan_id'] ?? null;
            $scope = $pending['scope'] ?? null;
            $days = $pending['duration_days'] ?? null;
            $plan = is_int($planId) ? Plan::query()->find($planId) : null;

            if (! $consent->isEnabled()
                || ! is_string($scope) || ! is_int($days)
                || ! $consent->isScopeAndDurationAllowed($scope, $days)
                || $plan === null
                || ! $consent->isPersonalDevelopmentOwner($user, $plan)
                || ! $consent->matchesLinkedIdentity($user, $fingerprint)) {
                return $this->failure();
            }

            $request->session()->put(McpOAuthPlanConsentController::VERIFIED_SESSION, [
                'actor_id' => (int) $user->id,
                'identity_fingerprint' => $fingerprint,
                'plan_id' => (int) $plan->id,
                'scope' => $scope,
                'duration_days' => $days,
                'verified_at' => now()->timestamp,
            ]);
            return redirect()->route('auth.account')->with(
                'status',
                '本人IDの再確認が完了しました。対象Plan・共有範囲・期限を確認して、共有許可を確定してください。',
            );
        }
        if (isset($pending['purpose'])) {
            // Unknown purpose must fail; a crafted flow is not link consent.
            return $this->failure();
        }

        // Store only an HMAC fingerprint, not raw sub, token, verifier or code.
        $request->session()->put(self::VERIFIED_SESSION, [
            'actor_id' => (int) $user->id,
            'identity_fingerprint' => $fingerprint,
            'verified_at' => now()->timestamp,
        ]);

        return redirect()->route('auth.account')->with(
            'status',
            '認証プロバイダーによる本人確認が完了しました。連携を確定するには、内容を確認して確定ボタンを押してください。',
        );
    }

    public function confirm(
        Request $request,
        McpOAuthAccountLinkConfiguration $configuration,
        McpOAuthAccountLinkCommitter $committer,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user, 403);

        // Explicit owner confirmation; this never issues a Plan access grant.
        $verified = $request->session()->pull(self::VERIFIED_SESSION);

        if ($configuration->settings() === null
            || ! is_array($verified)
            || (int) ($verified['actor_id'] ?? 0) !== (int) $user->id
            || ! is_int($verified['verified_at'] ?? null)
            || $verified['verified_at'] > now()->timestamp
            || now()->timestamp - $verified['verified_at'] > self::MAX_AGE_SECONDS
            || ! is_string($verified['identity_fingerprint'] ?? null)
            || ! $committer->confirm($user, $verified['identity_fingerprint'])) {
            return $this->failure();
        }

        return redirect()->route('auth.account')->with(
            'status',
            '外部IDとの紐付けが完了しました。ChatGPTへのPlan共有はまだ許可していません。',
        );
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget([
            self::PENDING_SESSION,
            self::VERIFIED_SESSION,
            McpOAuthPlanConsentController::VERIFIED_SESSION,
        ]);

        return redirect()->route('auth.account')->with(
            'status', '外部IDの紐付け操作を中止しました。',
        );
    }

    private function failure(): RedirectResponse
    {
        // Avoid leaking OAuth errors, identities and provider token responses.
        return redirect()->route('auth.account')->with(
            'status',
            '外部IDの本人確認を完了できませんでした。設定と認証状態をご確認のうえ、最初からやり直してください。',
        );
    }

    private function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
