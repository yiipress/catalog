<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Catalog;

use YiiPress\Catalog\Entry\CatalogEntry;

/**
 * Every entry of the metadata repository, sorted by ID, with the allowed categories.
 */
final readonly class Catalog
{
    /**
     * @param list<CatalogEntry> $entries
     */
    public function __construct(
        public array $entries,
        public Categories $categories,
    ) {}
}
