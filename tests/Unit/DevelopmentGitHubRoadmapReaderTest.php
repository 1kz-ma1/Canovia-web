<?php

namespace Tests\Unit;

use App\Services\DevelopmentGitHubRoadmapReader;
use App\Services\DevelopmentRoadmapMarkdownParser;
use App\Services\GitHubRepositoryWriter;
use Illuminate\Http\Client\PendingRequest;
use Mockery;
use Tests\TestCase;

final class DevelopmentGitHubRoadmapReaderTest extends TestCase
{
    public function test_it_reads_at_pinned_commit_and_never_writes_tasks(): void
    {
        $sha = str_repeat('a', 40);
        $this->app['config']->set('services.github.api_url', 'https://api.github.com');
        \Illuminate\Support\Facades\Http::fake([
            'api.github.com/repos/example/repo/commits/main' => \Illuminate\Support\Facades\Http::response(['sha' => $sha]),
            'api.github.com/repos/example/repo/contents/docs/development/ROADMAP.md*' => \Illuminate\Support\Facades\Http::response([
                'type' => 'file', 'encoding' => 'base64',
                'content' => base64_encode("## Next\n- Review PR"),
                'size' => strlen("## Next\n- Review PR"),
            ]),
            'api.github.com/repos/example/repo' => \Illuminate\Support\Facades\Http::response(['default_branch' => 'main']),
        ]);
        $writer = Mockery::mock(GitHubRepositoryWriter::class);
        $writer->shouldReceive('developmentReadContext')->once()->with('example/repo', 'contents', 'Contents')->andReturn([
            'repo_path' => 'example/repo',
            'client' => \Illuminate\Support\Facades\Http::baseUrl('https://api.github.com'),
        ]);
        $result = (new DevelopmentGitHubRoadmapReader($writer, new DevelopmentRoadmapMarkdownParser))->read('example/repo');
        $this->assertSame($sha, $result['source']['sha']);
        $this->assertSame('unverified', $result['sections'][0]['entries'][0]['status']);
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => $request->method() === 'GET');
    }
}
