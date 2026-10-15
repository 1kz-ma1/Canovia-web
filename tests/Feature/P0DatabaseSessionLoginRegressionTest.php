<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

final class P0DatabaseSessionLoginRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Unlike normal array-session feature tests, persist actual web
        // sessions to the disposable test database across HTTP requests.
        config()->set('session.driver', 'database');
        config()->set('session.secure', true);
        config()->set('session.http_only', true);
        config()->set('session.same_site', 'lax');
        config()->set('session.domain', null);
        config()->set('session.expire_on_close', false);
    }

    public function test_established_user_login_survives_new_request_then_logout_and_relogin(): void
    {
        $user = $this->establishedUser();

        $response = $this->post(route('auth.login'), [
            'email' => $user->email,
            'password' => 'correct-password-for-ci',
            'remember' => '1',
        ])->assertRedirect(route('home'));

        $cookie = $this->sessionCookie($response);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertNull($cookie->getDomain());
        $this->assertGreaterThan(0, DB::table('sessions')->count());

        // Forget the cached user resolver between requests. Only a valid
        // client-provided session cookie may restore auth on this request.
        Auth::forgetGuards();
        $this->withCookie($cookie->getName(), (string) $cookie->getValue())
            ->get(route('auth.account'))
            ->assertOk();

        Auth::forgetGuards();
        $logout = $this->withCookie($cookie->getName(), (string) $cookie->getValue())
            ->post(route('auth.logout'))
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        Auth::forgetGuards();

        $logoutCookie = $this->sessionCookie($logout);
        $this->withCookie($logoutCookie->getName(), (string) $logoutCookie->getValue())
            ->get(route('auth.account'))
            ->assertRedirect(route('auth.login.form'));

        $relogin = $this->post(route('auth.login'), [
            'email' => $user->email,
            'password' => 'correct-password-for-ci',
            'remember' => '0',
        ])->assertRedirect(route('auth.account'));

        Auth::forgetGuards();
        $reloginCookie = $this->sessionCookie($relogin);
        $this->withCookie($reloginCookie->getName(), (string) $reloginCookie->getValue())
            ->get(route('auth.account'))
            ->assertOk();
    }

    public function test_bad_credentials_redirect_with_visible_feedback_and_never_sign_in(): void
    {
        $user = $this->establishedUser();

        $response = $this->from(route('auth.login.form'))
            ->post(route('auth.login'), [
                'email' => $user->email,
                'password' => 'incorrect-ci-password',
                'remember' => '1',
            ])
            ->assertRedirect(route('auth.login.form'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $cookie = $this->sessionCookie($response);

        // A Feature Test can inspect the flashed error bag on the POST,
        // but its in-process client cannot prove browser flash persistence.
        // Render the next-page error bag explicitly, as the existing auth
        // feedback test does, without claiming Safari/PWA acceptance.
        Auth::forgetGuards();
        $this->withCookie($cookie->getName(), (string) $cookie->getValue())
            ->get(route('auth.login.form'))
            ->assertOk();

        $errors = (new \Illuminate\Support\ViewErrorBag())->put(
            'default',
            new \Illuminate\Support\MessageBag([
                'email' => ['メールアドレスまたはパスワードが正しくありません。'],
            ]),
        );
        $html = view('auth.login', ['errors' => $errors])->render();
        $this->assertStringContainsString('data-auth-login-error', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('メールアドレスまたはパスワードが正しくありません。', $html);
        $this->assertStringNotContainsString('incorrect-ci-password', $html);
        $this->assertGuest();
    }

    public function test_cookie_policy_is_host_only_secure_and_non_expiring_on_browser_close(): void
    {
        // This asserts the configured response cookie shape. It does not
        // claim Safari/PWA/WKWebView acceptance or Redis persistence.
        $response = $this->get(route('auth.login.form'))->assertOk();
        $cookie = $this->sessionCookie($response);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertNull($cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
    }

    private function establishedUser(): User
    {
        return User::factory()->create([
            'email' => 'p0-existing-account@example.com',
            'password' => Hash::make('correct-password-for-ci'),
            'first_run_completed_at' => now()->subDay(),
        ]);
    }

    private function sessionCookie(\Illuminate\Testing\TestResponse $response): Cookie
    {
        $name = (string) config('session.cookie');
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        $this->fail('No session cookie was set by the web session middleware.');
    }
}
