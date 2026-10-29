<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Build;

use YiiPress\Catalog\Catalog\CatalogLoader;
use YiiPress\Catalog\Entry\EntryStatus;
use YiiPress\Catalog\Entry\InvalidEntryException;
use YiiPress\Catalog\Fetch\ExtensionFetcher;
use YiiPress\Catalog\Fetch\FetchFailedException;
use YiiPress\Catalog\Fetch\ReleaseLists;
use YiiPress\Catalog\Listing\EngineVersions;
use YiiPress\Catalog\Listing\Listing;
use YiiPress\Catalog\Listing\ListingBuilder;
use YiiPress\Catalog\Site\AssetFetcher;
use YiiPress\Catalog\Site\CatalogData;
use YiiPress\Catalog\Site\ContentWriter;
use YiiPress\Extension\Install\InstallException;
use YiiPress\Extension\Release\GitHubRepository;

use function array_push;
use function basename;
use function preg_replace;
use function sprintf;

/**
 * Builds the generated part of `site/content/` from the metadata and GitHub (through the cache).
 */
final readonly class Generator
{
    public const string ENGINE_REPOSITORY = 'https://github.com/yiipress/engine';
    public const string CONTENT_DIRECTORY = 'site/content';

    public function __construct(
        private CatalogLoader $loader,
        private ReleaseLists $lists,
        private ExtensionFetcher $fetcher,
        private AssetFetcher $assets,
    ) {}

    /**
     * @param string $root Catalog checkout: `categories.yaml`, `extensions/`, `site/`.
     *
     * @throws InvalidEntryException The metadata is invalid.
     * @throws FetchFailedException GitHub failed for an entry, or for the YiiPress releases, and nothing is cached.
     */
    public function generate(string $root): GenerateReport
    {
        $catalog = $this->loader->load($root);
        $engineVersions = EngineVersions::pick($this->engineReleases());
        $builder = new ListingBuilder($catalog->categories, $engineVersions);

        $warnings = [];
        $listings = [];
        $withdrawn = [];
        $files = [];
        $images = [];
        $texts = [];
        foreach ($catalog->entries as $entry) {
            if ($entry->status === EntryStatus::Withdrawn) {
                $withdrawn[] = $entry;
                continue;
            }
            $fetched = $this->fetcher->fetch($entry);
            if ($fetched->stale) {
                $warnings[] = sprintf(
                    '%s: GitHub could not be read (%s); using cached data from %s.',
                    $entry->file(),
                    $fetched->staleReason,
                    $fetched->fetchedAt,
                );
            }
            $listing = $builder->build($fetched);
            foreach ($listing->problems as $problem) {
                $warnings[] = sprintf('[%s] %s', $problem->code, $problem->message);
            }
            $listings[] = $listing;
            $this->collectAssets($listing, $files, $images, $texts);
        }
        array_push($warnings, ...$this->assets->warnings());

        $data = new CatalogData()->build($listings, $withdrawn, $engineVersions, $this->now(), $images, $texts);
        new ContentWriter($root . '/' . self::CONTENT_DIRECTORY)->write($data, $files);
        $listed = 0;
        foreach ($listings as $listing) {
            $listed += $listing->current === null ? 0 : 1;
        }

        return new GenerateReport($listed, $warnings);
    }

    /**
     * @return list<\YiiPress\Catalog\Fetch\ReleaseInfo>
     */
    private function engineReleases(): array
    {
        try {
            return $this->lists->read(GitHubRepository::fromUrl(self::ENGINE_REPOSITORY))->releases;
        } catch (InstallException $exception) {
            throw new FetchFailedException(sprintf(
                'Could not read the YiiPress releases for the compatibility table, and nothing is cached: %s',
                $exception->getMessage(),
            ), 0, $exception);
        }
    }

    /**
     * @param array<string, string> $files
     * @param array<string, array<string, string>> $images
     * @param array<string, array<string, string>> $texts
     */
    private function collectAssets(Listing $listing, array &$files, array &$images, array &$texts): void
    {
        $manifest = $listing->current;
        if ($manifest === null) {
            return;
        }
        $entry = $listing->fetched->entry;
        $id = (string) $entry->id;
        $tag = null;
        foreach ($listing->fetched->releases as $release) {
            if ($release->manifest === $manifest) {
                $tag = $release->info->tag;
                break;
            }
        }
        if ($tag === null) {
            return;
        }

        foreach ($manifest->theme()->screenshots ?? [] as $index => $path) {
            $bytes = $this->assets->fetch($entry->repository, $tag, $path);
            if ($bytes === null) {
                continue;
            }
            $file = sprintf(
                '%s/%s/%s/%d-%s',
                ContentWriter::ASSETS,
                $id,
                $tag,
                $index + 1,
                preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($path)),
            );
            $files[$file] = $bytes;
            $images[$id][$path] = '/' . $file;
        }
        foreach ($manifest->processor()->examples ?? [] as $example) {
            foreach ([$example->input, $example->output] as $path) {
                $text = $this->assets->fetch($entry->repository, $tag, $path);
                if ($text !== null) {
                    $texts[$id][$path] = $text;
                }
            }
        }
    }

    private function now(): string
    {
        return $this->lists->now();
    }
}
