<?php

namespace Tests\Feature;

use App\Services\McpStagingAuthFailureDiagnostic;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

final class McpStagingAuthFailureDiagnosticTest extends TestCase
{
    private const ISSUER = 'https://canovia-mcp-dev.jp.auth0.com/';
    private const RESOURCE = 'https://canovia-mcp-staging.onrender.com/api/mcp';
    private const CLIENT = 'invented-signed-jwt-client';
    private const NOW = 1791900000;

    /** @return array<string,mixed> */
    private function claims(): array
    {
        return [
            'iss' => self::ISSUER,
            'sub' => 'auth0|synthetic',
            'aud' => [self::RESOURCE, self::ISSUER.'userinfo'],
            'azp' => self::CLIENT,
            'exp' => self::NOW + 600,
            'iat' => self::NOW - 50,
            'scope' => 'openid canovia.development.read',
        ];
    }

    public function test_only_static_categories_are_emitted_on_isolated_stage(): void
    {
        config([
            'app.env' => 'staging',
            'canovia_staging.isolated' => true,
            'canovia_staging.synthetic_read_pilot_enabled' => true,
            'canovia_staging.mcp_explicitly_approved' => true,
        ]);
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')->once()->with(
            'mcp_stage_auth_failure',
            ['category' => 'jwt_claims_audience'],
        );
        Log::shouldReceive('channel')->once()->with('stderr')->andReturn($logger);

        McpStagingAuthFailureDiagnostic::record('Bearer secret-should-not-be-logged');
        McpStagingAuthFailureDiagnostic::record('jwt_claims_audience');
    }

    public function test_non_staging_or_unapproved_pilot_never_emits_diagnostics(): void
    {
        Log::shouldReceive('channel')->never();

        config(['app.env' => 'production', 'canovia_staging.isolated' => true,
            'canovia_staging.synthetic_read_pilot_enabled' => true,
            'canovia_staging.mcp_explicitly_approved' => true]);
        McpStagingAuthFailureDiagnostic::record('jwt_claims_scope');

        config(['app.env' => 'staging', 'canovia_staging.isolated' => false]);
        McpStagingAuthFailureDiagnostic::record('jwt_claims_scope');

        config(['canovia_staging.isolated' => true,
            'canovia_staging.synthetic_read_pilot_enabled' => false]);
        McpStagingAuthFailureDiagnostic::record('jwt_claims_scope');

        config(['canovia_staging.synthetic_read_pilot_enabled' => true,
            'canovia_staging.mcp_explicitly_approved' => false]);
        McpStagingAuthFailureDiagnostic::record('jwt_claims_scope');
    }

    public function test_only_classifies_verified_claims_and_never_returns_claim_values(): void
    {
        $claims = $this->claims();
        foreach ([
            [['iss' => 'https://wrong.auth0.com/'], 'jwt_claims_issuer'],
            [['aud' => [self::ISSUER.'userinfo']], 'jwt_claims_audience'],
            [['azp' => 'foreign-client'], 'jwt_claims_client'],
            [['scope' => 'openid'], 'jwt_claims_scope'],
            [['exp' => self::NOW - 1], 'jwt_claims_time'],
            [['sub' => ''], 'jwt_claims_subject'],
        ] as [$changes, $expected]) {
            $category = McpStagingAuthFailureDiagnostic::classifyVerifiedClaims(
                array_replace($claims, $changes),
                self::ISSUER, self::RESOURCE, self::CLIENT, self::NOW,
            );
            $this->assertSame($expected, $category);
            $this->assertStringNotContainsString('foreign-client', $category);
        }

        $this->assertSame('jwt_claims_other',
            McpStagingAuthFailureDiagnostic::classifyVerifiedClaims(
                $claims, self::ISSUER, self::RESOURCE, self::CLIENT, self::NOW,
            ));
    }
}
