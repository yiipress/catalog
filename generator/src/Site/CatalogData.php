<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Site;

use YiiPress\Catalog\Entry\CatalogEntry;
use YiiPress\Catalog\Fetch\ReleaseRecord;
use YiiPress\Catalog\Listing\Listing;
use YiiPress\Extension\ExtensionType;
use YiiPress\Extension\Manifest\Author;
use YiiPress\Extension\Manifest\ExtensionManifest;
use YiiPress\Extension\Manifest\ManifestError;

use function array_map;
use function in_array;
use function sprintf;

/**
 * The site data file `content/data/catalog.yaml`: plain arrays the templates read as `$data['catalog']`.
 */
final readonly class CatalogData
{
    /** Error codes of a failed request, from the engine's HTTP clients and `GitHubReleaseSource`. */
    private const array READ_FAILURES = ['network_failed', 'rate_limited'];

    public const array TYPES = [
        'theme' => ['title' => 'Themes', 'path' => 'themes'],
        'processor' => ['title' => 'Content processors', 'path' => 'processors'],
        'importer' => ['title' => 'Importers', 'path' => 'importers'],
        'plugin' => ['title' => 'Lifecycle plugins', 'path' => 'plugins'],
    ];

    public static function url(ExtensionType $type, string $id): string
    {
        return sprintf('/%s/%s/', self::TYPES[$type->value]['path'], $id);
    }

    /**
     * @param list<Listing> $listings
     * @param list<CatalogEntry> $withdrawn
     * @param list<string> $engineVersions
     * @param array<string, array<string, string>> $assets Extension ID => catalog path => site URL (screenshots).
     * @param array<string, array<string, string>> $texts Extension ID => catalog path => text (examples).
     * @return array<string, mixed>
     */
    public function build(
        array $listings,
        array $withdrawn,
        array $engineVersions,
        string $generatedAt,
        array $assets,
        array $texts = [],
    ): array {
        $types = [];
        foreach (ExtensionType::cases() as $type) {
            $types[$type->value] = self::TYPES[$type->value] + [
                'installable' => $type->isSupported(),
                'extensions' => [],
            ];
        }

        $extensions = [];
        foreach ($listings as $listing) {
            if ($listing->current === null || $listing->type === null) {
                continue;
            }
            $id = (string) $listing->fetched->entry->id;
            $extensions[$id] = $this->extension($listing, $listing->current, $assets[$id] ?? [], $texts[$id] ?? []);
            $types[$listing->type->value]['extensions'][] = $id;
        }

        return [
            'generated_at' => $generatedAt,
            'engine_versions' => $engineVersions,
            'types' => $types,
            'extensions' => $extensions,
            'withdrawn' => array_map(static fn(CatalogEntry $entry): array => [
                'id' => (string) $entry->id,
                'date' => $entry->withdrawal?->date,
                'reason' => $entry->withdrawal?->reason,
            ], $withdrawn),
        ];
    }

    /**
     * @param array<string, string> $assets
     * @param array<string, string> $texts
     * @return array<string, mixed>
     */
    private function extension(Listing $listing, ExtensionManifest $manifest, array $assets, array $texts): array
    {
        $fetched = $listing->fetched;
        $entry = $fetched->entry;
        $current = null;
        foreach ($fetched->releases as $release) {
            if ($release->manifest === $manifest) {
                $current = $release;
                break;
            }
        }

        return [
            'id' => (string) $entry->id,
            'type' => $manifest->type->value,
            'name' => $manifest->name,
            'description' => $manifest->description,
            'license' => $manifest->license,
            'authors' => array_map(static fn(Author $author): array => ['name' => $author->name, 'url' => $author->url], $manifest->authors),
            'homepage' => $manifest->homepage,
            'repository' => $entry->repository->url(),
            'categories' => $entry->categories,
            'official' => $entry->official,
            'status' => $entry->status->value,
            'url' => self::url($manifest->type, (string) $entry->id),
            'install' => $manifest->type->isSupported()
                ? sprintf('yiipress get:%s --url=%s', $manifest->type->value, $entry->repository->url())
                : null,
            'release' => $current === null ? null : [
                'tag' => $current->info->tag,
                'version' => $current->info->version,
                'published_at' => $current->info->publishedAt,
                'url' => $current->info->url,
                'yiipress' => $manifest->yiipress,
            ],
            'latest_compatible' => $listing->latestCompatible,
            'platforms' => ['os' => $manifest->platforms->os, 'arch' => $manifest->platforms->arch],
            'theme' => $this->theme($manifest, $assets),
            'processor' => $this->processor($manifest, $texts),
            'releases' => array_map(fn(ReleaseRecord $release): array => [
                'tag' => $release->info->tag,
                'version' => $release->info->version,
                'published_at' => $release->info->publishedAt,
                'url' => $release->info->url,
                'yiipress' => $release->manifest?->yiipress,
                'installable' => $release->manifest !== null,
                'compatible' => $listing->compatibility[$release->info->tag] ?? [],
                'problems' => array_map(static fn(ManifestError $problem): string => $problem->message, $release->problems),
                'unread' => self::unread($release),
            ], $fetched->releases),
            'history' => array_map(static fn($transfer): array => [
                'date' => $transfer->date,
                'from' => $transfer->from->url(),
                'record' => $transfer->record,
            ], $entry->history),
            'fetched_at' => $fetched->fetchedAt,
            'stale' => $fetched->stale,
        ];
    }

    /**
     * Whether the manifest of the release could not be read at this build (GitHub failed), as opposed to being
     * missing or invalid. Such a release is shown as not read yet, not as not installable.
     */
    private static function unread(ReleaseRecord $release): bool
    {
        foreach ($release->problems as $problem) {
            if (in_array($problem->code, self::READ_FAILURES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $assets
     * @return array<string, mixed>|null
     */
    private function theme(ExtensionManifest $manifest, array $assets): ?array
    {
        $theme = $manifest->theme();
        if ($theme === null) {
            return null;
        }
        $screenshots = [];
        foreach ($theme->screenshots as $path) {
            if (isset($assets[$path])) {
                $screenshots[] = $assets[$path];
            }
        }

        return [
            'fallback' => $theme->fallback->value,
            'intended_use' => $theme->intendedUse,
            'screenshots' => $screenshots,
            'demo' => $theme->demo,
        ];
    }

    /**
     * @param array<string, string> $texts
     * @return array<string, mixed>|null
     */
    private function processor(ExtensionManifest $manifest, array $texts): ?array
    {
        $processor = $manifest->processor();
        if ($processor === null) {
            return null;
        }
        $examples = [];
        foreach ($processor->examples as $example) {
            if (isset($texts[$example->input], $texts[$example->output])) {
                $examples[] = ['input' => $texts[$example->input], 'output' => $texts[$example->output]];
            }
        }
        $entryPoints = [];
        foreach ($processor->entryPoints as $entryPoint) {
            $entryPoints[] = [
                'path' => $entryPoint->path,
                'stages' => array_map(static fn($stage): string => $stage->value, $entryPoint->stages),
            ];
        }

        return ['entry_points' => $entryPoints, 'examples' => $examples];
    }
}
