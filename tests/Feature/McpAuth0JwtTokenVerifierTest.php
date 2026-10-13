<?php

namespace Tests\Feature;

use App\Services\McpAuth0JwtTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Only locally signed synthetic RSA JWTs; no real users, secrets or Auth0
 * access tokens. Positive assertions require a VALID RSA signature, never a
 * decoded JWT body. This is NOT an actual ChatGPT OAuth E2E acceptance.
 */
final class McpAuth0JwtTokenVerifierTest extends TestCase
{
    private const ISSUER = 'https://canovia-mcp-dev.jp.auth0.com/';
    private const RESOURCE = 'https://canovia-mcp-staging.onrender.com/api/mcp';
    private const JWKS = self::ISSUER.'.well-known/jwks.json';
    private const CHATGPT_INTERNAL_CLIENT = 'synthetic-auth0-internal-client';
    private const TOKEN_KID = 'canovia-test-2026-k1';

    private string $privateKey;

    /** @var array<string, mixed> */
    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        config([
            'canovia_mcp.discovery_enabled' => true,
            'canovia_mcp.oauth_issuer' => self::ISSUER,
            'canovia_mcp.resource_url' => self::RESOURCE,
            'canovia_mcp.read_scope' => 'canovia.development.read',
            'canovia_mcp.allowed_client_id' => self::CHATGPT_INTERNAL_CLIENT,
            'canovia_mcp.auth0_jwt_verification_enabled' => true,
            'canovia_mcp.tools_enabled' => false,
            'canovia_mcp.token_introspection_enabled' => false,
            'canovia_mcp.delegated_policy_enabled' => false,
        ]);

        [$this->privateKey, $this->jwk] = $this->generateKey(self::TOKEN_KID);
    }

    private function verifier(): McpAuth0JwtTokenVerifier
    {
        return app(McpAuth0JwtTokenVerifier::class);
    }

    /** @return array{string,array<string, mixed>} */
    private function generateKey(string $kid): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($key === false || ! openssl_pkey_export($key, $privateKey)) {
            throw new RuntimeException('Synthetic test RSA key generation failed.');
        }
        $details = openssl_pkey_get_details($key);
        if (! is_array($details)) {
            throw new RuntimeException('Synthetic test RSA public-key extraction failed.');
        }

        return [$privateKey, [
            'kid' => $kid,
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ]];
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function validClaims(): array
    {
        return [
            'iss' => self::ISSUER,
            'sub' => 'auth0|synthetic-owner',
            'aud' => self::RESOURCE,
            'azp' => self::CHATGPT_INTERNAL_CLIENT,
            'iat' => time() - 30,
            'nbf' => time() - 10,
            'exp' => time() + 900,
            'scope' => 'openid canovia.development.read',
        ];
    }

    /** @param array<string, mixed> $changes */
    private function token(array $changes = [], ?string $key = null, ?string $kid = null, string $alg = 'RS256'): string
    {
        return JWT::encode(
            array_replace($this->validClaims(), $changes),
            $key ?? $this->privateKey,
            $alg,
            $kid ?? self::TOKEN_KID,
        );
    }

    /** @param array<string, mixed>|string $body */
    private function fakeJwks(array|string $body, int $status = 200, string $type = 'application/json'): void
    {
        Http::fake([
            self::JWKS => Http::response($body, $status, ['Content-Type' => $type]),
        ]);
    }

    public function test_disabled_gate_rejects_even_valid_signed_jwt_without_network(): void
    {
        config(['canovia_mcp.auth0_jwt_verification_enabled' => false]);
        $this->assertFalse($this->verifier()->isConfigured());
        $this->assertNull($this->verifier()->verify($this->token()));
        Http::assertNothingSent();
    }

    public function test_correct_signature_and_exact_claims_yield_only_verified_principal(): void
    {
        $this->fakeJwks(['keys' => [$this->jwk]]);
        $this->assertTrue($this->verifier()->isConfigured());

        $principal = $this->verifier()->verify($this->token());
        $this->assertNotNull($principal);
        $this->assertSame(self::ISSUER, $principal->issuer);
        $this->assertSame('auth0|synthetic-owner', $principal->subject);
        $this->assertSame(self::RESOURCE, $principal->audience);
        $this->assertSame(self::CHATGPT_INTERNAL_CLIENT, $principal->clientId);
        $this->assertContains('canovia.development.read', $principal->scopes);
        Http::assertSentCount(1);

        // Valid cached JWKS avoids another network fetch on the next call.
        $this->assertNotNull($this->verifier()->verify($this->token()));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) =>
            $request->url() === self::JWKS
            && $request->method() === 'GET'
            && ! $request->hasHeader('Authorization')
        );
    }

    public function test_modified_payload_or_signature_and_wrong_signing_key_fail(): void
    {
        $this->fakeJwks(['keys' => [$this->jwk]]);
        $valid = $this->token();
        [$header, $payload, $signature] = explode('.', $valid);
        $tamperedPayload = self::base64Url(json_encode(array_replace($this->validClaims(), [
            'sub' => 'auth0|attacker',
        ]), JSON_THROW_ON_ERROR));

        $this->assertNull($this->verifier()->verify($header.'.'.$tamperedPayload.'.'.$signature));
        $this->assertNull($this->verifier()->verify($header.'.'.$payload.'.'.str_repeat('a', strlen($signature))));
        [$attackerPrivate] = $this->generateKey(self::TOKEN_KID);
        $this->assertNull($this->verifier()->verify($this->token([], $attackerPrivate)));
    }

    public function test_alg_confusion_rejects_hs256_none_and_embedded_jwk_headers(): void
    {
        $this->fakeJwks(['keys' => [$this->jwk]]);
        $claims = $this->validClaims();
        $this->assertNull($this->verifier()->verify(JWT::encode($claims, str_repeat('x', 64), 'HS256', self::TOKEN_KID)));

        $token = $this->token();
        [$header, $payload, $sig] = explode('.', $token);
        $variants = [
            ['alg' => 'none', 'kid' => self::TOKEN_KID],
            ['alg' => 'RS256', 'kid' => self::TOKEN_KID, 'jku' => 'https://evil.invalid/jwks'],
            ['alg' => 'RS256', 'kid' => self::TOKEN_KID, 'jwk' => $this->jwk],
            ['alg' => 'RS256', 'kid' => self::TOKEN_KID, 'crit' => ['untrusted']],
            ['alg' => 'RS256', 'kid' => ['untrusted']],
            ['alg' => 'RS256'],
            ['alg' => 'RS256', 'kid' => self::TOKEN_KID, 'typ' => 'unknown'],
        ];
        foreach ($variants as $headerPayload) {
            $forged = self::base64Url(json_encode($headerPayload, JSON_THROW_ON_ERROR)).'.'.$payload.'.'.$sig;
            $this->assertNull($this->verifier()->verify($forged));
        }

        $this->assertNull($this->verifier()->verify('not-a-jwt'));
        $this->assertNull($this->verifier()->verify(str_repeat('a', 9000)));
    }

    public function test_exact_signed_claims_require_issuer_approved_audiences_client_scope_and_expiry(): void
    {
        $this->fakeJwks(['keys' => [$this->jwk]]);
        foreach ([
            ['iss' => 'https://other.jp.auth0.com/'],
            ['aud' => 'https://unrelated.example/api'],
            ['aud' => [self::ISSUER.'userinfo']],
            ['aud' => [self::RESOURCE, self::ISSUER.'userinfo', 'https://evil.example/api']],
            ['aud' => [self::RESOURCE, 'https://evil.example/userinfo']],
            ['aud' => [self::RESOURCE, self::RESOURCE]],
            ['azp' => 'unrecognized-client'],
            ['scope' => 'openid'],
            ['scope' => 'canovia.development.read:write'],
            ['iat' => time() - 4001],
            ['exp' => time() + 3601],
            ['exp' => time() - 1],
            ['nbf' => time() + 20],
            ['sub' => ''],
        ] as $diff) {
            $this->assertNull($this->verifier()->verify($this->token($diff)),
                'Incorrect claim must never authorize: '.implode(',', array_keys($diff)));
        }
        $this->assertNotNull($this->verifier()->verify($this->token([
            'aud' => [self::RESOURCE, self::ISSUER.'userinfo'],
        ])));
        $this->assertNotNull($this->verifier()->verify($this->token([
            'aud' => [self::ISSUER.'userinfo', self::RESOURCE],
        ])));
        $this->assertNull($this->verifier()->verify($this->token([
            'aud' => [self::RESOURCE, self::ISSUER.'userinfo'],
            'scope' => 'canovia.development.read',
        ])));

        // Signed tokens with BOTH claims are accepted only if each equals
        // the same allowlisted OAuth client; conflicting signatures are not
        // enough for authorization.
        $this->assertNotNull($this->verifier()->verify($this->token([
            'client_id' => self::CHATGPT_INTERNAL_CLIENT,
        ])));
        $this->assertNull($this->verifier()->verify($this->token([
            'client_id' => 'foreign-client',
        ])));
        $this->assertNull($this->verifier()->verify($this->token([
            'azp' => 'foreign-client',
            'client_id' => self::CHATGPT_INTERNAL_CLIENT,
        ])));

        $rfc9068 = $this->validClaims();
        unset($rfc9068['azp']);
        $rfc9068['client_id'] = self::CHATGPT_INTERNAL_CLIENT;
        $this->assertNotNull($this->verifier()->verify(JWT::encode($rfc9068, $this->privateKey, 'RS256', self::TOKEN_KID)));
    }

    public function test_unknown_kid_refreshes_once_for_rotation_and_blocks_repeated_spray(): void
    {
        [$newPrivate, $newJwk] = $this->generateKey('rotated-2026-k2');

        $sequence = Http::fakeSequence();
        $sequence->push(['keys' => [$this->jwk]], 200, ['Content-Type' => 'application/json']);
        $sequence->push(['keys' => [$newJwk]], 200, ['Content-Type' => 'application/json']);

        $this->assertNotNull($this->verifier()->verify($this->token()));
        $rotated = $this->token([], $newPrivate, 'rotated-2026-k2');
        $this->assertNotNull($this->verifier()->verify($rotated));
        Http::assertSentCount(2);

        // Unknown kid after rotation triggers a single refresh (per minute)
        // and cannot result in unbounded JWK requests.
        $unknown = $this->token([], $newPrivate, 'unknown-key-3');
        $this->assertNull($this->verifier()->verify($unknown));
        $this->assertNull($this->verifier()->verify($unknown));
        Http::assertSentCount(2);
    }

    public function test_invalid_jwks_and_provider_responses_fail_closed(): void
    {
        $variants = [
            [['keys' => []], 200, 'application/json'],
            [['keys' => [array_replace($this->jwk, ['alg' => 'HS256'])]], 200, 'application/json'],
            [['keys' => [array_replace($this->jwk, ['kty' => 'oct'])]], 200, 'application/json'],
            [['keys' => [$this->jwk, $this->jwk]], 200, 'application/json'],
            [['keys' => array_fill(0, 9, $this->jwk)], 200, 'application/json'],
            [['keys' => [array_replace($this->jwk, ['n' => 'tiny'])]], 200, 'application/json'],
            ['not-json', 200, 'text/html'],
            [['keys' => [$this->jwk]], 302, 'application/json'],
            [['keys' => [$this->jwk]], 503, 'application/json'],
            [str_repeat('a', 12500), 200, 'application/json'],
        ];
        foreach ($variants as [$data, $status, $type]) {
            Cache::flush();
            $this->fakeJwks($data, $status, $type);
            $this->assertNull($this->verifier()->verify($this->token()));
        }
    }

    public function test_untrusted_issuer_cannot_fetch_urls_even_when_gate_on(): void
    {
        foreach ([
            'https://evil.invalid/',
            'http://canovia-mcp-dev.jp.auth0.com/',
            'https://canovia-mcp-dev.jp.auth0.com:444/',
            'https://canovia-mcp-dev.jp.auth0.com/extra',
            'https://canovia-mcp-dev.jp.auth0.com/?redirect=http://evil.invalid',
        ] as $issuer) {
            config(['canovia_mcp.oauth_issuer' => $issuer]);
            $this->assertFalse($this->verifier()->isConfigured());
            $this->assertNull($this->verifier()->verify($this->token()));
        }
        Http::assertNothingSent();
    }
}
