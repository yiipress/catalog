<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Fetch;

/**
 * Stable releases of a repository, newest first, and when they were read.
 */
final readonly class ReleaseList
{
    /**
     * @param list<ReleaseInfo> $releases
     * @param string $fetchedAt ISO 8601.
     * @param string|null $staleReason Why GitHub could not be read now, when the list comes from the cache.
     */
    public function __construct(
        public array $releases,
        public string $fetchedAt,
        public ?string $staleReason = null,
    ) {}

    public function isStale(): bool
    {
        return $this->staleReason !== null;
    }
}
