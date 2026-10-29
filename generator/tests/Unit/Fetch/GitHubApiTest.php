<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Fetch;

use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Fetch\GitHubApi;
use YiiPress\Catalog\Tests\Support\FakeHttpClient;
use YiiPress\Extension\Install\InstallException;
use YiiPress\Extension\Release\GitHubRepository;

use function array_map;

final class GitHubApiTest extends TestCase
{
    public function testListsStableReleasesNewestVersionFirst(): void
    {
        $http = new FakeHttpClient()->releases('acme/theme-dusk', [
            'v1.2.0' => ['published_at' => '2026-10-03T00:00:00Z'],
            '1.10.0' => [],
            'v2.0.0-beta.1' => [],
            'v1.9.0' => ['prerelease' => true],
            'v1.8.0' => ['draft' => true],
            'nightly' => [],
            'v01.0.0' => [],
        ]);

        $releases = new GitHubApi($http)->releases(self::repository());

        self::assertSame(['1.10.0', 'v1.2.0'], array_map(static fn($release): string => $release->tag, $releases));
        self::assertSame('1.2.0', $releases[1]->version);
        self::assertSame('2026-10-03T00:00:00Z', $releases[1]->publishedAt);
        self::assertSame('https://github.com/acme/theme-dusk/releases/tag/v1.2.0', $releases[1]->url);
    }

    public function testFollowsNextPages(): void
    {
        $first = 'https://api.github.com/repos/acme/theme-dusk/releases?per_page=100';
        $second = 'https://api.github.com/repositories/1/releases?per_page=100&page=2';
        $http = new FakeHttpClient()
            ->on($first, 200, '[{"tag_name":"v2.0.0","draft":false,"prerelease":false}]', ['link' => ['<' . $second . '>; rel="next"']])
            ->on($second, 200, '[{"tag_name":"v1.0.0","draft":false,"prerelease":false}]');

        $releases = new GitHubApi($http)->releases(self::repository());

        self::assertSame(['v2.0.0', 'v1.0.0'], array_map(static fn($release): string => $release->tag, $releases));
        self::assertSame('', $releases[0]->publishedAt);
        self::assertSame('https://github.com/acme/theme-dusk/releases/tag/v2.0.0', $releases[0]->url);
    }

    public function testReportsRateLimitWithReset(): void
    {
        $http = new FakeHttpClient()->on(
            'https://api.github.com/repos/acme/theme-dusk/releases?per_page=100',
            403,
            '{}',
            ['x-ratelimit-remaining' => ['0'], 'x-ratelimit-reset' => ['1893456000']],
        );

        try {
            new GitHubApi($http)->releases(self::repository());
        } catch (InstallException $exception) {
            self::assertSame('rate_limited', $exception->errorCode);
            self::assertStringContainsString('2030-01-01', $exception->getMessage());
            self::assertStringContainsString('GITHUB_TOKEN', $exception->getMessage());
            return;
        }

        self::fail('No exception thrown.');
    }

    public function testReportsMissingRepository(): void
    {
        $http = new FakeHttpClient()->on('https://api.github.com/repos/acme/theme-dusk/releases?per_page=100', 404, '{}');

        $this->expectException(InstallException::class);
        $this->expectExceptionMessage('HTTP status 404');

        new GitHubApi($http)->releases(self::repository());
    }

    public function testReportsBodyThatIsNotAList(): void
    {
        $http = new FakeHttpClient()->on('https://api.github.com/repos/acme/theme-dusk/releases?per_page=100', 200, '{"a":1}');

        $this->expectException(InstallException::class);
        $this->expectExceptionMessage('not a list of releases');

        new GitHubApi($http)->releases(self::repository());
    }

    private static function repository(): GitHubRepository
    {
        return GitHubRepository::fromUrl('https://github.com/acme/theme-dusk');
    }
}
