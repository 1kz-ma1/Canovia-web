<?php

namespace Tests\Feature;

use App\Services\DevelopmentGitHubRoadmapReader;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DevelopmentGitHubRoadmapReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);
        $pem = '';
        $this->assertTrue(openssl_pkey_export($key, $pem));

        config([
            'services.github.api_url' => 'https://api.github.com',
            'services.github.app_id' => '12345',
            'services.github.app_private_key' => $pem,
            'services.github.app_private_key_base64' => null,
        ]);
    }

    public function test_reads_canonical_roadmap_at_pinned_sha_using_installation_token(): void
    {
        $sha = str_repeat('a', 40);
        $markdown = "## Next actions\n- Review PR #376\n";
        $this->fakeGitHub($sha, $markdown);

        $result = app(DevelopmentGitHubRoadmapReader::class)->read('example/repo');

        $this->assertSame($sha, $result['source']['sha']);
        $this->assertSame('Review PR #376', $result['sections'][0]['entries'][0]['text']);
        $this->assertSame('unverified', $result['sections'][0]['entries'][0]['status']);
        $this->assertArrayNotHasKey('task_id', $result['sections'][0]['entries'][0]);

        Http::assertSent(fn (HttpRequest $request) =>
            $request->method() === 'GET'
            && str_starts_with(
                $request->url(),
                'https://api.github.com/repos/example/repo/contents/docs/development/ROADMAP.md?',
            )
            && str_contains($request->url(), 'ref='.$sha)
            && $request->hasHeader('Authorization', 'Bearer installation-token')
        );

        Http::assertSentCount(5);
    }

    public function test_missing_canonical_document_is_explicit_and_does_not_guess_tasks(): void
    {
        $sha = str_repeat('b', 40);
        $this->fakeGitHub($sha, null);
        $result = app(DevelopmentGitHubRoadmapReader::class)->read('example/repo');

        $this->assertSame([], $result['sections']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_invalid_file_content_is_rejected(): void
    {
        $this->fakeGitHub(str_repeat('c', 40), "## Roadmap\n- item\n", corrupt: true);

        $this->expectException(\RuntimeException::class);
        app(DevelopmentGitHubRoadmapReader::class)->read('example/repo');
    }

    public function test_private_repository_is_blocked_until_actor_scoped_github_authorization_exists(): void
    {
        $this->fakeGitHub(str_repeat('e', 40), "## Private\\n- confidential", private: true);

        try {
            app(DevelopmentGitHubRoadmapReader::class)->read('example/repo');
            $this->fail('Private roadmap should not be accessible through installation token alone.');
        } catch (\\RuntimeException $exception) {
            $this->assertStringContainsString('Private / Internal Repository', $exception->getMessage());
        }

        Http::assertSentCount(3);
    }

    public function test_missing_contents_permission_is_rejected_before_file_access(): void
    {
        $this->fakeGitHub(str_repeat('d', 40), "## Roadmap\n- item\n", contentsAllowed: false);

        $this->expectException(\RuntimeException::class);
        app(DevelopmentGitHubRoadmapReader::class)->read('example/repo');
    }

    private function fakeGitHub(
        string $sha,
        ?string $markdown,
        bool $corrupt = false,
        bool $contentsAllowed = true,
        bool $private = false,
    ): void {
        Http::fake(function (HttpRequest $request) use ($sha, $markdown, $corrupt, $contentsAllowed, $private) {
            $url = $request->url();
            if ($url === 'https://api.github.com/repos/example/repo/installation') {
                return Http::response(['id' => 777], 200);
            }
            if ($url === 'https://api.github.com/app/installations/777/access_tokens') {
                return Http::response([
                    'token' => 'installation-token',
                    'permissions' => ['contents' => $contentsAllowed ? 'read' : 'none'],
                ], 201);
            }
            if ($url === 'https://api.github.com/repos/example/repo') {
                return Http::response([
                    'default_branch' => 'main',
                    'private' => $private,
                    'visibility' => $private ? 'private' : 'public',
                ], 200);
            }
            if ($url === 'https://api.github.com/repos/example/repo/commits/main') {
                return Http::response(['sha' => $sha], 200);
            }
            if (str_starts_with($url, 'https://api.github.com/repos/example/repo/contents/docs/development/ROADMAP.md?')) {
                if ($markdown === null) {
                    return Http::response(['message' => 'Not Found'], 404);
                }
                return Http::response([
                    'type' => 'file',
                    'encoding' => 'base64',
                    'content' => $corrupt ? 'invalid!!!' : base64_encode($markdown),
                    'size' => strlen($markdown),
                ], 200);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });
    }
}
