<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Build;

final readonly class CheckReport
{
    /**
     * @param list<string> $errors A pull request with errors is not merged.
     * @param list<string> $checked IDs whose releases were read from GitHub.
     */
    public function __construct(
        public array $errors,
        public array $checked,
    ) {}
}
