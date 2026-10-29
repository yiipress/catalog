<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Fetch;

use YiiPress\Extension\Release\Release;

/**
 * A stable GitHub release, as the catalog shows it.
 */
final readonly class ReleaseInfo
{
    /**
     * @param string $publishedAt ISO 8601 time from GitHub; empty when GitHub gave none.
     */
    public function __construct(
        public string $tag,
        public string $version,
        public string $publishedAt,
        public string $url,
    ) {}

    public function release(): Release
    {
        return new Release($this->tag, $this->version);
    }
}
