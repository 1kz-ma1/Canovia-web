<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureMcpStagingSyntheticPilotActor;
use App\Services\McpStagingLoginPilotPolicy;
use App\Services\McpStagingSyntheticActorBootstrap;
use Illuminate\Http\Request;
use Tests\TestCase;

final class McpStagingLoginPilotPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.login_pilot_enabled' => true,
            'canovia_staging.account_link_pilot_enabled' => false,
            'canovia_mcp.discovery_enabled' => false,
            'canovia_mcp.account_link_enabled' => false,
            'canovia_mcp.auth0_jwt_verification_enabled' => false,
            'canovia_mcp.token_introspection_enabled' => false,
            'canovia_mcp.token_verification_driver' => 'auth0_jwt',
            'canovia_mcp.tools_enabled' => false,
            'canovia_mcp.plan_consent_enabled' => false,
            'canovia_mcp.delegated_policy_enabled' => false,
        ]);
    }

    public function test_login_only_pilot_allows_exact_methods_routes_and_synthetic_email(): void
    {
        $p = app(McpStagingLoginPilotPolicy::class);
        foreach ([
            ['GET', '/login'], ['HEAD', '/login'],
            ['GET', '/account'], ['HEAD', '/account'],
            ['POST', '/logout'],
        ] as [$method, $path]) {
            $this->assertTrue($p->allows(Request::create($path, $method)), "$method $path");
        }
        $this->assertTrue($p->allows(Request::create('/login', 'POST', [
            'email' => McpStagingSyntheticActorBootstrap::EMAIL,
            'password' => 'unrelated-canary',
        ])));

        foreach ([
            ['GET', '/'], ['GET', '/register'], ['POST', '/register'],
            ['GET', '/health'], ['GET', '/welcome'], ['GET', '/plans'],
            ['GET', '/admin'], ['POST', '/api/mcp'], ['GET', '/api/mcp'],
            ['GET', '/.well-known/oauth-protected-resource'],
            ['GET', '/account/mcp/link/callback'], ['POST', '/account/mcp/link/start'],
            ['POST', '/account/mcp/plans/1/consent/start'],
            ['DELETE', '/account/mcp/grants/1'],
            ['GET', '/logout'], ['GET', '/login?inject=1'],
        ] as [$method, $path]) {
            $req = Request::create($path, $method);
            $expected = $method === 'GET' && $path === '/login?inject=1';
            $this->assertSame($expected, $p->allows($req), "$method $path");
        }

        foreach (['someone@example.com', '', 'MCP-SYNTHETIC-OWNER@canovia.invalid'] as $badEmail) {
            $this->assertFalse($p->allows(Request::create('/login', 'POST', [
                'email' => $badEmail,
            ])));
        }

        config(['canovia_staging.login_pilot_enabled' => false]);
        $this->assertFalse($p->allows(Request::create('/login', 'GET')));
    }

    public function test_link_routes_require_an_independent_jwt_only_pilot_and_do_not_enable_plans(): void
    {
        $p = app(McpStagingLoginPilotPolicy::class);
        $start = Request::create('/account/mcp/link/start', 'POST');
        $callback = Request::create('/account/mcp/link/callback', 'GET');
        $this->assertFalse($p->allows($start));

        config([
            'canovia_staging.account_link_pilot_enabled' => true,
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.account_link_enabled' => true,
            'canovia_mcp.auth0_jwt_verification_enabled' => true,
        ]);
        $this->assertTrue($p->allows($start));
        $this->assertTrue($p->allows($callback));
        $this->assertTrue($p->allows(Request::create('/account/mcp/link/confirm', 'POST')));
        $this->assertTrue($p->allows(Request::create('/account/mcp/link/cancel', 'POST')));
        $this->assertFalse($p->allows(Request::create('/account/mcp/plans/1/consent/start', 'POST')));
        $this->assertFalse($p->allows(Request::create('/api/mcp', 'POST')));

        foreach ([
            ['canovia_mcp.discovery_enabled' => false],
            ['canovia_mcp.account_link_enabled' => false],
            ['canovia_mcp.auth0_jwt_verification_enabled' => false],
            ['canovia_mcp.token_introspection_enabled' => true],
            ['canovia_mcp.tools_enabled' => true],
            ['canovia_mcp.plan_consent_enabled' => true],
            ['canovia_mcp.delegated_policy_enabled' => true],
        ] as $change) {
            $key = array_key_first($change);
            $was = config($key);
            config($change);
            $this->assertFalse($p->allows($start), $key);
            config([$key => $was]);
        }
    }

    public function test_authenticated_non_synthetic_session_is_denied_without_any_identity_leak(): void
    {
        $gate = app(EnsureMcpStagingSyntheticPilotActor::class);
        $request = Request::create('/account', 'GET');
        $request->setUserResolver(fn () => (object) ['email' => 'foreign@example.invalid']);
        $response = $gate->handle($request, fn () => response('should-not-be-rendered'));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('Staging service unavailable.', $response->getContent());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));

        $request->setUserResolver(fn () => (object) [
            'email' => McpStagingSyntheticActorBootstrap::EMAIL,
        ]);
        $this->assertSame('safe', $gate->handle(
            $request,
            fn () => response('safe'),
        )->getContent());

        config(['app.env' => 'production']);
        $request->setUserResolver(fn () => (object) ['email' => 'foreign@example.invalid']);
        $this->assertSame('production-unchanged', $gate->handle(
            $request,
            fn () => response('production-unchanged'),
        )->getContent());
    }
}
