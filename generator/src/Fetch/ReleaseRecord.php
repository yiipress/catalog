<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Fetch;

use YiiPress\Extension\Manifest\ExtensionManifest;
use YiiPress\Extension\Manifest\ManifestError;

/**
 * One release with its manifest, or the problems that make it not installable.
 */
final readonly class ReleaseRecord
{
    /**
     * @param ExtensionManifest|null $manifest `null` when the release has no valid manifest for this entry.
     * @param list<ManifestError> $problems
     */
    public function __construct(
        public ReleaseInfo $info,
        public ?ExtensionManifest $manifest,
        public array $problems = [],
    ) {}
}
