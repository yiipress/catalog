<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Fetch;

use Composer\Semver\Semver;
use JsonException;
use YiiPress\Extension\Archive\InstallLimits;
use YiiPress\Extension\Http\HttpClientInterface;
use YiiPress\Extension\Http\HttpResponse;
use YiiPress\Extension\Install\InstallException;
use YiiPress\Extension\Release\GitHubReleaseSource;
use YiiPress\Extension\Release\GitHubRepository;
use Yiisoft\Yii\Console\ExitCode;

use function array_is_list;
use function date;
use function file_get_contents;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function preg_match;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * Reads the stable releases of a repository from the GitHub API, with dates and links.
 *
 * Stable means what the installer accepts: not a draft, not a pre-release, a SemVer tag with at most one `v`.
 */
final readonly class GitHubApi
{
    private const string API = 'https://api.github.com/repos/';
    private const int MAX_PAGE_BYTES = 5 * 1024 * 1024;

    public function __construct(private HttpClientInterface $http) {}

    /**
     * @return list<ReleaseInfo> Newest version first.
     *
     * @throws InstallException `rate_limited` or `network_failed`.
     */
    public function releases(GitHubRepository $repository): array
    {
        $byVersion = [];
        $url = self::API . $repository . '/releases?per_page=100';
        for ($page = 0; $url !== null && $page < InstallLimits::API_PAGES; $page++) {
            [$response, $body] = $this->fetch($url);
            foreach ($this->decode($body, $url) as $item) {
                $release = $this->release($item, $repository);
                if ($release !== null) {
                    $byVersion[$release->version] ??= $release;
                }
            }
            $url = self::next($response);
        }

        $releases = [];
        foreach (Semver::rsort(array_keys($byVersion)) as $version) {
            $releases[] = $byVersion[$version];
        }

        return $releases;
    }

    /**
     * @param array<mixed> $item
     */
    private function release(array $item, GitHubRepository $repository): ?ReleaseInfo
    {
        if (!is_string($item['tag_name'] ?? null) || ($item['draft'] ?? false) !== false || ($item['prerelease'] ?? false) !== false) {
            return null;
        }
        $release = GitHubReleaseSource::release($item['tag_name']);
        if ($release === null) {
            return null;
        }
        $url = is_string($item['html_url'] ?? null) && preg_match('~^https://github\.com/~', $item['html_url']) === 1
            ? $item['html_url']
            : $repository->url() . '/releases/tag/' . $release->tag;

        return new ReleaseInfo(
            $release->tag,
            $release->version,
            is_string($item['published_at'] ?? null) ? $item['published_at'] : '',
            $url,
        );
    }

    /**
     * @return array{HttpResponse, string}
     */
    private function fetch(string $url): array
    {
        $file = tempnam(sys_get_temp_dir(), 'catalog-api-');
        if ($file === false) {
            throw new InstallException('filesystem_failed', 'Could not create a temporary file.');
        }
        try {
            $response = $this->http->get($url, $file, self::MAX_PAGE_BYTES);
            $body = @file_get_contents($file);
        } finally {
            @unlink($file);
        }
        self::expectOk($response, $url);

        return [$response, $body === false ? '' : $body];
    }

    /**
     * @return list<array<mixed>>
     */
    private function decode(string $body, string $url): array
    {
        try {
            $items = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $items = null;
        }
        if (!is_array($items) || !array_is_list($items)) {
            throw new InstallException(
                'network_failed',
                sprintf('GitHub answered %s with something that is not a list of releases.', $url),
                ExitCode::UNAVAILABLE,
            );
        }

        $releases = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $releases[] = $item;
            }
        }

        return $releases;
    }

    private static function expectOk(HttpResponse $response, string $url): void
    {
        if ($response->status === 200) {
            return;
        }
        $remaining = $response->header('x-ratelimit-remaining');
        if ($response->status === 429 || ($response->status === 403 && $remaining === '0')) {
            $reset = $response->header('x-ratelimit-reset');
            throw new InstallException('rate_limited', sprintf(
                'GitHub refused %s because the rate limit is reached%s. Set GITHUB_TOKEN for a higher limit, or retry'
                . ' later.',
                $url,
                $reset !== null && is_numeric($reset) ? ' until ' . date('Y-m-d H:i:s T', (int) $reset) : '',
            ), ExitCode::UNAVAILABLE);
        }

        throw new InstallException(
            'network_failed',
            sprintf('GitHub answered %s with HTTP status %d.', $url, $response->status),
            ExitCode::UNAVAILABLE,
        );
    }

    private static function next(HttpResponse $response): ?string
    {
        $link = $response->header('link');
        if ($link === null || preg_match('~<(https://api\.github\.com/[^>]+)>;\s*rel="next"~', $link, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
