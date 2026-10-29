<?php

declare(strict_types=1);

namespace YiiPress\Catalog\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use YiiPress\Catalog\Build\Services;
use YiiPress\Catalog\Tests\Support\FakeHttpClient;
use YiiPress\Catalog\Tests\Support\TempTree;

use function dirname;
use function file_get_contents;
use function yaml_parse;

/**
 * The catalog's own entry against the files of yiipress/theme-sample v1.0.0 (copied into tests/Fixtures), so the
 * first real listing is checked before the release exists.
 */
final class SampleThemeTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../Fixtures/theme-sample';
    private const string RAW = 'https://raw.githubusercontent.com/yiipress/theme-sample/v1.0.0/';

    private TempTree $tree;

    protected function setUp(): void
    {
        $catalog = dirname(__DIR__, 4);
        $this->tree = TempTree::create([
            'categories.yaml' => (string) file_get_contents($catalog . '/categories.yaml'),
            'extensions/yiipress/theme-sample.yaml' => (string) file_get_contents(
                $catalog . '/extensions/yiipress/theme-sample.yaml',
            ),
            '.github/CODEOWNERS' => (string) file_get_contents($catalog . '/.github/CODEOWNERS'),
            'site/content/index.md' => "# Catalog\n",
        ]);
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    public function testListsTheSampleThemeFromItsReleaseFiles(): void
    {
        $screenshot = (string) file_get_contents(self::FIXTURE . '/screenshots/home.png');
        $http = new FakeHttpClient()
            ->releases('yiipress/engine', ['0.2.0' => [], '0.1.13' => []])
            ->releases('yiipress/theme-sample', ['v1.0.0' => []])
            ->manifest('yiipress/theme-sample', 'v1.0.0', self::manifest())
            ->on(self::RAW . 'screenshots/home.png', 200, $screenshot);

        $report = $this->services($http)->generator()->generate($this->tree->root);

        self::assertSame(1, $report->listed);
        self::assertSame([], $report->warnings);
        $content = $this->tree->root . '/site/content';
        $data = yaml_parse((string) file_get_contents($content . '/data/catalog.yaml'));
        self::assertIsArray($data);
        $sample = $data['extensions']['yiipress/theme-sample'];
        self::assertSame('theme', $sample['type']);
        self::assertSame('Sample theme', $sample['name']);
        self::assertTrue($sample['official']);
        self::assertSame('BSD-3-Clause', $sample['license']);
        self::assertSame('minimal', $sample['theme']['fallback']);
        self::assertSame('yiipress get:theme --url=https://github.com/yiipress/theme-sample', $sample['install']);
        self::assertSame(['0.2.0' => 'v1.0.0', '0.1.13' => null], $sample['latest_compatible']);
        $copied = $sample['theme']['screenshots'][0];
        self::assertSame('/assets/extensions/yiipress/theme-sample/v1.0.0/1-home.png', $copied);
        self::assertSame($screenshot, file_get_contents($content . $copied));
    }

    public function testThePullRequestCheckAcceptsTheSampleTheme(): void
    {
        $http = new FakeHttpClient()
            ->releases('yiipress/theme-sample', ['v1.0.0' => []])
            ->manifest('yiipress/theme-sample', 'v1.0.0', self::manifest());

        $report = $this->services($http)->checker()->check($this->tree->root, null, null);

        self::assertSame([], $report->errors);
        self::assertSame(['yiipress/theme-sample'], $report->checked);
    }

    private function services(FakeHttpClient $http): Services
    {
        return Services::create($http, $this->tree->root . '/cache', '2026-10-29T12:00:00Z');
    }

    private static function manifest(): string
    {
        return (string) file_get_contents(self::FIXTURE . '/extension.yaml');
    }
}
