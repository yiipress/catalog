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
use YiiPress\Catalog\Entry\InvalidEntryException;
use YiiPress\Catalog\Fetch\FetchFailedException;

use function sprintf;

#[AsCommand(name: 'generate', description: 'Fetch release data and write the generated site content')]
final class GenerateCommand extends Command
{
    /**
     * @param Closure(string, bool): Services $services Cache directory, offline => services.
     */
    public function __construct(private readonly Closure $services)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        Options::addCommon($this);
        $this->addOption('offline', null, InputOption::VALUE_NONE, 'Read only the cache, no network');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = Options::read($input);
        try {
            $report = ($this->services)($options->cache, (bool) $input->getOption('offline'))->generator()->generate($options->root);
        } catch (InvalidEntryException | FetchFailedException $exception) {
            $output->writeln('<error>The catalog was not generated.</error>');
            $output->writeln($exception->getMessage());
            return Command::FAILURE;
        }

        foreach ($report->warnings as $warning) {
            $output->writeln('<comment>Warning:</comment> ' . $warning);
        }
        $output->writeln(sprintf('Listed %d extension%s.', $report->listed, $report->listed === 1 ? '' : 's'));

        return Command::SUCCESS;
    }
}
