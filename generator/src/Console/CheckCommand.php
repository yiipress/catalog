<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Console;

use Closure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use YiiPress\Catalog\Build\Services;

use function implode;
use function count;
use function is_string;
use function sprintf;
use function str_replace;

#[AsCommand(name: 'check', description: 'Check the metadata and the releases of new or changed entries')]
final class CheckCommand extends Command
{
    /**
     * @param Closure(string, bool): Services $services Cache directory, offline => services.
     * @param bool $githubActions Print errors as workflow annotations.
     */
    public function __construct(
        private readonly Closure $services,
        private readonly bool $githubActions = false,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        Options::addCommon($this);
        $this
            ->addOption('base', null, InputOption::VALUE_REQUIRED, 'Checkout of the base branch, for the change rules')
            ->addOption('author', null, InputOption::VALUE_REQUIRED, 'GitHub login of the pull request author');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = Options::read($input);
        $base = $input->getOption('base');
        $author = $input->getOption('author');
        $report = ($this->services)($options->cache, false)->checker()->check(
            $options->root,
            is_string($base) && $base !== '' ? $base : null,
            is_string($author) && $author !== '' ? $author : null,
        );

        $output->writeln($report->checked === []
            ? 'No new or changed entries to read from GitHub.'
            : 'Read from GitHub: ' . implode(', ', $report->checked) . '.');
        foreach ($report->errors as $error) {
            // A workflow annotation is one line; GitHub decodes %0A back into line breaks.
            $output->writeln($this->githubActions ? '::error::' . str_replace("\n", '%0A', $error) : '<error>Error:</error> ' . $error);
        }
        if ($report->errors !== []) {
            $output->writeln(sprintf('%d problem%s found.', count($report->errors), count($report->errors) === 1 ? '' : 's'));
            return Command::FAILURE;
        }
        $output->writeln('The catalog is valid.');

        return Command::SUCCESS;
    }
}
