<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Site;

use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Fetch\FileCache;
use YiiPress\Catalog\Site\AssetFetcher;
use YiiPress\Catalog\Tests\Support\FakeHttpClient;
use YiiPress\Catalog\Tests\Support\TempTree;
use YiiPress\Extension\Release\GitHubRepository;

final class AssetFetcherTest extends TestCase
{
    private const string URL = 'https://raw.githubusercontent.com/acme/theme-dusk/v1.0.0/screenshots/home%20page.png';

    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create();
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    public function testFetchesFileAtTheTagAndCachesIt(): void
    {
        $http = new FakeHttpClient()->on(self::URL, 200, 'PNG');

        $first = $this->fetcher($http)->fetch(self::repository(), 'v1.0.0', 'screenshots/home page.png');
        $http->requests = [];
        $second = $this->fetcher($http)->fetch(self::repository(), 'v1.0.0', 'screenshots/home page.png');

        self::assertSame('PNG', $first);
        self::assertSame('PNG', $second);
        self::assertSame([], $http->requests);
    }

    public function testMissingFileIsNullWithWarning(): void
    {
        $fetcher = $this->fetcher(new FakeHttpClient()->on(self::URL, 404));

        self::assertNull($fetcher->fetch(self::repository(), 'v1.0.0', 'screenshots/home page.png'));
        self::assertStringContainsString('HTTP status 404', $fetcher->warnings()[0]);
    }

    public function testTooLargeFileIsNullWithWarning(): void
    {
        $fetcher = $this->fetcher(new FakeHttpClient()->on(self::URL, 200, 'PNG'), maxBytes: 2);

        self::assertNull($fetcher->fetch(self::repository(), 'v1.0.0', 'screenshots/home page.png'));
        self::assertStringContainsString('larger than 2 bytes', $fetcher->warnings()[0]);
    }

    public function testOfflineReadsOnlyTheCache(): void
    {
        $http = new FakeHttpClient();

        self::assertNull($this->fetcher($http, offline: true)->fetch(self::repository(), 'v1.0.0', 'screenshots/home page.png'));
        self::assertSame([], $http->requests);
    }

    private function fetcher(FakeHttpClient $http, int $maxBytes = AssetFetcher::MAX_BYTES, bool $offline = false): AssetFetcher
    {
        return new AssetFetcher($http, new FileCache($this->tree->root . '/cache'), $offline, $maxBytes);
    }

    private static function repository(): GitHubRepository
    {
        return GitHubRepository::fromUrl('https://github.com/acme/theme-dusk');
    }
}
