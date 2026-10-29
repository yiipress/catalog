<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Listing;

use YiiPress\Catalog\Catalog\Categories;
use YiiPress\Catalog\Fetch\FetchedExtension;
use YiiPress\Extension\EngineCompatibility;
use YiiPress\Extension\EngineVersion;
use YiiPress\Extension\Manifest\ExtensionManifest;
use YiiPress\Extension\Manifest\ManifestError;
use YiiPress\Extension\Platform;

use function implode;
use function in_array;
use function sprintf;

/**
 * Turns fetched releases into a listing: type, current details, the compatibility table and entry problems.
 */
final readonly class ListingBuilder
{
    /**
     * @param list<string> $engineVersions YiiPress versions to check against, newest first.
     */
    public function __construct(
        private Categories $categories,
        private array $engineVersions,
        private EngineCompatibility $compatibility = new EngineCompatibility(),
    ) {}

    public function build(FetchedExtension $fetched): Listing
    {
        $current = null;
        foreach ($fetched->releases as $release) {
            if ($release->manifest !== null) {
                $current = $release->manifest;
                break;
            }
        }

        $table = [];
        $latest = [];
        foreach ($this->engineVersions as $version) {
            $latest[$version] = null;
        }
        foreach ($fetched->releases as $release) {
            foreach ($this->engineVersions as $version) {
                $compatible = $release->manifest !== null && $this->compatible($release->manifest, $version);
                $table[$release->info->tag][$version] = $compatible;
                if ($compatible && $latest[$version] === null) {
                    $latest[$version] = $release->info->tag;
                }
            }
        }

        return new Listing(
            $fetched,
            $current?->type,
            $current,
            $this->engineVersions,
            $table,
            $latest,
            $this->problems($fetched, $current),
        );
    }

    /**
     * @return list<ManifestError>
     */
    private function problems(FetchedExtension $fetched, ?ExtensionManifest $current): array
    {
        $entry = $fetched->entry;
        if ($current === null) {
            return [new ManifestError('release_missing', '', sprintf(
                '%s: none of the newest releases of %s has a valid extension.yaml for %s.',
                $entry->file(),
                $entry->repository->url(),
                $entry->id,
            ))];
        }

        $problems = [];
        foreach ($fetched->releases as $release) {
            if ($release->manifest !== null && $release->manifest->type !== $current->type) {
                $problems[] = new ManifestError('type_changed', '', sprintf(
                    '%s: release %s is a %s, but the newest release is a %s; the type of an extension never changes.',
                    $entry->file(),
                    $release->info->tag,
                    $release->manifest->type->value,
                    $current->type->value,
                ));
                break;
            }
        }
        $allowed = $this->categories->for($current->type);
        foreach ($entry->categories as $index => $category) {
            if (!in_array($category, $allowed, true)) {
                $problems[] = new ManifestError('category_type', 'categories[' . $index . ']', sprintf(
                    '%s: categories[%d]: "%s" is not a %s category; use one of: %s.',
                    $entry->file(),
                    $index,
                    $category,
                    $current->type->value,
                    implode(', ', $allowed),
                ));
            }
        }

        return $problems;
    }

    private function compatible(ExtensionManifest $manifest, string $version): bool
    {
        // Only the YiiPress range counts here; supported platforms are shown separately.
        foreach ($this->compatibility->check($manifest, EngineVersion::fromBuild($version), new Platform(null, null)) as $error) {
            if ($error->field === 'yiipress') {
                return false;
            }
        }

        return true;
    }
}
