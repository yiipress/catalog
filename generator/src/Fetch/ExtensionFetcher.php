<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Fetch;

use YiiPress\Catalog\Entry\CatalogEntry;
use YiiPress\Extension\Install\InstallException;
use YiiPress\Extension\Manifest\ExtensionManifest;
use YiiPress\Extension\Manifest\InvalidManifestException;
use YiiPress\Extension\Manifest\ManifestError;
use YiiPress\Extension\Manifest\ManifestParser;
use YiiPress\Extension\Release\GitHubReleaseSource;
use YiiPress\Extension\Release\GitHubRepository;
use YiiPress\Extension\Release\ReleaseSelector;

use function sprintf;
use function strtolower;

/**
 * Reads the newest releases of an entry and their manifests, through the cache.
 *
 * A manifest is read once per tag and kept: the catalog treats a published tag as immutable. Release lists come
 * from {@see ReleaseLists}.
 */
final readonly class ExtensionFetcher
{
    public const int RELEASES = 10;

    public function __construct(
        private ReleaseLists $lists,
        private GitHubReleaseSource $source,
        private FileCache $cache,
    ) {}

    /**
     * @throws FetchFailedException GitHub failed and the cache has no release list for the entry.
     */
    public function fetch(CatalogEntry $entry): FetchedExtension
    {
        try {
            $list = $this->lists->read($entry->repository, self::RELEASES);
        } catch (InstallException $exception) {
            throw new FetchFailedException(sprintf(
                '%s: could not read the releases of %s, and nothing is cached: %s',
                $entry->file(),
                $entry->repository->url(),
                $exception->getMessage(),
            ), 0, $exception);
        }

        $records = [];
        foreach ($list->releases as $release) {
            $records[] = $this->record($entry, $release);
        }

        return new FetchedExtension($entry, $records, $list->fetchedAt, $list->isStale(), $list->staleReason);
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
        if ($this->lists->isOffline()) {
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

}
