<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Build\Generator;
use YiiPress\Catalog\Build\Services;
use YiiPress\Catalog\Fetch\FetchFailedException;
use YiiPress\Catalog\Tests\Support\FakeHttpClient;
use YiiPress\Catalog\Tests\Support\Manifests;
use YiiPress\Catalog\Tests\Support\TempTree;

use function file_get_contents;
use function yaml_parse;

final class GeneratorTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = TempTree::create([
            'categories.yaml' => "theme: [blog, starter]\nprocessor: [markup]\n",
            'extensions/yiipress/theme-sample.yaml' => "repository: https://github.com/yiipress/theme-sample\ncategories: [starter]\nofficial: true\n",
            'extensions/acme/gone.yaml' => "repository: https://github.com/acme/gone\ncategories: [blog]\nstatus: withdrawn\n"
                . "withdrawn: {date: \"2026-10-01\", reason: The author removed it.}\n",
            'site/content/index.md' => "# Catalog\n",
        ]);
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    public function testGeneratesTheSiteContent(): void
    {
        $report = $this->generator(self::http())->generate($this->tree->root);

        self::assertSame(1, $report->listed);
        self::assertSame([], $report->warnings);
        $content = $this->tree->root . '/site/content';
        $data = yaml_parse((string) file_get_contents($content . '/data/catalog.yaml'));
        self::assertIsArray($data);
        self::assertSame(['0.2.0', '0.1.13'], $data['engine_versions']);
        self::assertSame(['yiipress/theme-sample'], $data['types']['theme']['extensions']);
        self::assertSame([['id' => 'acme/gone', 'date' => '2026-10-01', 'reason' => 'The author removed it.']], $data['withdrawn']);
        $sample = $data['extensions']['yiipress/theme-sample'];
        self::assertSame(['/assets/extensions/yiipress/theme-sample/v1.0.0/1-home.png'], $sample['theme']['screenshots']);
        self::assertSame(['0.2.0' => 'v1.0.0', '0.1.13' => null], $sample['latest_compatible']);
        self::assertSame('PNG', file_get_contents($content . '/assets/extensions/yiipress/theme-sample/v1.0.0/1-home.png'));
        self::assertFileExists($content . '/themes/yiipress--theme-sample.md');
        self::assertFileExists($content . '/index.md');
    }

    public function testWarnsAboutEntryProblemsAndSkipsUnlistable(): void
    {
        $http = self::http()->manifest('yiipress/theme-sample', 'v1.0.0', Manifests::theme('other/theme', '1.0.0'));

        $report = $this->generator($http)->generate($this->tree->root);

        self::assertSame(0, $report->listed);
        self::assertStringContainsString('release_missing', $report->warnings[0]);
    }

    public function testUsesCacheWhenGitHubFailsLater(): void
    {
        $this->generator(self::http())->generate($this->tree->root);

        $report = $this->generator(new FakeHttpClient(), '2026-10-30T12:00:00Z')->generate($this->tree->root);

        self::assertSame(1, $report->listed);
        self::assertStringContainsString('cached data from 2026-10-29T12:00:00Z', $report->warnings[0]);
    }

    public function testFailsWhenAnEntryHasNoDataAtAll(): void
    {
        $this->expectException(FetchFailedException::class);

        $this->generator(new FakeHttpClient())->generate($this->tree->root);
    }

    private static function http(): FakeHttpClient
    {
        $extra = "theme:\n  screenshots: [screenshots/home.png]\n";

        return new FakeHttpClient()
            ->releases('yiipress/engine', ['0.2.0' => [], '0.1.13' => [], '0.1.12' => [], 'nightly-1-abc' => ['prerelease' => true]])
            ->releases('yiipress/theme-sample', ['v1.0.0' => []])
            ->manifest('yiipress/theme-sample', 'v1.0.0', Manifests::theme('yiipress/theme-sample', '1.0.0', '>=0.2.0 <1.0', $extra))
            ->on('https://raw.githubusercontent.com/yiipress/theme-sample/v1.0.0/screenshots/home.png', 200, 'PNG');
    }

    private function generator(FakeHttpClient $http, string $now = '2026-10-29T12:00:00Z'): Generator
    {
        return Services::create($http, $this->tree->root . '/cache', $now)->generator();
    }
}
