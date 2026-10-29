<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Entry;

final readonly class Withdrawal
{
    /**
     * @param string $date `YYYY-MM-DD`.
     */
    public function __construct(
        public string $date,
        public string $reason,
    ) {}
}
