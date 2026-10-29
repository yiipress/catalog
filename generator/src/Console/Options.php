<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use function dirname;
use function is_string;

/**
 * Options both commands take: where the catalog checkout and the cache are.
 */
final readonly class Options
{
    private function __construct(
        public string $root,
        public string $cache,
    ) {}

    public static function addCommon(Command $command): void
    {
        $command
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Catalog checkout', dirname(__DIR__, 3))
            ->addOption('cache', null, InputOption::VALUE_REQUIRED, 'Cache directory', dirname(__DIR__, 2) . '/var/cache');
    }

    public static function read(InputInterface $input): self
    {
        $root = $input->getOption('root');
        $cache = $input->getOption('cache');

        return new self(is_string($root) ? $root : dirname(__DIR__, 3), is_string($cache) ? $cache : dirname(__DIR__, 2) . '/var/cache');
    }
}
