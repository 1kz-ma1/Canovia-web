<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\McpExplicitPlanConsentService;
use App\Services\McpDelegatedIdentityFingerprintService;
use App\Services\McpOAuthAccountLinkCommitter;
use App\Services\McpOAuthAccountLinkConfiguration;
use App\Services\McpOAuthAccountLinkProvider;
use App\Services\McpStagingLoginPilotPolicy;
use App\Services\McpStagingOAuthFailureDiagnostic;
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
            return $this->failure('link_start_provider_preflight');
        }

        $state = $this->base64Url(random_bytes(32));
        $verifier = $this->base64Url(random_bytes(48));
        $challenge = $this->base64Url(hash('sha256', $verifier, true));
        $url = $provider->authorizationUrl($state, $challenge);

        if ($url === null) {
            return $this->failure('link_start_authorization_url');
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

        // Reject with a *fixed category only*, never the OAuth code, state,
        // returned issuer, subject or IdP error description.
        if ($settings === null) {
            return $this->failure('link_callback_configuration');
        }
        if (! is_array($pending)
            || (int) ($pending['actor_id'] ?? 0) !== (int) $user->id
            || ! is_int($pending['issued_at'] ?? null)
            || $pending['issued_at'] > now()->timestamp
            || now()->timestamp - $pending['issued_at'] > self::MAX_AGE_SECONDS) {
            return $this->failure('link_callback_session');
        }
        if (! is_string($state)
            || strlen($state) > 128
            || ! is_string($pending['state_hash'] ?? null)
            || ! hash_equals($pending['state_hash'], hash('sha256', $state))) {
            return $this->failure('link_callback_state');
        }
        if ($request->has('error')) {
            return $this->failure('link_callback_idp_error');
        }
        if (! is_string($issuer) || ! hash_equals($settings['issuer'], $issuer)) {
            return $this->failure('link_callback_issuer');
        }
        if (! is_string($code) || ! is_string($pending['verifier'] ?? null)) {
            return $this->failure('link_callback_code');
        }

        $principal = $provider->exchangeAndVerify($code, $pending['verifier']);

        if ($principal === null
            || $principal->issuer !== $settings['issuer']
            || $principal->clientId !== $settings['client_id']
            || $principal->audience !== $settings['resource']) {
            return $this->failure('link_callback_token');
        }

        $fingerprint = $fingerprints->subject($principal->issuer, $principal->subject);
        if ($fingerprint === null) {
            return $this->failure('link_callback_subject');
        }

        // A Plan consent needs its OWN verified outcome. Never turn a grant
        // OAuth callback into an implicit identity-link confirmation.
        if (($pending['purpose'] ?? null) === 'plan_consent') {
            $planId = $pending['plan_id'] ?? null;
            $scope = $pending['scope'] ?? null;
            $days = $pending['duration_days'] ?? null;
            $plan = is_int($planId) ? Plan::query()->find($planId) : null;

            // A callback cannot convert another Plan into stage consent,
            // even with a correctly verified external subject.
            if (config('app.env') === 'staging') {
                $stage = app(McpStagingLoginPilotPolicy::class);
                if ($plan === null || ! $stage->planConsentReady()
                    || ! $stage->isApprovedSyntheticPlan($user, $plan)) {
                    return $this->failure('link_callback_plan_scope');
                }
            }

            if (! $consent->isEnabled()
                || ! is_string($scope)
                || ! $consent->isScopeAndDurationAllowed($scope, $days)
                || $plan === null
                || ! $consent->isPersonalDevelopmentOwner($user, $plan)
                || ! $consent->matchesLinkedIdentity($user, $fingerprint)) {
                return $this->failure('link_callback_plan_scope');
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
                '本人確認ができました。共有する計画・内容・期間を確認して、よければ許可してください。',
            );
        }
        if (isset($pending['purpose'])) {
            // Unknown purpose must fail; a crafted flow is not link consent.
            return $this->failure('link_callback_unexpected_purpose');
        }

        // Store only an HMAC fingerprint, not raw sub, token, verifier or code.
        $request->session()->put(self::VERIFIED_SESSION, [
            'actor_id' => (int) $user->id,
            'identity_fingerprint' => $fingerprint,
            'verified_at' => now()->timestamp,
        ]);

        return redirect()->route('auth.account')->with(
            'status',
            '本人確認ができました。このアカウントを使う場合は、確認ボタンを押してください。',
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
            return $this->failure('link_confirm_rejected');
        }

        return redirect()->route('auth.account')->with(
            'status',
            '接続に使うアカウントを確認しました。ChatGPTに見せる計画は、別途選んで許可してください。',
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
            'status', '本人確認を中止しました。',
        );
    }

    private function failure(string $stageCode): RedirectResponse
    {
        // Identical user-facing error remains privacy-preserving in all envs.
        app(McpStagingOAuthFailureDiagnostic::class)->record($stageCode);
        return redirect()->route('auth.account')->with(
            'status',
            '本人確認を完了できませんでした。もう一度最初からお試しください。',
        );
    }

    private function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
