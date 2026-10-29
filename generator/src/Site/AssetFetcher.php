<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Site;

use YiiPress\Catalog\Fetch\FileCache;
use YiiPress\Extension\Http\HttpClientInterface;
use YiiPress\Extension\Install\InstallException;
use YiiPress\Extension\Release\GitHubReleaseSource;
use YiiPress\Extension\Release\GitHubRepository;

use function array_map;
use function explode;
use function file_get_contents;
use function implode;
use function rawurlencode;
use function sha1;
use function sprintf;
use function strlen;
use function strtolower;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Reads catalog files (screenshots, examples) of a release from raw.githubusercontent.com at its tag, through the
 * cache. A file that cannot be read is skipped with a warning: the page is still built without it.
 */
final class AssetFetcher
{
    public const int MAX_BYTES = 5 * 1024 * 1024;
    private const string RAW = 'https://raw.githubusercontent.com/';

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly FileCache $cache,
        private readonly bool $offline = false,
        private readonly int $maxBytes = self::MAX_BYTES,
    ) {}

    /**
     * @param string $path Catalog path from the manifest (already checked by the engine's portable path rules).
     */
    public function fetch(GitHubRepository $repository, string $tag, string $path): ?string
    {
        $key = sprintf('files/%s/%s/%s', strtolower($repository->owner . '/' . $repository->name), $tag, sha1($path));
        $cached = $this->cache->get($key);
        if ($cached !== null || $this->offline) {
            return $cached;
        }

        $url = self::RAW . $repository . '/' . rawurlencode($tag) . '/'
            . implode('/', array_map(rawurlencode(...), explode('/', $path)));
        $file = tempnam(sys_get_temp_dir(), 'catalog-asset-');
        if ($file === false) {
            $this->warnings[] = 'Could not create a temporary file.';
            return null;
        }
        try {
            $response = $this->http->get($url, $file, $this->maxBytes);
            if ($response->status !== 200) {
                $this->warnings[] = sprintf('%s: HTTP status %d; skipped.', $url, $response->status);
                return null;
            }
            $content = @file_get_contents($file);
        } catch (InstallException $exception) {
            $this->warnings[] = $exception->getMessage() . ' Skipped.';
            return null;
        } finally {
            @unlink($file);
        }
        if ($content === false) {
            return null;
        }
        if (strlen($content) > $this->maxBytes) {
            $this->warnings[] = GitHubReleaseSource::tooLarge($url, $this->maxBytes)->getMessage() . ' Skipped.';
            return null;
        }
        $this->cache->set($key, $content);

        return $content;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
