<?php

namespace Tests\Unit;

use App\Services\McpJwtClaimConstraints;
use PHPUnit\Framework\TestCase;

/**
 * Claims-only contract tests. Synthetic arrays do not establish real ChatGPT
 * token acceptance or bypass the separate RS256 signature and consent gates.
 */
final class McpJwtClaimConstraintsTest extends TestCase
{
    private const ISSUER = 'https://canovia-mcp-dev.jp.auth0.com/';
    private const RESOURCE = 'https://canovia-mcp-staging.onrender.com/api/mcp';
    private const CHATGPT_INTERNAL_CLIENT = 'auth0-registered-client-id-verified-out-of-band';
    private const CANOVIA_LINK_CLIENT = 'canovia-link-separate-client';

    private const NOW = 1791750000;

    /** @return array<string,mixed> */
    private function validClaims(): array
    {
        return [
            'iss' => self::ISSUER,
            'sub' => 'auth0|test-human-subject',
            'aud' => self::RESOURCE,
            'azp' => self::CHATGPT_INTERNAL_CLIENT,
            'exp' => self::NOW + 1200,
            'iat' => self::NOW - 10,
            'nbf' => self::NOW - 5,
            'scope' => 'openid canovia.development.read',
        ];
    }

    private function acceptsClaims(array $claims, string $client = self::CHATGPT_INTERNAL_CLIENT): bool
    {
        return (new McpJwtClaimConstraints)->matches(
            $claims,
            self::ISSUER,
            self::RESOURCE,
            $client,
            self::NOW,
        );
    }

    public function test_exact_single_resource_and_auth0_default_azp_profile_pass_synthetic_constraints(): void
    {
        $this->assertTrue($this->acceptsClaims($this->validClaims()));
        $claims = $this->validClaims();
        $claims['aud'] = [self::RESOURCE];
        $this->assertTrue($this->acceptsClaims($claims));
    }

    public function test_auth0_openid_access_token_with_exact_resource_and_issuer_userinfo_is_accepted(): void
    {
        $claims = $this->validClaims();
        $userinfo = self::ISSUER.'userinfo';

        $claims['aud'] = [self::RESOURCE, $userinfo];
        $this->assertTrue($this->acceptsClaims($claims));

        $claims['aud'] = [$userinfo, self::RESOURCE];
        $this->assertTrue($this->acceptsClaims($claims));

        unset($claims['azp']);
        $claims['client_id'] = self::CHATGPT_INTERNAL_CLIENT;
        $this->assertTrue($this->acceptsClaims($claims));
        $this->assertFalse($this->acceptsClaims($claims, self::CANOVIA_LINK_CLIENT));
    }

    public function test_openid_dual_audience_never_accepts_extra_or_foreign_targets(): void
    {
        $userinfo = self::ISSUER.'userinfo';
        $claims = $this->validClaims();
        foreach ([
            [$userinfo],
            [self::RESOURCE, self::RESOURCE],
            [self::RESOURCE, $userinfo, $userinfo],
            [self::RESOURCE, $userinfo, 'https://evil.example.test'],
            [self::RESOURCE, 'https://evil.example.test/userinfo'],
            [self::RESOURCE, 'https://other.auth0.com/userinfo'],
            [self::RESOURCE, 'https://canovia-mcp-dev.jp.auth0.com/userinfo/'],
        ] as $audience) {
            $claims['aud'] = $audience;
            $this->assertFalse($this->acceptsClaims($claims));
        }

        // The pair is an Auth0 OpenID Connect exception, not generic
        // permission to carry secondary audiences without OIDC.
        $claims['aud'] = [self::RESOURCE, $userinfo];
        $claims['scope'] = 'canovia.development.read';
        $this->assertFalse($this->acceptsClaims($claims));
        $claims['scope'] = 'openid';
        $this->assertFalse($this->acceptsClaims($claims));
    }

    public function test_rfc9068_client_id_profile_can_match_only_exact_registered_client(): void
    {
        $claims = $this->validClaims();
        unset($claims['azp']);
        $claims['client_id'] = self::CHATGPT_INTERNAL_CLIENT;
        $this->assertTrue($this->acceptsClaims($claims));
        $this->assertFalse($this->acceptsClaims($claims, self::CANOVIA_LINK_CLIENT));
    }

    public function test_wrong_audience_client_and_issuer_fail_closed(): void
    {
        $valid = $this->validClaims();
        $variants = [
            ['iss' => 'https://wrong.jp.auth0.com/'],
            ['iss' => rtrim(self::ISSUER, '/')],
            ['aud' => 'https://other.example.test/api/mcp'],
            ['aud' => ['https://canovia-mcp-dev.jp.auth0.com/userinfo']],
            ['aud' => []],
            ['aud' => null],
            ['aud' => [self::RESOURCE, self::RESOURCE]],
            ['azp' => 'https://chatgpt.com/oauth/client.json'],
            ['azp' => 'mismatched-internal-client'],
            ['sub' => ''],
            ['sub' => ' account '],
            ['sub' => "account\ninjection"],
            ['sub' => str_repeat('s', 256)],
        ];
        foreach ($variants as $variant) {
            $this->assertFalse(
                $this->acceptsClaims(array_replace($valid, $variant)),
                'Invalid verified-claim variant must fail closed: '.implode(',', array_keys($variant)),
            );
        }

        $this->assertFalse($this->acceptsClaims($valid, self::CANOVIA_LINK_CLIENT));
        $this->assertFalse((new McpJwtClaimConstraints)->matches(
            $valid, self::ISSUER, self::RESOURCE, '', self::NOW,
        ));
    }

    public function test_absent_ambiguous_and_wrong_typed_client_claims_fail_closed(): void
    {
        $valid = $this->validClaims();
        $noClient = $valid;
        unset($noClient['azp']);
        $this->assertFalse($this->acceptsClaims($noClient));

        $both = $valid;
        $both['client_id'] = self::CHATGPT_INTERNAL_CLIENT;
        $this->assertFalse($this->acceptsClaims($both));

        $wrongType = $valid;
        $wrongType['azp'] = [self::CHATGPT_INTERNAL_CLIENT];
        $this->assertFalse($this->acceptsClaims($wrongType));

        $bothDifferent = $valid;
        $bothDifferent['client_id'] = 'attacker-client';
        $this->assertFalse($this->acceptsClaims($bothDifferent));
    }

    public function test_expiry_issued_at_and_not_before_boundaries_fail_closed(): void
    {
        $valid = $this->validClaims();
        $variants = [
            ['exp' => self::NOW],
            ['exp' => self::NOW - 1],
            ['exp' => self::NOW + 3601],
            ['exp' => (string) (self::NOW + 1200)],
            ['iat' => self::NOW + 1],
            ['iat' => self::NOW - 4000],
            ['iat' => 'not-an-integer'],
            ['iat' => null],
            ['iat' => self::NOW + 1300],
            ['nbf' => self::NOW + 1],
            ['nbf' => 'invalid'],
            ['nbf' => self::NOW + 1300],
        ];
        foreach ($variants as $variant) {
            $this->assertFalse($this->acceptsClaims(array_replace($valid, $variant)));
        }
        $this->assertTrue($this->acceptsClaims(array_replace($valid, [
            'exp' => self::NOW + 3600,
            'iat' => self::NOW,
            'nbf' => self::NOW,
        ])));
    }

    public function test_only_exact_development_read_scope_grants_pass(): void
    {
        $valid = $this->validClaims();
        foreach ([
            ['scope' => null],
            ['scope' => ['canovia.development.read']],
            ['scope' => 'canovia.development.read:write'],
            ['scope' => 'canovia.development'],
            ['scope' => 'openid'],
            ['scope' => 'canovia.development.read  openid'],
            ['scope' => ' canovia.development.read'],
            ['scope' => "canovia.development.read\nadmin"],
            ['scope' => str_repeat('s', 513)],
        ] as $variant) {
            $this->assertFalse($this->acceptsClaims(array_replace($valid, $variant)));
        }
        $this->assertTrue($this->acceptsClaims(array_replace($valid, [
            'scope' => 'canovia.development.read',
        ])));
    }
}
