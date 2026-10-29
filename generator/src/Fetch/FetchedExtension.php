<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Fetch;

use YiiPress\Catalog\Entry\CatalogEntry;

use function array_any;

/**
 * What the catalog knows about one entry after fetching: its newest releases and when they were read.
 */
final readonly class FetchedExtension
{
    /**
     * @param list<ReleaseRecord> $releases Newest version first.
     * @param string $fetchedAt When the release list was read from GitHub (ISO 8601).
     * @param bool $stale The release list comes from the cache because GitHub could not be read now.
     */
    public function __construct(
        public CatalogEntry $entry,
        public array $releases,
        public string $fetchedAt,
        public bool $stale = false,
        public ?string $staleReason = null,
    ) {}

    public function hasInstallableRelease(): bool
    {
        return array_any($this->releases, static fn(ReleaseRecord $release): bool => $release->manifest !== null);
    }
}
