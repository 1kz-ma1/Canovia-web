<?php

namespace App\Http\Controllers;

use App\Enums\ReleaseLevel;
use App\Models\User;
use App\Models\DevelopmentAiSharingPreference;
use App\Models\McpDelegatedGrant;
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
        $remember = $request->has('remember') ? $request->boolean('remember') : true;

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

        $defaultHome = route('home');

        return redirect()->intended($defaultHome)->with(
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

        $status = Password::sendResetLink($request->only('email'));

        // Do not reveal whether an address is registered.
        if (in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER], true)) {
            return back()->with('status', '登録済みのメールアドレスであれば、再設定用リンクを送信しました。');
        }

        return back()->withErrors(['email' => 'しばらく待ってから、もう一度お試しください。']);
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
        $user->loadMissing('personalizationContext');

        return view('auth.account', [
            'user' => $user,
            'mcpActiveGrants' => McpDelegatedGrant::query()
                ->where('user_id', $user->id)
                ->where('status', McpDelegatedGrant::STATUS_ACTIVE)
                ->with(['plan:id,user_id,title'])
                ->orderByDesc('updated_at')
                ->paginate(6, ['*'], 'mcp_grants_page'),
            'mcpLinkedSubjects' => McpLinkedSubject::query()
                ->where('user_id', $user->id)
                ->where('status', McpLinkedSubject::STATUS_LINKED)
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

    public function destroyAccount(
        Request $request,
        AccountDeletionService $deletion,
    ) {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $validated = $request->validate([
            'password' => ['required', 'string'],
            'confirmation_email' => ['required', 'email'],
        ]);

        if (! Hash::check((string) $validated['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'password' => '現在のパスワードが正しくありません。',
            ]);
        }

        if (
            mb_strtolower(trim((string) $validated['confirmation_email']))
            !== mb_strtolower(trim((string) $user->email))
        ) {
            throw ValidationException::withMessages([
                'confirmation_email' => '確認用メールアドレスが現在のアカウントと一致しません。',
            ]);
        }

        $deletion->delete($user);

        // Do not call SessionGuard::logout() after deleting the User because
        // it can try to rotate the deleted model's remember token.
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::forgetGuards();

        return redirect()
            ->route('home')
            ->with('status', 'Canoviaアカウントと本人所有データを削除しました。');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'ログアウトしました。保護済みの計画は、再ログインすると表示されます。');
    }
}
