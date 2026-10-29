<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Build;

use YiiPress\Catalog\Catalog\CatalogLoader;
use YiiPress\Catalog\Fetch\ExtensionFetcher;
use YiiPress\Catalog\Fetch\FileCache;
use YiiPress\Catalog\Fetch\GitHubApi;
use YiiPress\Catalog\Fetch\ReleaseLists;
use YiiPress\Catalog\Http\GitHubTokenHttpClient;
use YiiPress\Catalog\Site\AssetFetcher;
use YiiPress\Extension\Http\HttpClientInterface;
use YiiPress\Extension\Http\RetryingHttpClient;
use YiiPress\Extension\Release\GitHubReleaseSource;

/**
 * Wires the generator and the checker around one HTTP client and one cache directory.
 */
final readonly class Services
{
    private function __construct(
        private HttpClientInterface $http,
        private FileCache $cache,
        private string $now,
        private bool $offline,
    ) {}

    public static function create(HttpClientInterface $http, string $cacheDirectory, string $now, bool $offline = false): self
    {
        return new self($http, new FileCache($cacheDirectory), $now, $offline);
    }

    /**
     * The production client: ext-curl with the token for api.github.com, retried on transient failures.
     */
    public static function http(#[\SensitiveParameter] string $token): HttpClientInterface
    {
        return new RetryingHttpClient(new GitHubTokenHttpClient($token));
    }

    public function generator(): Generator
    {
        return new Generator(new CatalogLoader(), $this->lists(), $this->fetcher(), new AssetFetcher($this->http, $this->cache, $this->offline));
    }

    public function checker(): Checker
    {
        return new Checker(new CatalogLoader(), $this->fetcher());
    }

    private function lists(): ReleaseLists
    {
        return new ReleaseLists(new GitHubApi($this->http), $this->cache, $this->now, $this->offline);
    }

    private function fetcher(): ExtensionFetcher
    {
        return new ExtensionFetcher($this->lists(), new GitHubReleaseSource($this->http), $this->cache);
    }

    public function now(): string
    {
        return $this->now;
    }
}
