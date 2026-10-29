<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use YiiPress\Catalog\Build\Services;
use YiiPress\Catalog\Console\CheckCommand;
use YiiPress\Catalog\Console\GenerateCommand;
use YiiPress\Catalog\Tests\Support\FakeHttpClient;
use YiiPress\Catalog\Tests\Support\Manifests;
use YiiPress\Catalog\Tests\Support\TempTree;

final class CommandsTest extends TestCase
{
    private TempTree $tree;
    private FakeHttpClient $http;
    private ?bool $offline = null;

    protected function setUp(): void
    {
        $this->tree = TempTree::create([
            'categories.yaml' => "theme: [blog]\n",
            'extensions/acme/dusk.yaml' => "repository: https://github.com/acme/theme-dusk\ncategories: [blog]\n",
            'site/content/index.md' => "# Catalog\n",
        ]);
        $this->http = new FakeHttpClient()
            ->releases('yiipress/engine', ['0.2.0' => []])
            ->releases('acme/theme-dusk', ['v1.0.0' => []])
            ->manifest('acme/theme-dusk', 'v1.0.0', Manifests::theme('acme/dusk', '1.0.0'));
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    public function testGenerateWritesContentAndReports(): void
    {
        $tester = new CommandTester(new GenerateCommand($this->services(...)));

        $exit = $tester->execute(['--root' => $this->tree->root, '--cache' => $this->tree->root . '/cache']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Listed 1 extension', $tester->getDisplay());
        self::assertFileExists($this->tree->root . '/site/content/themes/acme--dusk.md');
        self::assertFalse($this->offline);
    }

    public function testGenerateOfflinePassesTheFlag(): void
    {
        $tester = new CommandTester(new GenerateCommand($this->services(...)));

        $tester->execute(['--root' => $this->tree->root, '--cache' => $this->tree->root . '/cache', '--offline' => true]);

        self::assertTrue($this->offline);
    }

    public function testGenerateFailsOnInvalidMetadata(): void
    {
        $this->tree->write('extensions/acme/dusk.yaml', "categories: [blog]\n");
        $tester = new CommandTester(new GenerateCommand($this->services(...)));

        $exit = $tester->execute(['--root' => $this->tree->root, '--cache' => $this->tree->root . '/cache']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('repository: is required', $tester->getDisplay());
    }

    public function testCheckPassesValidCatalog(): void
    {
        $tester = new CommandTester(new CheckCommand($this->services(...)));

        $exit = $tester->execute(['--root' => $this->tree->root, '--cache' => $this->tree->root . '/cache']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('acme/dusk', $tester->getDisplay());
    }

    public function testCheckFailsWithAnnotationsInActions(): void
    {
        $this->tree->write('extensions/acme/dusk.yaml', "categories: [blog]\n");
        $tester = new CommandTester(new CheckCommand($this->services(...), githubActions: true));

        $exit = $tester->execute(['--root' => $this->tree->root, '--cache' => $this->tree->root . '/cache']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('::error::', $tester->getDisplay());
    }

    private function services(string $cache, bool $offline): Services
    {
        $this->offline = $offline;

        return Services::create($this->http, $cache, '2026-10-29T12:00:00Z', $offline);
    }
}
