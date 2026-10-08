<?php

namespace Tests\Feature;

use App\Services\DevelopmentGitHubRoadmapReader;
use App\Services\GitHubRepositoryWriter;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DevelopmentRoadmapIssueEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
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

    public function test_issue_open_closed_and_pr_collision_are_distinct(): void
    {
        $this->fakeGitHub();
        $signals = app(GitHubRepositoryWriter::class)
            ->readDevelopmentRoadmapIssueSignals('example/repo', [33, 34, 35, 404]);
        $this->assertSame('open', $signals['signals'][33]['state']);
        $this->assertSame('closed', $signals['signals'][34]['state']);
        $this->assertSame('not_issue', $signals['signals'][35]['state']);
        $this->assertSame('missing', $signals['signals'][404]['state']);
        Http::assertSentCount(7);
    }

    public function test_issue_only_roadmap_verification_never_implies_completion(): void
    {
        $this->fakeGitHub();
        $reader = app(DevelopmentGitHubRoadmapReader::class);
        $preview = $reader->read('example/repo');
        $this->assertArrayNotHasKey('issue_signals', $preview['workstreams'][0]);
        $checked = $reader->read('example/repo', true);
        $this->assertTrue($checked['github_evidence_checked']);
        $this->assertSame('open', $checked['workstreams'][0]['issue_signals'][0]['state']);
        $this->assertSame('unverified', $checked['workstreams'][0]['status']);
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), '/pulls/'));
    }

    public function test_private_repo_blocks_issue_fetch_before_issue_request(): void
    {
        $this->fakeGitHub(private: true);
        $this->expectException(\RuntimeException::class);
        try {
            app(GitHubRepositoryWriter::class)->readDevelopmentRoadmapIssueSignals('example/repo', [33]);
        } finally {
            Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), '/issues/'));
        }
    }

    public function test_missing_issues_permission_is_unknown_without_issue_request(): void
    {
        $this->fakeGitHub(issuesAllowed: false);
        $signals = app(GitHubRepositoryWriter::class)
            ->readDevelopmentRoadmapIssueSignals('example/repo', [33]);
        $this->assertSame('unknown', $signals['signals'][33]['state']);
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), '/issues/'));
    }

    private function fakeGitHub(bool $private = false, bool $issuesAllowed = true): void
    {
        Http::fake(function (HttpRequest $request) use ($private, $issuesAllowed) {
            $url = $request->url();
            if ($url === 'https://api.github.com/repos/example/repo/installation') {
                return Http::response(['id' => 777], 200);
            }
            if ($url === 'https://api.github.com/app/installations/777/access_tokens') {
                return Http::response([
                    'token' => 'installation-token',
                    'permissions' => ['contents' => 'read', 'issues' => $issuesAllowed ? 'read' : 'none'],
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
                return Http::response(['sha' => str_repeat('a', 40)], 200);
            }
            if (str_starts_with($url, 'https://api.github.com/repos/example/repo/contents/docs/development/ROADMAP.md?')) {
                $markdown = "## Workstreams\n| Priority | Workstream | Existing evidence | Remaining acceptance |\n| --- | --- | --- | --- |\n| P0 | Learning | Issue #33 | Device E2E |\n";
                return Http::response([
                    'type' => 'file', 'encoding' => 'base64',
                    'content' => base64_encode($markdown), 'size' => strlen($markdown),
                ], 200);
            }
            if ($url === 'https://api.github.com/repos/example/repo/issues/404') {
                return Http::response(['message' => 'Not Found'], 404);
            }
            if (preg_match('~/issues/(\d+)$~', $url, $matches)) {
                $number = (int) $matches[1];
                return Http::response([
                    'number' => $number,
                    'state' => $number === 34 ? 'closed' : 'open',
                    ...($number === 35 ? ['pull_request' => ['url' => 'example']] : []),
                ], 200);
            }
            return Http::response(['message' => 'unexpected'], 500);
        });
    }
}
