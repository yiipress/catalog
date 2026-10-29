<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Listing;

use YiiPress\Catalog\Fetch\FetchedExtension;
use YiiPress\Extension\ExtensionType;
use YiiPress\Extension\Manifest\ExtensionManifest;
use YiiPress\Extension\Manifest\ManifestError;

/**
 * Everything a catalog page shows about one extension.
 */
final readonly class Listing
{
    /**
     * @param ExtensionType|null $type From the newest valid release; `null` when no release is valid.
     * @param ExtensionManifest|null $current Manifest of the newest valid release; the page describes it.
     * @param list<string> $engineVersions YiiPress versions of the table, newest first.
     * @param array<string, array<string, bool>> $compatibility Release tag => YiiPress version => compatible.
     * @param array<string, string|null> $latestCompatible YiiPress version => newest compatible release tag.
     * @param list<ManifestError> $problems Problems of the entry as a whole; a pull request check fails on them.
     */
    public function __construct(
        public FetchedExtension $fetched,
        public ?ExtensionType $type,
        public ?ExtensionManifest $current,
        public array $engineVersions,
        public array $compatibility,
        public array $latestCompatible,
        public array $problems,
    ) {}
}
