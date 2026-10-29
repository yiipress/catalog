<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Fetch;

use JsonException;
use YiiPress\Extension\Install\InstallException;
use YiiPress\Extension\Release\GitHubRepository;

use function array_slice;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function strtolower;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Reads release lists from GitHub on every run and keeps the last good one; when GitHub fails, the kept list is
 * returned and marked stale.
 */
final readonly class ReleaseLists
{
    /**
     * @param string $now Time of this run (ISO 8601), stored with a fresh list.
     * @param bool $offline Read only the cache.
     */
    public function __construct(
        private GitHubApi $api,
        private FileCache $cache,
        private string $now,
        private bool $offline = false,
    ) {}

    /**
     * @param int|null $limit Keep only the newest releases.
     *
     * @throws InstallException GitHub failed and nothing is cached; the message is GitHub's error.
     */
    public function read(GitHubRepository $repository, ?int $limit = null): ReleaseList
    {
        $key = 'releases/' . strtolower($repository->owner . '/' . $repository->name) . '.json';
        try {
            if ($this->offline) {
                throw new InstallException('network_failed', 'Offline: only the cache is read.');
            }
            $releases = array_slice($this->api->releases($repository), 0, $limit);
            $this->cache->set($key, self::encode($this->now, $releases));

            return new ReleaseList($releases, $this->now);
        } catch (InstallException $exception) {
            $cached = self::decode($this->cache->get($key));
            if ($cached === null) {
                throw $exception;
            }

            return new ReleaseList(array_slice($cached->releases, 0, $limit), $cached->fetchedAt, $exception->getMessage());
        }
    }

    public function isOffline(): bool
    {
        return $this->offline;
    }

    /**
     * @param list<ReleaseInfo> $releases
     */
    private static function encode(string $fetchedAt, array $releases): string
    {
        $items = [];
        foreach ($releases as $release) {
            $items[] = [
                'tag' => $release->tag,
                'version' => $release->version,
                'published_at' => $release->publishedAt,
                'url' => $release->url,
            ];
        }

        return json_encode(['fetched_at' => $fetchedAt, 'releases' => $items], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function decode(?string $json): ?ReleaseList
    {
        if ($json === null) {
            return null;
        }
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data) || !is_string($data['fetched_at'] ?? null) || !is_array($data['releases'] ?? null)) {
            return null;
        }

        $releases = [];
        foreach ($data['releases'] as $item) {
            if (
                is_array($item)
                && is_string($item['tag'] ?? null)
                && is_string($item['version'] ?? null)
                && is_string($item['published_at'] ?? null)
                && is_string($item['url'] ?? null)
            ) {
                $releases[] = new ReleaseInfo($item['tag'], $item['version'], $item['published_at'], $item['url']);
            }
        }

        return new ReleaseList($releases, $data['fetched_at']);
    }
}
