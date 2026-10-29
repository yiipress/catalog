<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Fetch;

use JsonException;
use YiiPress\Catalog\Entry\CatalogEntry;
use YiiPress\Extension\Install\InstallException;
use YiiPress\Extension\Manifest\ExtensionManifest;
use YiiPress\Extension\Manifest\InvalidManifestException;
use YiiPress\Extension\Manifest\ManifestError;
use YiiPress\Extension\Manifest\ManifestParser;
use YiiPress\Extension\Release\GitHubReleaseSource;
use YiiPress\Extension\Release\GitHubRepository;
use YiiPress\Extension\Release\ReleaseSelector;

use function array_slice;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;
use function strtolower;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Reads the newest releases of an entry and their manifests, through the cache.
 *
 * A manifest is read once per tag and kept: the catalog treats a published tag as immutable. The release list is
 * read on every run; when GitHub fails, the last list from the cache is used and marked stale.
 */
final readonly class ExtensionFetcher
{
    public const int RELEASES = 10;

    /**
     * @param string $now Time of this run (ISO 8601), stored with a fresh release list.
     * @param bool $offline Read only the cache.
     */
    public function __construct(
        private GitHubApi $api,
        private GitHubReleaseSource $source,
        private FileCache $cache,
        private string $now,
        private bool $offline = false,
    ) {}

    /**
     * @throws FetchFailedException GitHub failed and the cache has no release list for the entry.
     */
    public function fetch(CatalogEntry $entry): FetchedExtension
    {
        $repository = $entry->repository;
        $listKey = 'releases/' . self::key($repository) . '.json';
        $stale = false;
        $reason = null;
        $fetchedAt = $this->now;
        try {
            if ($this->offline) {
                throw new InstallException('network_failed', 'Offline: only the cache is read.');
            }
            $releases = array_slice($this->api->releases($repository), 0, self::RELEASES);
            $this->cache->set($listKey, self::encode($fetchedAt, $releases));
        } catch (InstallException $exception) {
            $cached = self::decode($this->cache->get($listKey));
            if ($cached === null) {
                throw new FetchFailedException(sprintf(
                    '%s: could not read the releases of %s, and nothing is cached: %s',
                    $entry->file(),
                    $repository->url(),
                    $exception->getMessage(),
                ), 0, $exception);
            }
            [$fetchedAt, $releases] = $cached;
            $stale = true;
            $reason = $exception->getMessage();
        }

        $records = [];
        foreach ($releases as $release) {
            $records[] = $this->record($entry, $release);
        }

        return new FetchedExtension($entry, $records, $fetchedAt, $stale, $reason);
    }

    private function record(CatalogEntry $entry, ReleaseInfo $release): ReleaseRecord
    {
        try {
            $yaml = $this->manifestText($entry->repository, $release);
        } catch (InstallException $exception) {
            return new ReleaseRecord($release, null, [new ManifestError($exception->errorCode, '', $exception->getMessage())]);
        }
        if ($yaml === null) {
            return new ReleaseRecord($release, null, [new ManifestError(
                'manifest_missing',
                '',
                sprintf('Release %s has no extension.yaml at the repository root.', $release->tag),
            )]);
        }

        try {
            $manifest = new ManifestParser()->parse($yaml);
        } catch (InvalidManifestException $exception) {
            return new ReleaseRecord($release, null, $exception->errors());
        }

        $problem = $this->mismatch($entry, $release, $manifest);

        return $problem === null
            ? new ReleaseRecord($release, $manifest)
            : new ReleaseRecord($release, null, [$problem]);
    }

    private function mismatch(CatalogEntry $entry, ReleaseInfo $release, ExtensionManifest $manifest): ?ManifestError
    {
        if ((string) $manifest->id !== (string) $entry->id) {
            return new ManifestError('manifest_mismatch', 'id', sprintf(
                'Release %s declares id %s; the catalog entry is %s.',
                $release->tag,
                $manifest->id,
                $entry->id,
            ));
        }
        try {
            ReleaseSelector::checkMatches($manifest, $release->release(), $manifest->type, $entry->repository);
        } catch (InstallException $exception) {
            return new ManifestError($exception->errorCode, 'version', $exception->getMessage());
        }

        return null;
    }

    private function manifestText(GitHubRepository $repository, ReleaseInfo $release): ?string
    {
        $base = 'manifests/' . self::key($repository) . '/' . $release->tag;
        $cached = $this->cache->get($base . '.yaml');
        if ($cached !== null) {
            return $cached;
        }
        if ($this->cache->get($base . '.missing') !== null) {
            return null;
        }
        if ($this->offline) {
            throw new InstallException('network_failed', sprintf('Offline: the manifest of %s is not cached.', $release->tag));
        }

        $yaml = $this->source->manifest($repository, $release->release());
        $this->cache->set($base . ($yaml === null ? '.missing' : '.yaml'), $yaml ?? '');

        return $yaml;
    }

    private static function key(GitHubRepository $repository): string
    {
        return strtolower($repository->owner . '/' . $repository->name);
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

    /**
     * @return array{string, list<ReleaseInfo>}|null
     */
    private static function decode(?string $json): ?array
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

        return [$data['fetched_at'], $releases];
    }
}
