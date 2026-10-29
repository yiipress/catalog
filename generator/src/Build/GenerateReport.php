<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Build;

final readonly class GenerateReport
{
    /**
     * @param int $listed Extensions with a page.
     * @param list<string> $warnings Problems that did not stop the build.
     */
    public function __construct(
        public int $listed,
        public array $warnings,
    ) {}
}
