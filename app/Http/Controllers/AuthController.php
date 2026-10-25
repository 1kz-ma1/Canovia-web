<?php

namespace App\Http\Controllers;

use App\Enums\ReleaseLevel;
use App\Models\User;
use App\Models\DevelopmentAiSharingPreference;
use App\Models\McpDelegatedGrant;
use App\Models\McpStudyResultProposal;
use App\Models\McpLinkedSubject;
use App\Services\AccountDeletionService;
use App\Services\GuestPlanClaimService;
use App\Services\FutureMemoService;
use App\Services\FirstRunService;
use App\Services\ReleaseLevelService;
use App\Services\EarlyAccessTelemetryService;
use App\Services\PersonalizationLivingProfileService;
use Illuminate\Http\Request;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (config('app.env') === 'staging'
            && config('canovia_staging.login_pilot_enabled') === true) {
            return view('auth.staging-pilot-login');
        }

        return view('auth.login');
    }

    public function login(
        Request $request,
        GuestPlanClaimService $claimService,
        FutureMemoService $futureMemos,
        FirstRunService $firstRun,
    ) {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Installed PWAs should survive browser restarts and deploys. The web form
        // sends an explicit value, while older/alternate clients default to remember.
        $pilot = config('app.env') === 'staging'
            && config('canovia_staging.login_pilot_enabled') === true;
        // The short-lived staging login must not create a persistent remember-me cookie.
        $remember = $pilot ? false
            : ($request->has('remember') ? $request->boolean('remember') : true);

        if (! Auth::attempt($credentials, $remember)) {
            throw ValidationException::withMessages([
                'email' => 'メールアドレスまたはパスワードが正しくありません。',
            ]);
        }

        $request->session()->regenerate();
        $claimed = $claimService->claim($request, $request->user());
        $claimedMemos = $futureMemos->claimGuestMemos($request, $request->user());

        // Existing accounts are backfilled as already introduced. If an
        // unfinished new account logs in later, keep its persisted gate intact.
        if ($firstRun->hasBrowserPass($request)) {
            $firstRun->markPassed($request);
        }

        $claimMessages = [];
        if ($claimed > 0) $claimMessages[] = "{$claimed}件のGuest計画";
        if ($claimedMemos > 0) $claimMessages[] = "{$claimedMemos}件の保存情報";

        // Avoid landing on the deliberately blocked Canovia home screen.
        $defaultHome = $pilot ? route('auth.account') : route('home');

        return ($pilot ? redirect()->route('auth.account') : redirect()->intended($defaultHome))->with(
            'status',
            $claimMessages !== []
                ? 'ログインしました。' . implode('と', $claimMessages) . 'もこのアカウントに引き継ぎました。'
                : 'ログインしました。'
        );
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(
        Request $request,
        GuestPlanClaimService $claimService,
        FutureMemoService $futureMemos,
        FirstRunService $firstRun,
        EarlyAccessTelemetryService $earlyAccessTelemetry,
    ) {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $user = User::create($validated);

        // Registration completes the authentication flow. The user should not
        // have to type the same credentials again after protecting Guest data.
        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();
        $claimed = $claimService->claim($request, $user);
        $claimedMemos = $futureMemos->claimGuestMemos($request, $user);
        $earlyAccessTelemetry->recordRegistration($request, $user);

        $claimMessages = [];
        if ($claimed > 0) $claimMessages[] = "{$claimed}件のGuest計画";
        if ($claimedMemos > 0) $claimMessages[] = "{$claimedMemos}件の保存情報";

        if ($claimed > 0 || $firstRun->hasBrowserPass($request)) {
            $firstRun->markPassed($request);
            $defaultRoute = $claimed > 0 ? route('home') : route('plans.create');
        } else {
            $firstRun->requireForNewAccount($request);
            $defaultRoute = route('first_run.show');
        }

        return redirect()->intended($defaultRoute)->with(
            'status',
            $claimMessages !== []
                ? 'アカウントを作成し、' . implode('と', $claimMessages) . 'を保護しました。'
                : 'アカウントを作成しました。まず、今進めたいことと現在地を少しだけ教えてください。'
        );
    }


    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // A reset link is a bearer credential. Development log/array mailers
        // must never mint or print one in a deployed/unknown environment.
        if (! in_array(config('app.env'), ['local', 'testing'], true)
            && ! $this->passwordResetDeliveryConfigured()) {
            return back()->withErrors(['email' => '現在、再設定メールを送信できません。しばらくしてから、もう一度お試しください。']);
        }

        $status = Password::sendResetLink($request->only('email'));

        // Do not reveal whether an address is registered.
        if (in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER], true)) {
            return back()->with('status', '登録済みのメールアドレスであれば、再設定用リンクを送信しました。');
        }

        return back()->withErrors(['email' => 'しばらく待ってから、もう一度お試しください。']);
    }

    private function passwordResetDeliveryConfigured(): bool
    {
        $visited = 0;
        $check = function ($name, array $ancestors = []) use (&$check, &$visited): bool {
            if (! is_string($name) || $name === '' || strlen($name) > 80
                || ++$visited > 32 || count($ancestors) >= 8 || in_array($name, $ancestors, true)) {
                return false;
            }
            $mailer = config('mail.mailers.'.$name);
            if (! is_array($mailer)) {
                return false;
            }
            // Match Laravel MailManager's URL override semantics, including
            // ?driver=log; never print parse errors or any configured values.
            if (isset($mailer['url'])) {
                $mailer = array_merge($mailer, (new \Illuminate\Support\ConfigurationUrlParser)->parseConfiguration($mailer));
                $mailer['transport'] = $mailer['driver'] ?? null;
            }
            if (in_array($mailer['transport'] ?? null, ['failover', 'roundrobin'], true)) {
                $children = $mailer['mailers'] ?? null;
                if (! is_array($children) || ! array_is_list($children) || $children === [] || count($children) > 8) {
                    return false;
                }
                foreach ($children as $child) {
                    if (! $check($child, [...$ancestors, $name])) {
                        return false;
                    }
                }
                return true;
            }
            return in_array($mailer['transport'] ?? null,
                ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark', 'resend'], true);
        };
        try {
            // Legacy/custom global drivers need an explicit separate review.
            return config('mail.driver') === null && $check(config('mail.default'));
        } catch (\Throwable) {
            return false;
        }
    }

    public function showResetPassword(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function resetPassword(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $validated,
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('auth.login.form')->with('status', 'パスワードを更新しました。新しいパスワードでログインしてください。')
            : back()->withErrors(['email' => __($status)]);
    }

    public function account(
        Request $request,
        ReleaseLevelService $releaseLevels,
        PersonalizationLivingProfileService $livingProfile,
    ) {
        if (! $request->user()) {
            return redirect()->route('auth.login.form');
        }

        $user = $request->user();
        if ($releaseLevels->preparationOnly()) {
            return view('preparation.account', compact('user'));
        }
        if (config('app.env') === 'staging'
            && config('canovia_staging.login_pilot_enabled') === true) {
            // The stage-only identity pilot needs no product navigation, PWA,
            // profile/Plan expansion or asset references from the normal shell.
            return view('auth.staging-pilot-account', ['user' => $user]);
        }

        $user->loadMissing('personalizationContext');

        return view('auth.account', [
            'user' => $user,
            'mcpStudyResultProposals' => McpStudyResultProposal::query()
                ->where('user_id', $user->id)
                ->where('status', McpStudyResultProposal::PENDING)
                ->where('expires_at', '>', now())
                ->with(['plan:id,user_id,title', 'task:id,plan_id,title'])
                ->orderBy('expires_at')->limit(8)->get(),
            'mcpActiveGrants' => McpDelegatedGrant::query()
                ->where('user_id', $user->id)
                ->where('status', McpDelegatedGrant::STATUS_ACTIVE)
                ->with(['plan' => fn ($query) => $query->where('user_id', $user->id)
                    ->select(['id', 'user_id', 'title', 'category', 'workspace_domain_override', 'is_public', 'is_collaborative'])])
                ->orderByDesc('updated_at')
                ->paginate(6, ['*'], 'mcp_grants_page'),
            'mcpHasCurrentConsentRecord' => McpDelegatedGrant::query()
                ->where('user_id', $user->id)
                ->where('status', McpDelegatedGrant::STATUS_ACTIVE)
                ->whereNull('revoked_at')
                ->where(static fn ($query) => $query
                    ->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->exists(),
            'mcpLinkedSubjects' => McpLinkedSubject::query()
                ->where('user_id', $user->id)
                ->where('status', McpLinkedSubject::STATUS_LINKED)
                ->whereNull('revoked_at')
                ->orderByDesc('linked_at')
                ->paginate(6, ['*'], 'mcp_subjects_page'),
            'chatgptPreparedPreferences' => DevelopmentAiSharingPreference::query()
                ->where('user_id', $user->id)
                ->where('provider_key', DevelopmentAiSharingPreference::PROVIDER_CHATGPT)
                ->where('status', DevelopmentAiSharingPreference::STATUS_PREPARED)
                ->with(['plan:id,user_id,title'])
                ->orderByDesc('updated_at')
                ->paginate(8, ['*'], 'sharing_page'),
            'personalizationContext' => $user->personalizationContext,
            'livingProfileSummary' => $livingProfile->summary($request),
            'showPersonalizationBootstrap' =>
                $releaseLevels->allowsMinimum(
                    ReleaseLevel::EarlyAccessCore,
                    $user,
                    $request,
                ),
            'showProductPreview' => $releaseLevels->allowsMinimum(
                ReleaseLevel::ProductPreview,
                $user,
                $request,
            ),
        ]);
    }

    private const ACCOUNT_DELETION_REVIEW = 'account_deletion_review';

    /**
     * Verify the current password twice before issuing a short-lived,
     * account-bound review session. This step cannot delete any data.
     * Repeating the password is not a second authentication factor.
     */
    public function prepareAccountDeletion(Request $request)
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        // Every new attempt invalidates the preceding review authorization,
        // including attempts that do not pass validation.
        $request->session()->forget(self::ACCOUNT_DELETION_REVIEW);
        $validated = $request->validate([
            'password' => ['required', 'string', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        if (! Hash::check((string) $validated['password'], (string) $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'password' => '現在のパスワードが正しくありません。',
            ]);
        }

        // Store no plaintext passwords or independent deletion credentials.
        $request->session()->put(self::ACCOUNT_DELETION_REVIEW, [
            'user_id' => (int) $user->getKey(),
            'email_fingerprint' => hash('sha256', mb_strtolower(trim((string) $user->email))),
            'password_fingerprint' => hash('sha256', (string) $user->getAuthPassword()),
            'expires_at' => now()->addMinutes(5)->timestamp,
            'reviewed_at' => null,
        ]);

        return redirect()->route('auth.account.deletion.review');
    }

    public function reviewAccountDeletion(Request $request)
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if (! $this->hasValidAccountDeletionReview($request, $user)) {
            $request->session()->forget(self::ACCOUNT_DELETION_REVIEW);
            return redirect()->route('auth.account')
                ->withErrors(['password' => '削除確認の有効期限が切れました。パスワードを再入力してください。']);
        }

        $review = $request->session()->get(self::ACCOUNT_DELETION_REVIEW);
        $review['reviewed_at'] = now()->timestamp;
        $request->session()->put(self::ACCOUNT_DELETION_REVIEW, $review);

        return response()->view('auth.account-deletion-review', ['user' => $user])
            ->header('Cache-Control', 'no-store, private');
    }

    public function cancelAccountDeletion(Request $request)
    {
        $request->session()->forget(self::ACCOUNT_DELETION_REVIEW);

        return redirect()->route('auth.account')
            ->with('status', 'アカウント削除をキャンセルしました。');
    }

    private function hasValidAccountDeletionReview(Request $request, User $user): bool
    {
        $review = $request->session()->get(self::ACCOUNT_DELETION_REVIEW);

        return is_array($review)
            && isset($review['user_id'], $review['email_fingerprint'],
                $review['password_fingerprint'], $review['expires_at'])
            && is_string($review['email_fingerprint'])
            && is_string($review['password_fingerprint'])
            && (int) $review['user_id'] === (int) $user->getKey()
            && hash_equals(
                $review['email_fingerprint'],
                hash('sha256', mb_strtolower(trim((string) $user->email))),
            )
            && hash_equals(
                $review['password_fingerprint'],
                hash('sha256', (string) $user->getAuthPassword()),
            )
            && (int) $review['expires_at'] > now()->timestamp;
    }

    public function destroyAccount(Request $request, AccountDeletionService $deletion)
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $request->validate([
            'confirm_deletion' => ['required', 'accepted'],
        ]);

        $review = $request->session()->get(self::ACCOUNT_DELETION_REVIEW);
        if (! $this->hasValidAccountDeletionReview($request, $user)
            || ! is_array($review)
            || ! isset($review['reviewed_at'])
            || ! is_int($review['reviewed_at'])
        ) {
            $request->session()->forget(self::ACCOUNT_DELETION_REVIEW);
            throw ValidationException::withMessages([
                'confirm_deletion' => '削除内容の確認が必要です。最初からやり直してください。',
            ]);
        }

        // One-time authorization, consumed before invoking irreversible deletion.
        $request->session()->forget(self::ACCOUNT_DELETION_REVIEW);
        $deletion->delete($user);

        // Do not call SessionGuard::logout() after deleting the User because
        // it can try to rotate the deleted model's remember token.
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();

        return redirect()
            ->route('home')
            ->with('status', 'Canoviaアカウントと本人所有データを削除しました。')
            ->with('account_deleted', true);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (config('app.env') === 'staging'
            && config('canovia_staging.login_pilot_enabled') === true) {
            return redirect()->route('auth.login.form')->with('status', '検証用アカウントからログアウトしました。');
        }

        return redirect()->route('home')->with('status', 'ログアウトしました。保護済みの計画は、再ログインすると表示されます。');
    }
}
