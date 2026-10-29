<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Entry;

use YiiPress\Extension\Release\GitHubRepository;

/**
 * A reviewed change of the source repository; the extension ID stays the same.
 */
final readonly class Transfer
{
    /**
     * @param string $date `YYYY-MM-DD`.
     * @param GitHubRepository $from The repository used before this change.
     * @param string $record Link to the reviewed pull request or issue.
     */
    public function __construct(
        public string $date,
        public GitHubRepository $from,
        public string $record,
    ) {}
}
