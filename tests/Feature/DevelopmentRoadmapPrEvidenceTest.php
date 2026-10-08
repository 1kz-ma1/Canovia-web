<?php

namespace Tests\Feature;

use App\Services\DevelopmentGitHubRoadmapReader;
use App\Services\GitHubRepositoryWriter;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DevelopmentRoadmapPrEvidenceTest extends TestCase
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

    public function test_opt_in_verifies_merge_and_exact_pr_head_checks_without_completion(): void
    {
        $sha = str_repeat('a', 40);
        $headSha = str_repeat('b', 40);
        $mergeSha = str_repeat('c', 40);
        $markdown = "## Roadmap\n| Priority | Workstream | Existing evidence | Remaining acceptance |\n| --- | --- | --- | --- |\n| P0 | Learning | PR #12 merged | iPhone E2E pending |\n";
        $this->fakeGitHub(
            $sha,
            $markdown,
            12,
            ['number' => 12, 'state' => 'closed', 'merged' => true, 'base' => ['ref' => 'main'],
                'head' => ['sha' => $headSha], 'merge_commit_sha' => $mergeSha],
            ['total_count' => 1, 'check_runs' => [
                ['status' => 'completed', 'conclusion' => 'success'],
            ]],
        );

        $reader = app(DevelopmentGitHubRoadmapReader::class);
        $preview = $reader->read('example/repo');
        $this->assertArrayNotHasKey('github_signals', $preview['workstreams'][0]);

        $checked = $reader->read('example/repo', true);
        $signal = $checked['workstreams'][0]['github_signals'][0];
        $this->assertTrue($checked['github_evidence_checked']);
        $this->assertSame(12, $signal['number']);
        $this->assertSame('merged_default', $signal['merge_state']);
        $this->assertSame($mergeSha, $signal['merge_sha']);
        $this->assertSame('observed_pass', $signal['ci_state']);
        $this->assertSame($headSha, $signal['ci_sha']);
        $this->assertSame('unverified', $checked['workstreams'][0]['status']);

        Http::assertSent(fn (HttpRequest $request) =>
            $request->url() === 'https://api.github.com/repos/example/repo/pulls/12'
            && $request->hasHeader('Authorization', 'Bearer installation-token')
        );
        Http::assertSent(fn (HttpRequest $request) =>
            str_contains($request->url(), '/commits/'.$headSha.'/check-runs?per_page=30')
        );
        Http::assertSentCount(15);
    }

    public function test_missing_checks_permission_never_fabricates_ci_pass(): void
    {
        $sha = str_repeat('a', 40);
        $headSha = str_repeat('b', 40);
        $this->fakeGitHub(
            $sha, null, 12,
            ['number' => 12, 'state' => 'open', 'merged' => false,
                'base' => ['ref' => 'main'], 'head' => ['sha' => $headSha]],
            ['total_count' => 1, 'check_runs' => [['status' => 'completed', 'conclusion' => 'success']]],
            checkPermission: false,
        );

        $signals = app(GitHubRepositoryWriter::class)
            ->readDevelopmentRoadmapPullRequestSignals('example/repo', [12]);
        $this->assertSame('open', $signals['signals'][12]['merge_state']);
        $this->assertSame('unknown', $signals['signals'][12]['ci_state']);
        $this->assertNull($signals['signals'][12]['ci_sha']);
        $this->assertNotEmpty($signals['warning']);
        Http::assertNotSent(fn (HttpRequest $request) =>
            str_contains($request->url(), '/check-runs')
        );
    }

    public function test_missing_pr_and_failed_check_remain_distinct(): void
    {
        $this->fakeGitHub(
            str_repeat('a', 40), null, 12,
            ['number' => 12, 'state' => 'closed', 'merged' => true,
                'base' => ['ref' => 'main'], 'head' => ['sha' => str_repeat('b', 40)]],
            ['total_count' => 1, 'check_runs' => [['status' => 'completed', 'conclusion' => 'failure']]],
        );
        $result = app(GitHubRepositoryWriter::class)
            ->readDevelopmentRoadmapPullRequestSignals('example/repo', [12, 404]);
        $this->assertSame('merged_default', $result['signals'][12]['merge_state']);
        $this->assertSame('failed', $result['signals'][12]['ci_state']);
        $this->assertSame('missing', $result['signals'][404]['merge_state']);
        $this->assertSame('unknown', $result['signals'][404]['ci_state']);
    }

    public function test_private_repository_signal_fetch_fails_before_pr_request(): void
    {
        $this->fakeGitHub(str_repeat('a', 40), null, 12, [], [], private: true);
        $this->expectException(\RuntimeException::class);
        try {
            app(GitHubRepositoryWriter::class)
                ->readDevelopmentRoadmapPullRequestSignals('example/repo', [12]);
        } finally {
            Http::assertNotSent(fn (HttpRequest $request) =>
                str_contains($request->url(), '/pulls/')
            );
        }
    }

    /**
     * Fake one authorized repository and its PR/CI signals. No real GitHub requests.
     * @param array<string,mixed> $pr
     * @param array<string,mixed> $checks
     */
    private function fakeGitHub(
        string $sha,
        ?string $markdown,
        int $number,
        array $pr,
        array $checks,
        bool $checkPermission = true,
        bool $private = false,
    ): void {
        Http::fake(function (HttpRequest $request) use (
            $sha, $markdown, $number, $pr, $checks, $checkPermission, $private,
        ) {
            $url = $request->url();
            if ($url === 'https://api.github.com/repos/example/repo/installation') {
                return Http::response(['id' => 777], 200);
            }
            if ($url === 'https://api.github.com/app/installations/777/access_tokens') {
                $permissions = ['contents' => 'read', 'pull_requests' => 'read'];
                if ($checkPermission) {
                    $permissions['checks'] = 'read';
                }
                return Http::response(['token' => 'installation-token', 'permissions' => $permissions], 201);
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
                    'type' => 'file', 'encoding' => 'base64',
                    'content' => base64_encode($markdown), 'size' => strlen($markdown),
                ], 200);
            }
            if ($url === 'https://api.github.com/repos/example/repo/pulls/'.$number) {
                return Http::response($pr, 200);
            }
            if ($url === 'https://api.github.com/repos/example/repo/pulls/404') {
                return Http::response(['message' => 'Not Found'], 404);
            }
            if (str_contains($url, '/check-runs?')) {
                return Http::response($checks, 200);
            }

            return Http::response(['message' => 'unexpected '.$url], 500);
        });
    }
}
