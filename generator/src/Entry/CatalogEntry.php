<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Entry;

use YiiPress\Extension\ExtensionId;
use YiiPress\Extension\Release\GitHubRepository;

/**
 * Catalog-owned facts about one extension, from `extensions/<vendor>/<name>.yaml`.
 *
 * Everything the author owns (type, name, description, license, versions) comes from the release manifests.
 */
final readonly class CatalogEntry
{
    /**
     * @param list<string> $categories
     * @param string|null $ownershipRecord Link to a maintainer-reviewed ownership record, when the ID vendor is
     *     not the repository owner.
     * @param list<Transfer> $history Newest first.
     */
    public function __construct(
        public ExtensionId $id,
        public GitHubRepository $repository,
        public array $categories,
        public EntryStatus $status = EntryStatus::Listed,
        public bool $official = false,
        public ?string $ownershipRecord = null,
        public array $history = [],
        public ?Withdrawal $withdrawal = null,
    ) {}

    public function file(): string
    {
        return EntryParser::file($this->id);
    }
}
