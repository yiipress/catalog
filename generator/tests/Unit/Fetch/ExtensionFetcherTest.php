<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Fetch;

use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Entry\EntryParser;
use YiiPress\Catalog\Entry\CatalogEntry;
use YiiPress\Catalog\Fetch\ExtensionFetcher;
use YiiPress\Catalog\Fetch\FetchFailedException;
use YiiPress\Catalog\Fetch\FileCache;
use YiiPress\Catalog\Fetch\GitHubApi;
use YiiPress\Catalog\Fetch\ReleaseLists;
use YiiPress\Catalog\Tests\Support\FakeHttpClient;
use YiiPress\Catalog\Tests\Support\Manifests;
use YiiPress\Catalog\Tests\Support\TempTree;
use YiiPress\Extension\ExtensionId;
use YiiPress\Extension\Release\GitHubReleaseSource;

use function array_map;
use function count;

final class ExtensionFetcherTest extends TestCase
{
    private const string REPO = 'acme/theme-dusk';

    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create();
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    public function testReadsReleasesAndTheirManifests(): void
    {
        $http = new FakeHttpClient()
            ->releases(self::REPO, ['v1.1.0' => [], 'v1.0.0' => []])
            ->manifest(self::REPO, 'v1.1.0', Manifests::theme('acme/dusk', '1.1.0'))
            ->manifest(self::REPO, 'v1.0.0', Manifests::theme('acme/dusk', '1.0.0', '>=0.1.0 <0.2'));

        $fetched = $this->fetcher($http)->fetch(self::entry());

        self::assertFalse($fetched->stale);
        self::assertSame('2026-10-29T12:00:00Z', $fetched->fetchedAt);
        self::assertCount(2, $fetched->releases);
        self::assertSame('v1.1.0', $fetched->releases[0]->info->tag);
        self::assertSame('>=0.2.0 <1.0', $fetched->releases[0]->manifest?->yiipress);
        self::assertSame([], $fetched->releases[0]->problems);
        self::assertSame('>=0.1.0 <0.2', $fetched->releases[1]->manifest?->yiipress);
    }

    public function testKeepsOnlyTheNewestReleases(): void
    {
        $releases = [];
        $http = new FakeHttpClient();
        for ($minor = 0; $minor < 12; $minor++) {
            $releases['v1.' . $minor . '.0'] = [];
            $http->manifest(self::REPO, 'v1.' . $minor . '.0', Manifests::theme('acme/dusk', '1.' . $minor . '.0'));
        }
        $http->releases(self::REPO, $releases);

        $fetched = $this->fetcher($http)->fetch(self::entry());

        self::assertCount(ExtensionFetcher::RELEASES, $fetched->releases);
        self::assertSame('v1.11.0', $fetched->releases[0]->info->tag);
        self::assertSame('v1.2.0', $fetched->releases[9]->info->tag);
        self::assertSame(1 + ExtensionFetcher::RELEASES, count($http->requests));
    }

    public function testRecordsReleaseProblemsWithoutFailing(): void
    {
        $http = new FakeHttpClient()
            ->releases(self::REPO, ['v1.3.0' => [], 'v1.2.0' => [], 'v1.1.0' => [], 'v1.0.0' => []])
            ->manifest(self::REPO, 'v1.3.0', Manifests::theme('other/dusk', '1.3.0'))
            ->manifest(self::REPO, 'v1.2.0', Manifests::theme('acme/dusk', '1.2.1'))
            ->manifest(self::REPO, 'v1.1.0', "id: acme/dusk\n")
            ->on('https://raw.githubusercontent.com/acme/theme-dusk/v1.0.0/extension.yaml', 404);

        $fetched = $this->fetcher($http)->fetch(self::entry());

        self::assertSame(['manifest_mismatch'], self::codes($fetched->releases[0]->problems));
        self::assertNull($fetched->releases[0]->manifest);
        self::assertSame(['manifest_mismatch'], self::codes($fetched->releases[1]->problems));
        self::assertContains('field_missing', self::codes($fetched->releases[2]->problems));
        self::assertSame(['manifest_missing'], self::codes($fetched->releases[3]->problems));
        self::assertFalse($fetched->hasInstallableRelease());
    }

    public function testCachesManifestsByTag(): void
    {
        $http = new FakeHttpClient()
            ->releases(self::REPO, ['v1.0.0' => []])
            ->manifest(self::REPO, 'v1.0.0', Manifests::theme('acme/dusk', '1.0.0'));
        $this->fetcher($http)->fetch(self::entry());
        $http->requests = [];

        $fetched = $this->fetcher($http)->fetch(self::entry());

        self::assertSame(['https://api.github.com/repos/acme/theme-dusk/releases?per_page=100'], $http->requests);
        self::assertNotNull($fetched->releases[0]->manifest);
    }

    public function testCachesMissingManifests(): void
    {
        $http = new FakeHttpClient()
            ->releases(self::REPO, ['v1.0.0' => []])
            ->on('https://raw.githubusercontent.com/acme/theme-dusk/v1.0.0/extension.yaml', 404);
        $this->fetcher($http)->fetch(self::entry());
        $http->requests = [];

        $fetched = $this->fetcher($http)->fetch(self::entry());

        self::assertCount(1, $http->requests);
        self::assertSame(['manifest_missing'], self::codes($fetched->releases[0]->problems));
    }

    public function testFallsBackToCachedReleaseListWhenGitHubFails(): void
    {
        $http = new FakeHttpClient()
            ->releases(self::REPO, ['v1.0.0' => []])
            ->manifest(self::REPO, 'v1.0.0', Manifests::theme('acme/dusk', '1.0.0'));
        $this->fetcher($http)->fetch(self::entry());

        $failing = new FakeHttpClient()->on('https://api.github.com/repos/acme/theme-dusk/releases?per_page=100', 502);
        $fetched = $this->fetcher($failing, '2026-10-30T12:00:00Z')->fetch(self::entry());

        self::assertTrue($fetched->stale);
        self::assertSame('2026-10-29T12:00:00Z', $fetched->fetchedAt);
        self::assertStringContainsString('HTTP status 502', (string) $fetched->staleReason);
        self::assertSame('v1.0.0', $fetched->releases[0]->info->tag);
        self::assertNotNull($fetched->releases[0]->manifest);
    }

    public function testFailsWhenGitHubFailsAndNothingIsCached(): void
    {
        $failing = new FakeHttpClient()->on('https://api.github.com/repos/acme/theme-dusk/releases?per_page=100', 502);

        try {
            $this->fetcher($failing)->fetch(self::entry());
        } catch (FetchFailedException $exception) {
            self::assertStringContainsString('extensions/acme/dusk.yaml', $exception->getMessage());
            self::assertStringContainsString('HTTP status 502', $exception->getMessage());
            return;
        }

        self::fail('No exception thrown.');
    }

    public function testOfflineModeUsesOnlyTheCache(): void
    {
        $http = new FakeHttpClient()
            ->releases(self::REPO, ['v1.0.0' => []])
            ->manifest(self::REPO, 'v1.0.0', Manifests::theme('acme/dusk', '1.0.0'));
        $this->fetcher($http)->fetch(self::entry());
        $offline = new FakeHttpClient();

        $fetched = $this->fetcher($offline, offline: true)->fetch(self::entry());

        self::assertSame([], $offline->requests);
        self::assertTrue($fetched->stale);
        self::assertCount(1, $fetched->releases);
    }

    private function fetcher(FakeHttpClient $http, string $now = '2026-10-29T12:00:00Z', bool $offline = false): ExtensionFetcher
    {
        $cache = new FileCache($this->tree->root . '/cache');

        return new ExtensionFetcher(
            new ReleaseLists(new GitHubApi($http), $cache, $now, $offline),
            new GitHubReleaseSource($http),
            $cache,
        );
    }

    private static function entry(): CatalogEntry
    {
        $id = ExtensionId::tryFromString('acme/dusk');
        self::assertNotNull($id);

        return new EntryParser()->parse($id, "repository: https://github.com/acme/theme-dusk\ncategories: [blog]\n");
    }

    /**
     * @param list<\YiiPress\Extension\Manifest\ManifestError> $problems
     * @return list<string>
     */
    private static function codes(array $problems): array
    {
        return array_map(static fn($problem): string => $problem->code, $problems);
    }
}
